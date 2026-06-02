<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;

/**
 * Odeslání děkovného e-mailu za úhradu faktury (issue #57).
 *
 * Volá se z MarkPaidAction (ruční/bulk) i z bankovního párování. Sama si ověří
 * podmínky (paid, ne-cancellation, příjemce, per-supplier zapnutí) a je
 * idempotentní (auto odeslání jen pokud `payment_thanks_sent_at IS NULL`).
 * NIKDY nevyhazuje výjimku ven — selhání odeslání nesmí rozbít označení/párování;
 * vrací strukturovaný výsledek a vše loguje do activity logu.
 */
final class PaymentThanksMailer
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $repo,
        private readonly Mailer $mailer,
        private readonly InvoiceEmailVarsBuilder $varsBuilder,
        private readonly InvoicePdfRenderer $renderer,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param 'manual'|'bulk'|'bank_match' $trigger
     * @return array{status:'sent'|'skipped'|'failed', reason?:string, recipients?:list<string>}
     */
    public function sendForInvoice(
        int $invoiceId,
        string $trigger,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        bool $requireUnsent = false,
    ): array {
        $invoice = $this->repo->find($invoiceId);
        if (!$invoice) {
            return ['status' => 'skipped', 'reason' => 'not_found'];
        }
        if (($invoice['invoice_type'] ?? '') === 'cancellation') {
            return ['status' => 'skipped', 'reason' => 'cancellation'];
        }
        if (($invoice['status'] ?? '') !== 'paid') {
            return ['status' => 'skipped', 'reason' => 'not_paid'];
        }

        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        $settings = $this->supplierSettings($supplierId);
        if (!$settings['enabled']) {
            return ['status' => 'skipped', 'reason' => 'disabled'];
        }
        if ($trigger === 'bank_match' && !$settings['auto_send']) {
            return ['status' => 'skipped', 'reason' => 'auto_disabled'];
        }

        // Idempotence — auto odeslání jen jednou (ruční „odeslat znovu" by byla
        // samostatná vědomá akce; mark-paid stejně nejde na už zaplacené faktuře).
        if ($requireUnsent && !empty($this->sentAt($invoiceId))) {
            return $this->logSkip($invoiceId, $invoice, 'already_sent', $trigger, $userId, $ip, $userAgent);
        }

        $to = $this->resolveRecipients($invoice);
        if (empty($to)) {
            return $this->logSkip($invoiceId, $invoice, 'no_recipient', $trigger, $userId, $ip, $userAgent);
        }

        $locale = (string) ($invoice['language'] ?? 'cs');
        $vars = $this->buildVars($invoice, $locale);

        $attachments = [];
        if ($settings['attach_paid_pdf']) {
            try {
                $pdfPath = $this->renderer->render($invoiceId, false, $userId);
                $attachments[] = ['path' => $pdfPath, 'name' => basename($pdfPath), 'contentType' => 'application/pdf'];
            } catch (\Throwable $e) {
                // PDF příloha je volitelná — když selže, pošli e-mail bez ní.
                $attachments = [];
            }
        }

        try {
            $smtp = $this->mailer->sendTemplate('invoice_payment_thanks', $locale, $to, $vars, null, [], [], $attachments, $userId);
        } catch (\Throwable $e) {
            $this->logger->log('invoice.payment_thanks_failed', $userId, 'invoice', $invoiceId, [
                'varsymbol' => $invoice['varsymbol'] ?? null,
                'paid_at'   => $invoice['paid_at'] ?? null,
                'recipients'=> $to,
                'reason'    => $e->getMessage(),
                'trigger'   => $trigger,
            ], $ip, $userAgent);
            return ['status' => 'failed', 'reason' => $e->getMessage(), 'recipients' => $to];
        }

        $sentTo = mb_substr(implode(', ', $to), 0, 512);
        $this->db->pdo()
            ->prepare('UPDATE invoices SET payment_thanks_sent_at = NOW(), payment_thanks_sent_to = ? WHERE id = ?')
            ->execute([$sentTo, $invoiceId]);

        $this->logger->log('invoice.payment_thanks_sent', $userId, 'invoice', $invoiceId, [
            'varsymbol'     => $invoice['varsymbol'] ?? null,
            'paid_at'       => $invoice['paid_at'] ?? null,
            'recipients'    => $to,
            'trigger'       => $trigger,
            'smtp_response' => $smtp,
        ], $ip, $userAgent);

        return ['status' => 'sent', 'recipients' => $to];
    }

    /** @return array{enabled:bool, auto_send:bool, attach_paid_pdf:bool} */
    private function supplierSettings(int $supplierId): array
    {
        if ($supplierId <= 0) {
            return ['enabled' => false, 'auto_send' => false, 'attach_paid_pdf' => false];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT payment_thanks_enabled, payment_thanks_auto_send, payment_thanks_attach_paid_pdf
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'enabled'         => (bool) ($row['payment_thanks_enabled'] ?? false),
            'auto_send'       => (bool) ($row['payment_thanks_auto_send'] ?? false),
            'attach_paid_pdf' => (bool) ($row['payment_thanks_attach_paid_pdf'] ?? false),
        ];
    }

    private function sentAt(int $invoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT payment_thanks_sent_at FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : ($v ?: null);
    }

    /** @return array<string,mixed> */
    private function buildVars(array $invoice, string $locale): array
    {
        // Reuse stávajícího builderu pro `supplier` (patička + branding) a `invoice`.
        $vars = $this->varsBuilder->build($invoice, false, $locale);

        $varsymbol = (string) ($invoice['varsymbol'] ?? '');
        $supName = '';
        if (is_array($vars['supplier'] ?? null)) {
            $supName = (string) ($vars['supplier']['display_name'] ?: ($vars['supplier']['company_name'] ?? ''));
        }
        $subject = $locale === 'en'
            ? "Thank you for your payment — invoice {$varsymbol}"
            : "Děkujeme za úhradu faktury {$varsymbol}";
        if ($supName !== '') {
            $subject .= " — {$supName}";
        }

        $vars['subject']        = $subject;
        $vars['paid_at']        = (string) ($invoice['paid_at'] ?? date('Y-m-d'));
        $vars['amount_paid']    = (float) ($invoice['amount_to_pay'] ?? $invoice['total_with_vat'] ?? 0);
        $vars['currency']       = (string) ($invoice['currency'] ?? 'CZK');
        $vars['payment_method'] = (string) ($invoice['payment_method'] ?? 'bank_transfer');
        $vars['is_proforma']    = ($invoice['invoice_type'] ?? '') === 'proforma';
        $vars['is_test']        = false;
        return $vars;
    }

    /** Stejná logika jako SendEmailAction::resolveRecipients (klient + fakturační e-maily zakázky). */
    private function resolveRecipients(array $invoice): array
    {
        $emails = [];
        if (!empty($invoice['client_main_email'])) {
            $emails[] = (string) $invoice['client_main_email'];
        }
        if (!empty($invoice['project_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT email FROM project_billing_emails WHERE project_id = ? ORDER BY position'
            );
            $stmt->execute([$invoice['project_id']]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $em) {
                $em = trim((string) $em);
                if ($em !== '' && !in_array($em, $emails, true)) {
                    $emails[] = $em;
                }
            }
        }
        return array_values(array_filter($emails, static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    /** @return array{status:'skipped', reason:string} */
    private function logSkip(int $invoiceId, array $invoice, string $reason, string $trigger, ?int $userId, ?string $ip, ?string $ua): array
    {
        $this->logger->log('invoice.payment_thanks_skipped', $userId, 'invoice', $invoiceId, [
            'varsymbol' => $invoice['varsymbol'] ?? null,
            'paid_at'   => $invoice['paid_at'] ?? null,
            'reason'    => $reason,
            'trigger'   => $trigger,
        ], $ip, $ua);
        return ['status' => 'skipped', 'reason' => $reason];
    }
}

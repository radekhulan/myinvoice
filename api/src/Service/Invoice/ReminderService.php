<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;

/**
 * Sdílená logika pro odeslání upomínky — používá:
 *   - SendReminderAction (HTTP single)
 *   - BulkSendRemindersAction (HTTP bulk)
 *   - bin/cron-send-reminders.php (CLI)
 *
 * Validace: faktura musí být ve stavu 'issued'/'sent'/'reminded' a po splatnosti.
 * Po úspěchu: status = 'reminded', last_reminder_at = NOW(), reminder_count++.
 */
final class ReminderService
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly InvoicePdfRenderer $renderer,
        private readonly Mailer $mailer,
        private readonly InvoiceEmailVarsBuilder $varsBuilder,
        private readonly ActivityLogger $logger,
        private readonly Config $config,
    ) {}

    /**
     * @return array{sent_to: string[], days_overdue: int}
     * @throws \RuntimeException při chybě (recipient/PDF/SMTP/...)
     * @throws \DomainException když faktura nesplňuje podmínky pro upomínku
     */
    public function send(int $invoiceId, ?int $userId = null, ?string $ip = null, ?string $userAgent = null): array
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) {
            throw new \DomainException('Faktura nenalezena.');
        }

        if (!in_array($invoice['status'], ['issued', 'sent', 'reminded'], true)) {
            throw new \DomainException('Upomínku lze poslat jen u nezaplacené vystavené/odeslané faktury.');
        }
        if (!in_array($invoice['invoice_type'], ['invoice', 'proforma'], true)) {
            throw new \DomainException('Upomínat lze jen běžnou fakturu nebo proformu (ne dobropis/storno).');
        }
        if (!InvoiceAmountPolicy::hasPositiveAmountToPay($invoice)) {
            throw new \DomainException(InvoiceAmountPolicy::NON_POSITIVE_REMINDER_MESSAGE);
        }
        // Card/cash/other = úhrada mimo bankovní převod. QR ani bankovní spojení se na
        // PDF/emailu nepoužívají; upomínka „zaplaťte převodem" by klienta jen mátla.
        // Cron filtruje už v SQL; tahle pojistka chrání i bulk/manual cestu.
        $paymentMethod = (string) ($invoice['payment_method'] ?? 'bank_transfer');
        if ($paymentMethod !== 'bank_transfer') {
            throw new \DomainException('Upomínat lze jen faktury s platbou bankovním převodem.');
        }

        $today = new \DateTimeImmutable('today');
        $due   = new \DateTimeImmutable((string) $invoice['due_date']);
        if ($due >= $today) {
            throw new \DomainException('Faktura ještě není po splatnosti.');
        }
        $daysOverdue = (int) $today->diff($due)->days;

        $to = $this->resolveRecipients($invoice);
        if (empty($to)) {
            throw new \DomainException('Klient nemá vyplněný email.');
        }

        $cc = [];
        if ((bool) $this->config->get('smtp.cc_supplier_on_reminder', false)) {
            $stmt = $this->db->pdo()->prepare('SELECT email FROM supplier WHERE id = ?');
            $stmt->execute([(int) $invoice['supplier_id']]);
            $supplierEmail = trim((string) $stmt->fetchColumn());
            if ($supplierEmail !== ''
                && filter_var($supplierEmail, FILTER_VALIDATE_EMAIL)
                && !in_array($supplierEmail, $to, true)
            ) {
                $cc[] = $supplierEmail;
            }
        }

        $locale = (string) ($invoice['language'] ?? 'cs');

        // Reálné selhání (PDF/SMTP) zalogujeme jako `invoice.reminder_failed`, ať je
        // v přehledu odeslaných e-mailů vidět „nebylo odesláno". Validační DomainException
        // výše se sem nedostanou (házejí dřív). Po zalogování chybu propustíme dál —
        // caller (manual/bulk/cron) si ji ošetří jako dosud.
        try {
            $pdfPath = $this->renderer->render($invoiceId, false, $userId);
            $vars = $this->varsBuilder->buildReminder($invoice, $daysOverdue, $locale);
            $templateCode = $invoice['invoice_type'] === 'proforma' ? 'proforma_reminder' : 'invoice_reminder';
            $this->mailer->sendTemplate(
                $templateCode,
                $locale,
                $to,
                $vars,
                null,
                $cc,
                [],
                [['path' => $pdfPath, 'name' => basename($pdfPath), 'contentType' => 'application/pdf']],
                $userId,
            );
        } catch (\Throwable $e) {
            $this->logger->log('invoice.reminder_failed', $userId, 'invoice', $invoiceId, [
                'to'           => $to,
                'cc'           => $cc,
                'days_overdue' => $daysOverdue,
                'error'        => mb_substr($e->getMessage(), 0, 500),
            ], $ip, $userAgent);
            throw $e;
        }

        // Status → 'reminded' (z 'paid' nepřechází, protože jsme to vyloučili výše)
        $this->db->pdo()->prepare(
            "UPDATE invoices
                SET status = 'reminded',
                    last_reminder_at = NOW(),
                    reminder_count = reminder_count + 1
              WHERE id = ?"
        )->execute([$invoiceId]);

        $this->logger->log('invoice.reminder_sent', $userId, 'invoice', $invoiceId, [
            'to'           => $to,
            'cc'           => $cc,
            'days_overdue' => $daysOverdue,
            'reminder_no'  => (int) $invoice['reminder_count'] + 1,
        ], $ip, $userAgent);

        return ['sent_to' => $to, 'days_overdue' => $daysOverdue];
    }

    /** Stejná logika jako v SendEmailAction::resolveRecipients. */
    private function resolveRecipients(array $invoice): array
    {
        $emails = [];
        if (!empty($invoice['client_main_email'])) {
            $emails[] = $invoice['client_main_email'];
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
        return array_values(array_filter($emails, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

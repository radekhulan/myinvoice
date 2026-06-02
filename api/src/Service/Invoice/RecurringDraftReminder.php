<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Mail\Mailer;

/**
 * Pošle dodavateli (tenantovi) připomínku, že otevřený koncept pravidelné faktury
 * (draft_open_mode='period_start') se blíží automatickému vystavení — ať stihne
 * doplnit vícepráce do výkazu práce. Volá se z cron-generate-recurring-invoices.php
 * v reminder fázi.
 *
 * Příjemcem je e-mail dodavatele (NE klienta) — jde o interní připomínku.
 * Idempotenci (jednou za období) řeší cron přes last_reminder_date.
 */
final class RecurringDraftReminder
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly Connection $db,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param array $template řádek šablony z RecurringTemplateRepository::find()
     * @return bool true pokud byl e-mail odeslán
     */
    public function send(array $template, int $invoiceId, string $ua = 'cron'): bool
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null || ($invoice['status'] ?? '') !== 'draft') {
            return false; // koncept už neexistuje nebo byl vystaven → není co připomínat
        }

        $supplierId = (int) $template['supplier_id'];
        $recipient = $this->supplierEmail($supplierId);
        if ($recipient === null) {
            return false; // dodavatel nemá platný e-mail → nemáme kam připomínat
        }

        $locale = 'cs'; // interní připomínka tenantovi
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');

        $issueDate = (string) $invoice['issue_date'];
        $clientName = (string) ($invoice['client_company_name'] ?? $template['client_company_name'] ?? '');
        $issueFmt = date('j.n.Y', strtotime($issueDate) ?: time());

        $vars = [
            'template_name'   => (string) $template['name'],
            'client_name'     => $clientName,
            'issue_date'      => $issueDate,
            'work_item_count' => $this->workReportItemCount($invoiceId),
            'amount_to_pay'   => (float) ($invoice['amount_to_pay'] ?? $invoice['total_with_vat'] ?? 0),
            'invoice'         => $invoice,
            'edit_link'       => $appUrl !== '' ? "{$appUrl}/invoices/{$invoiceId}" : '',
            'supplier'        => $this->loadSupplierFooter($supplierId),
            // Subject pro file-template path (DB override admin subject ho přebije).
            'subject'         => "Koncept pravidelné faktury se vystaví {$issueFmt}"
                                 . ($clientName !== '' ? " — {$clientName}" : ''),
        ];

        try {
            $this->mailer->sendTemplate('recurring_draft_reminder', $locale, [$recipient], $vars);
        } catch (\Throwable $e) {
            // Selhání interní připomínky zalogujeme do přehledu e-mailů a propustíme dál
            // (cron-generate-recurring-invoices ho započítá do errors).
            $this->logger->log('recurring.reminder_failed', (int) $template['created_by'], 'recurring_template', (int) $template['id'], [
                'invoice_id' => $invoiceId,
                'to'         => $recipient,
                'issue_date' => (string) $invoice['issue_date'],
                'error'      => mb_substr($e->getMessage(), 0, 500),
            ], '', $ua);
            throw $e;
        }

        $this->logger->log('recurring.reminder_sent', (int) $template['created_by'], 'recurring_template', (int) $template['id'], [
            'invoice_id' => $invoiceId,
            'to'         => $recipient,
            'issue_date' => (string) $invoice['issue_date'],
        ], '', $ua);

        return true;
    }

    private function supplierEmail(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT email FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $email = trim((string) $stmt->fetchColumn());
        return ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
    }

    private function workReportItemCount(int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM work_report_items wri
               JOIN work_reports wr ON wr.id = wri.work_report_id
              WHERE wr.invoice_id = ?'
        );
        $stmt->execute([$invoiceId]);
        return (int) $stmt->fetchColumn();
    }

    /** Supplier kontext pro patičku + branding e-mailu (live, dle template supplier_id). */
    private function loadSupplierFooter(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.company_name, s.display_name, s.tagline, s.street, s.city, s.zip,
                    s.email, s.phone, s.web,
                    s.email_branding_enabled, s.email_accent_color, s.logo_path,
                    co.name_cs AS country
               FROM supplier s
          LEFT JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row['email_branding_enabled'] = (bool) ($row['email_branding_enabled'] ?? false);
        $row['email_accent_color']     = (string) ($row['email_accent_color'] ?: '#3B2D83');
        $row['logo_path']              = $row['logo_path'] ?: null;
        return $row;
    }
}

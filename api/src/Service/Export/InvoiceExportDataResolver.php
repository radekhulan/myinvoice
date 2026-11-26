<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Shared party/payment data resolver for invoice XML exports.
 *
 * Issued invoices should use immutable snapshots, but legacy/imported data may
 * miss snapshots or individual snapshot fields. Live rows are therefore used as
 * a defensive base and snapshots win when present.
 */
final class InvoiceExportDataResolver
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    public function supplier(array $invoice): array
    {
        $live = $this->loadLiveSupplier((int) ($invoice['supplier_id'] ?? 0));
        $snapshot = $this->snapshot($invoice['supplier_snapshot'] ?? null);

        return $snapshot !== [] ? array_merge($live, $snapshot) : $live;
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    public function client(array $invoice): array
    {
        $live = $this->loadLiveClient((int) ($invoice['client_id'] ?? 0), (int) ($invoice['supplier_id'] ?? 0));
        $snapshot = $this->snapshot($invoice['client_snapshot'] ?? null);
        if ($snapshot !== []) {
            return array_merge($live, $snapshot);
        }
        if ($live !== []) {
            return $live;
        }

        return [
            'company_name' => $invoice['client_company_name'] ?? '',
            'ic' => $invoice['client_ic'] ?? '',
            'dic' => $invoice['client_dic'] ?? '',
            'main_email' => $invoice['client_main_email'] ?? '',
            'country_iso2' => 'CZ',
        ];
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>|null
     */
    public function bank(array $invoice): ?array
    {
        $snapshot = $this->snapshot($invoice['bank_snapshot'] ?? null);
        if ($snapshot !== []) {
            return $snapshot;
        }
        if (!empty($invoice['bank_account_number']) || !empty($invoice['bank_iban'])) {
            return [
                'account_number' => $invoice['bank_account_number'] ?? null,
                'bank_code' => $invoice['bank_code'] ?? null,
                'bank_name' => $invoice['bank_name'] ?? null,
                'iban' => $invoice['bank_iban'] ?? null,
                'bic' => $invoice['bank_bic'] ?? null,
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $invoice
     */
    public function issuePerson(array $invoice): string
    {
        $direct = $this->nonEmptyString($invoice['issue_person'] ?? null)
            ?? $this->nonEmptyString($invoice['created_by_name'] ?? null)
            ?? $this->nonEmptyString($invoice['user_name'] ?? null);
        if ($direct !== null) {
            return $direct;
        }

        $userId = (int) ($invoice['created_by'] ?? 0);
        if ($userId <= 0) {
            return '';
        }

        try {
            $stmt = $this->db->pdo()->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $name = $stmt->fetchColumn();
        } catch (\Throwable) {
            return '';
        }

        return $this->nonEmptyString($name) ?? '';
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadLiveSupplier(int $supplierId): array
    {
        if ($supplierId <= 0) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM supplier s
               JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadLiveClient(int $clientId, int $supplierId): array
    {
        if ($clientId <= 0) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM clients c
               JOIN countries co ON co.id = c.country_id
              WHERE c.id = ? AND c.supplier_id = ?'
        );
        $stmt->execute([$clientId, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        return $string !== '' ? $string : null;
    }
}

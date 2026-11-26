<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Resolver default hodnot pro novou fakturu — ze supplier, client, project.
 */
final class InvoiceDefaults
{
    private const INVALID_REFERENCE = 'Neplatný odkaz na související záznam faktury.';

    public function __construct(private readonly Connection $db) {}

    /**
     * Doplní chybějící pole v $data podle defaultů.
     */
    public function resolve(array $data, int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $today = date('Y-m-d');

        $clientId = self::referenceId($data['client_id'] ?? null);
        $projectId = self::referenceId($data['project_id'] ?? null);
        $categoryId = self::referenceId($data['revenue_category_id'] ?? null);
        $currencyId = self::referenceId($data['currency_id'] ?? null);
        if ($supplierId <= 0 || $clientId === null) {
            throw new \InvalidArgumentException(self::INVALID_REFERENCE);
        }

        $stmt = $pdo->prepare(
            'SELECT language, currency_default_id, reverse_charge, payment_due_default, payment_due_unit
               FROM clients WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$clientId, $supplierId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($client === false) {
            throw new \InvalidArgumentException(self::INVALID_REFERENCE);
        }

        $project = null;
        if ($projectId !== null) {
            $stmt = $pdo->prepare(
                'SELECT currency_id, payment_due_days, payment_due_unit
                   FROM projects WHERE id = ? AND client_id = ?'
            );
            $stmt->execute([$projectId, $clientId]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($project === null) {
                throw new \InvalidArgumentException(self::INVALID_REFERENCE);
            }
        }

        if ($categoryId !== null) {
            $stmt = $pdo->prepare('SELECT 1 FROM revenue_categories WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$categoryId, $supplierId]);
            if (!$stmt->fetchColumn()) {
                throw new \InvalidArgumentException(self::INVALID_REFERENCE);
            }
        }

        $stmt = $pdo->prepare(
            'SELECT default_currency_id, default_payment_due_days, default_payment_due_unit
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Defaults
        $data['issue_date'] = (string) ($data['issue_date'] ?? $today);

        $type = (string) ($data['invoice_type'] ?? 'invoice');
        if ($type === 'proforma') {
            $data['tax_date'] = null;
        } else {
            $data['tax_date'] = (string) ($data['tax_date'] ?? $today);
        }

        if ($currencyId === null && !empty($data['currency']) && is_string($data['currency'])) {
            $stmt = $pdo->prepare(
                'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
            );
            $stmt->execute([$supplierId, strtoupper($data['currency'])]);
            $currencyId = (int) $stmt->fetchColumn() ?: null;
        }
        if ($currencyId === null) {
            $currencyId = (int) (
                $project['currency_id']
                ?? $client['currency_default_id']
                ?? $supplier['default_currency_id']
                ?? 0
            );
            if ($currencyId <= 0) {
                $stmt = $pdo->prepare(
                    "SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY is_default DESC LIMIT 1"
                );
                $stmt->execute([$supplierId]);
                $currencyId = (int) $stmt->fetchColumn();
            }
        }

        $stmt = $pdo->prepare('SELECT 1 FROM currencies WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$currencyId, $supplierId]);
        if (!$stmt->fetchColumn()) {
            throw new \InvalidArgumentException(self::INVALID_REFERENCE);
        }
        $data['currency_id'] = $currencyId;

        if (empty($data['language'])) {
            $data['language'] = $client['language'] ?? 'cs';
        }

        if (!isset($data['reverse_charge'])) {
            $data['reverse_charge'] = (bool) ($client['reverse_charge'] ?? false);
        }

        if (empty($data['due_date'])) {
            if ($project !== null && $project['payment_due_days'] !== null) {
                $dueValue = (int) $project['payment_due_days'];
                $dueUnit = (string) ($project['payment_due_unit'] ?? 'days');
            } elseif ($client['payment_due_default'] !== null) {
                $dueValue = (int) $client['payment_due_default'];
                $dueUnit = (string) (
                    $client['payment_due_unit']
                    ?? $supplier['default_payment_due_unit']
                    ?? 'days'
                );
            } elseif ($supplier !== null && $supplier['default_payment_due_days'] !== null) {
                $dueValue = (int) $supplier['default_payment_due_days'];
                $dueUnit = (string) ($supplier['default_payment_due_unit'] ?? 'days');
            } else {
                $dueValue = 7;
                $dueUnit = 'days';
            }
            $data['due_date'] = DueDateCalculator::calculate(
                $data['issue_date'],
                $dueValue,
                $dueUnit,
            );
        }

        return $data;
    }

    private static function referenceId(mixed $value): ?int
    {
        if (in_array($value, [null, '', 0, '0'], true)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || is_bool($value)) {
            throw new \InvalidArgumentException(self::INVALID_REFERENCE);
        }
        return $id;
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;

final class IdokladBankTransactionImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly IdokladClient $idoklad,
        private readonly StatementMatcher $matcher,
        private readonly EmailNoticeReconciler $reconciler,
        private readonly InvoicePaymentService $payments,
    ) {}

    /** @return array{created:int,skipped:int,matched:int,unmapped:int,document_links:int} */
    public function import(int $supplierId, bool $dryRun, bool $incremental = false): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'matched' => 0, 'unmapped' => 0, 'document_links' => 0];
        $pdo = $this->db->pdo();
        $accounts = $this->mappedAccounts($pdo, $supplierId, $dryRun);
        $lastExternalId = $incremental ? $this->lastExternalId($pdo, $supplierId) : null;
        $query = $lastExternalId !== null ? ['filter' => "Id~gt~{$lastExternalId}"] : [];

        foreach ($this->idoklad->getAll($supplierId, 'BankStatements', $query) as $movement) {
            $externalId = (int) ($movement['Id'] ?? 0);
            $externalAccountId = (int) ($movement['BankAccountId'] ?? 0);
            if ($externalId <= 0 || $externalAccountId <= 0) {
                $result['skipped']++;
                continue;
            }
            $sourceRef = $supplierId . ':' . $externalId;
            if ($this->exists($pdo, $sourceRef)) {
                $result['skipped']++;
                continue;
            }

            $account = $accounts[(string) $externalAccountId] ?? null;
            if ($account === null) {
                $result['unmapped']++;
                continue;
            }
            $date = $this->date($movement['DateOfTransaction'] ?? null);
            $amount = abs((float) ($movement['Prices']['TotalWithVat'] ?? 0));
            if ((int) ($movement['MovementType'] ?? 1) < 0) $amount *= -1;
            if ($date === null || abs($amount) < 0.005) {
                $result['skipped']++;
                continue;
            }
            if ($dryRun) {
                $result['created']++;
                if ($this->hasPairedIssuedInvoice($pdo, $supplierId, $movement, $amount, (string) $account['currency'])) {
                    $result['document_links']++;
                }
                continue;
            }

            $statementId = 0;
            $txId = 0;
            try {
                $statementId = $this->statement($pdo, $supplierId, $externalAccountId, $date, $account);
                $pdo->prepare(
                "INSERT INTO bank_transactions
                    (source, source_ref, statement_id, posted_at, amount, currency,
                     variable_symbol, constant_symbol, specific_symbol, counterparty_account,
                     counterparty_bank, counterparty_name, description, bank_ref)
                 VALUES ('idoklad', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                $sourceRef, $statementId, $date, number_format($amount, 2, '.', ''),
                (string) $account['currency'], self::text($movement['VariableSymbol'] ?? null, 20),
                self::text($movement['ConstantSymbol'] ?? null, 10), self::text($movement['SpecificSymbol'] ?? null, 20),
                self::text($movement['PartnerAccountNumber'] ?? null, 40),
                self::text($movement['PartnerBankCode'] ?? null, 4), self::text($movement['PartnerName'] ?? null, 190),
                self::text($movement['Description'] ?? $movement['DocumentNumber'] ?? null, 255),
                self::text($movement['DocumentNumber'] ?? null, 40),
                ]);
                $txId = (int) $pdo->lastInsertId();

                if ($this->hasPairedIssuedInvoice(
                    $pdo, $supplierId, $movement, $amount, (string) $account['currency']
                )) {
                    $result['document_links']++;
                }

                $authoritativeTwinId = $this->reconciler->ignoreSecondaryWhenAuthoritativeTwinExists($txId);
                if ($authoritativeTwinId !== null) {
                    if ($this->reconcileAuthoritativeInvoice(
                        $pdo, $authoritativeTwinId, $supplierId, $movement, $amount, (string) $account['currency']
                    )) $result['matched']++;
                } else {
                    if ($this->matchPairedIssuedInvoice($pdo, $txId, $supplierId, $movement, $amount, $date, (string) $account['currency'])) {
                        $result['matched']++;
                    } else {
                        $match = $this->matcher->match($txId);
                        if (in_array($match['status'], ['auto_exact', 'auto_partial'], true)) $result['matched']++;
                    }
                }
                $this->refreshStatement($pdo, $statementId);
                $result['created']++;
            } catch (\Throwable $e) {
                if (isset($txId) && $txId > 0) {
                    $pdo->prepare('DELETE FROM bank_transactions WHERE id = ?')->execute([$txId]);
                }
                if ($statementId > 0) $this->refreshStatement($pdo, $statementId);
                throw $e;
            }
        }
        return $result;
    }

    private function lastExternalId(PDO $pdo, int $supplierId): ?int
    {
        $prefix = $supplierId . ':%';
        $stmt = $pdo->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(source_ref, ':', -1) AS UNSIGNED))
               FROM bank_transactions
              WHERE source = 'idoklad' AND source_ref LIKE ?"
        );
        $stmt->execute([$prefix]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    private function exists(PDO $pdo, string $externalId): bool
    {
        $s = $pdo->prepare("SELECT 1 FROM bank_transactions WHERE source = 'idoklad' AND source_ref = ? LIMIT 1");
        $s->execute([$externalId]);
        return (bool) $s->fetchColumn();
    }

    /** @return array<string,array<string,mixed>> keyed by iDoklad BankAccount.Id */
    private function mappedAccounts(PDO $pdo, int $supplierId, bool $includeDryRunCandidates): array
    {
        $s = $pdo->prepare(
            "SELECT m.external_account_id, c.id, c.account_number, c.bank_code, c.iban, c.code AS currency
               FROM external_bank_account_mappings m
               JOIN currencies c ON c.id = m.currency_id AND c.supplier_id = m.supplier_id
              WHERE m.supplier_id = ? AND m.provider = 'idoklad'
                AND m.sync_status = 'matched' AND c.is_active = 1"
        );
        $s->execute([$supplierId]);
        $mapped = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $mapped[(string) $row['external_account_id']] = $row;
        }
        if (!$includeDryRunCandidates) return $mapped;

        $currencies = $pdo->prepare(
            'SELECT id, account_number, bank_code, iban, code AS currency
               FROM currencies WHERE supplier_id = ? AND is_active = 1 ORDER BY id'
        );
        $currencies->execute([$supplierId]);
        $byCode = [];
        foreach ($currencies->fetchAll(PDO::FETCH_ASSOC) ?: [] as $currency) {
            $byCode[strtoupper((string) $currency['currency'])][] = $currency;
        }
        $currencyCodes = $this->idoklad->currencyCodeMap($supplierId);
        foreach ($this->idoklad->getAll($supplierId, 'BankAccounts') as $external) {
            $externalId = (int) ($external['Id'] ?? 0);
            if ($externalId <= 0 || isset($mapped[(string) $externalId])) continue;
            $code = strtoupper((string) ($currencyCodes[(int) ($external['CurrencyId'] ?? 0)] ?? 'CZK'));
            $selection = IdokladImportService::matchExternalBankAccount($external, $byCode[$code] ?? []);
            if ($selection['status'] !== 'matched' || $selection['currency_id'] === null) continue;
            foreach ($byCode[$code] ?? [] as $candidate) {
                if ((int) $candidate['id'] === $selection['currency_id']) {
                    $mapped[(string) $externalId] = $candidate;
                    break;
                }
            }
        }
        return $mapped;
    }

    /** @param array<string,mixed> $account */
    private function statement(PDO $pdo, int $supplierId, int $externalAccountId, string $date, array $account): int
    {
        $month = substr($date, 0, 7);
        $ref = $supplierId . ':' . $externalAccountId . ':' . $month;
        $hash = hash('sha256', 'idoklad:' . $ref);
        $pdo->prepare(
            "INSERT INTO bank_statements
                (source, source_ref, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES ('idoklad', ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE statement_date = GREATEST(statement_date, VALUES(statement_date))"
        )->execute([
            $ref, 'iDoklad ' . $month, $hash, (string) $account['account_number'],
            self::text($account['bank_code'] ?? null, 4), (string) $account['currency'], $date,
        ]);
        $s = $pdo->prepare('SELECT id FROM bank_statements WHERE file_hash = ?');
        $s->execute([$hash]);
        return (int) $s->fetchColumn();
    }

    /** @param array<string,mixed> $movement */
    private function matchPairedIssuedInvoice(PDO $pdo, int $txId, int $supplierId, array $movement, float $amount, string $date, string $movementCurrency): bool
    {
        $invoice = $this->pairedIssuedInvoice($pdo, $supplierId, $movement, $amount, $movementCurrency);
        if ($invoice === null) return false;
        $invoiceId = (int) $invoice['id'];
        if ((string) $invoice['status'] === 'paid') {
            $pdo->prepare("UPDATE bank_transactions SET matched_invoice_id = ?, match_status = 'auto_exact', matched_at = NOW() WHERE id = ?")
                ->execute([$invoiceId, $txId]);
            return true;
        }
        if (!in_array((string) $invoice['status'], ['issued', 'sent', 'reminded'], true)) return false;
        $this->payments->recordPayment($invoiceId, $amount, $date, [
            'source' => 'bank', 'bank_transaction_id' => $txId,
            'variable_symbol' => self::text($movement['VariableSymbol'] ?? null, 20),
            'bank_reference' => 'iDoklad BankStatement ' . (int) ($movement['Id'] ?? 0),
        ]);
        $pdo->prepare("UPDATE bank_transactions SET matched_invoice_id = ?, match_status = 'auto_exact', matched_at = NOW() WHERE id = ?")
            ->execute([$invoiceId, $txId]);
        return true;
    }

    /** @param array<string,mixed> $movement */
    private function hasPairedIssuedInvoice(PDO $pdo, int $supplierId, array $movement, float $amount, string $currency): bool
    {
        return $this->pairedIssuedInvoice($pdo, $supplierId, $movement, $amount, $currency) !== null;
    }

    /**
     * iDoklad zná přesné ID dokladu, GPC je zdroj pravdy pro existenci platby.
     * Opravíme pouze automatickou vazbu bez platebních záznamů; manuální nebo
     * účetně evidovanou vazbu nikdy nepřepisujeme.
     *
     * @param array<string,mixed> $movement
     */
    private function reconcileAuthoritativeInvoice(PDO $pdo, int $authoritativeTxId, int $supplierId, array $movement, float $amount, string $currency): bool
    {
        $invoice = $this->pairedIssuedInvoice($pdo, $supplierId, $movement, $amount, $currency);
        if ($invoice === null || (string) $invoice['status'] !== 'paid') return false;
        $targetInvoiceId = (int) $invoice['id'];
        $s = $pdo->prepare(
            "SELECT bt.match_status, bt.matched_invoice_id,
                    (SELECT COUNT(*) FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id) AS invoice_payments,
                    (SELECT COUNT(*) FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id) AS purchase_matches
               FROM bank_transactions bt
              WHERE bt.id = ? AND bt.source = 'statement'"
        );
        $s->execute([$authoritativeTxId]);
        $tx = $s->fetch(PDO::FETCH_ASSOC);
        if ($tx === false) return false;
        if ((int) ($tx['matched_invoice_id'] ?? 0) === $targetInvoiceId) return true;
        if (!in_array((string) $tx['match_status'], ['unmatched', 'auto_exact', 'auto_partial'], true)) return false;
        if ((int) $tx['invoice_payments'] > 0 || (int) $tx['purchase_matches'] > 0) return false;
        $pdo->prepare(
            "UPDATE bank_transactions
                SET matched_invoice_id = ?, match_status = 'auto_exact', matched_at = NOW(), matched_by = NULL
              WHERE id = ?"
        )->execute([$targetInvoiceId, $authoritativeTxId]);
        return true;
    }

    /** @param array<string,mixed> $movement @return array<string,mixed>|null */
    private function pairedIssuedInvoice(PDO $pdo, int $supplierId, array $movement, float $amount, string $movementCurrency): ?array
    {
        $paired = is_array($movement['PairedDocument'] ?? null) ? $movement['PairedDocument'] : [];
        $documentId = (int) ($paired['DocumentId'] ?? 0);
        $type = (int) ($paired['DocumentType'] ?? -1);
        if ($amount <= 0 || $documentId <= 0 || !in_array($type, [0, 1], true)) return null;
        $s = $pdo->prepare(
            'SELECT i.id, i.status, c.code AS currency
               FROM invoices i JOIN currencies c ON c.id = i.currency_id
              WHERE i.supplier_id = ? AND i.idoklad_id = ? LIMIT 2'
        );
        $s->execute([$supplierId, $documentId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) !== 1) return null;
        if (strtoupper($movementCurrency) !== strtoupper((string) $rows[0]['currency'])) return null;
        return $rows[0];
    }

    private function refreshStatement(PDO $pdo, int $statementId): void
    {
        $pdo->prepare(
            "UPDATE bank_statements SET
                transaction_count = (SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ?),
                matched_count = (SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ? AND match_status IN ('auto_exact','auto_partial','manual'))
              WHERE id = ?"
        )->execute([$statementId, $statementId, $statementId]);
    }

    private function date(mixed $value): ?string
    {
        $value = (string) ($value ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null;
    }

    private static function text(mixed $value, int $length): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}

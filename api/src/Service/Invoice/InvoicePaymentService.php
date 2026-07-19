<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;

/**
 * Evidence plateb k vydaným fakturám a proformám (#89).
 *
 * Zdroj pravdy je tabulka `invoice_payments`; `invoices.paid_total` je udržovaná
 * suma (přepočítává se zde po každé změně). Zbývá-k-úhradě = amount_to_pay - paid_total
 * (formule amount_to_pay se neměnila — je to statické „K úhradě" dokladu na PDF).
 *
 * Lifecycle status (`invoices.status`) se NErozšiřuje — platební stav je odvozená
 * dimenze (paymentStatus()): unpaid / partially_paid / paid / overpaid. Služba ale
 * přepíná lifecycle paid ↔ issued/sent podle toho, zda platby pokryjí částku:
 *   - suma plateb >= amount_to_pay - TOLERANCE → status 'paid', paid_at = poslední platba
 *   - smazání platby pod tuto hranici → revert na 'sent'/'issued' (jako unmark-paid)
 *
 * Transakce: respektuje otevřenou transakci volajícího (StatementMatcher), jinak
 * vlastní. Side-effecty (PDF invalidace, stats) běží až po DB zápisu.
 */
final class InvoicePaymentService
{
    /** Stejná tolerance jako StatementMatcher::EXACT_MATCH_TOLERANCE (DPH zaokrouhlení). */
    public const TOLERANCE = 0.05;

    /** Typy dokladů, na které lze evidovat platbu. */
    private const PAYABLE_TYPES = ['invoice', 'proforma'];

    public function __construct(
        private readonly Connection $db,
        private readonly InvoicePdfRenderer $pdf,
        private readonly StatsRecomputer $stats,
    ) {}

    /**
     * Odvozený platební stav dokladu. NULL pro draft/cancelled (nedává smysl).
     * Pracuje nad poli `status`, `amount_to_pay`, `paid_total` (najdi přes repo/SQL).
     */
    public static function paymentStatus(array $invoice): ?string
    {
        $status = (string) ($invoice['status'] ?? '');
        if (in_array($status, ['draft', 'cancelled'], true)) {
            return null;
        }
        $due  = (float) ($invoice['amount_to_pay'] ?? 0);
        $paid = (float) ($invoice['paid_total'] ?? 0);

        if ($paid > $due + self::TOLERANCE && $due > 0) {
            return 'overpaid';
        }
        if ($status === 'paid' || ($paid > 0 && $paid >= $due - self::TOLERANCE)) {
            return 'paid';
        }
        return $paid > 0 ? 'partially_paid' : 'unpaid';
    }

    /**
     * Platby faktury pro detail (vč. čísla/stavu daňového dokladu k platbě
     * a odkazu na bankovní výpis).
     *
     * @return list<array<string,mixed>>
     */
    public function listFor(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.*,
                    td.varsymbol AS tax_document_varsymbol, td.status AS tax_document_status,
                    bt.statement_id AS bank_statement_id, bt.counterparty_name AS bank_counterparty_name
               FROM invoice_payments p
          LEFT JOIN invoices td ON td.id = p.tax_document_invoice_id
          LEFT JOIN bank_transactions bt ON bt.id = p.bank_transaction_id
              WHERE p.invoice_id = ?
              ORDER BY p.paid_on, p.id'
        );
        $stmt->execute([$invoiceId]);
        return array_map([self::class, 'castPayment'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Bankovní operace přímo spárované s fakturou, nezávisle na účetní evidenci
     * invoice_payments. Typicky jde o historické faktury importované jako uhrazené
     * z iDokladu: bankovní matcher zná přesnou vazbu, ale zpětně nevytváří platbu.
     *
     * @return list<array<string,mixed>>
     */
    public function listRelatedBankTransactions(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.id, bt.statement_id, bs.source AS statement_source,
                    bt.posted_at, bt.amount, bt.currency,
                    bt.variable_symbol, bt.constant_symbol, bt.specific_symbol,
                    bt.counterparty_account, bt.counterparty_bank, bt.counterparty_name,
                    bt.description, bt.bank_ref, bt.match_status
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.matched_invoice_id = ?
              ORDER BY bt.posted_at, bt.id'
        );
        $stmt->execute([$invoiceId]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['statement_id'] = (int) $row['statement_id'];
            $row['amount'] = (float) $row['amount'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function findPayment(int $paymentId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM invoice_payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castPayment($row);
    }

    /**
     * Zaeviduje platbu (v měně faktury) a přepočítá paid_total + lifecycle status.
     *
     * @param array{variable_symbol?: ?string, bank_reference?: ?string, note?: ?string,
     *              source?: string, bank_transaction_id?: ?int, created_by?: ?int} $opts
     * @return array{payment_id: int, became_paid: bool, remaining: float}
     * @throws \RuntimeException při validační chybě (zpráva pro UI)
     */
    public function recordPayment(int $invoiceId, float $amount, string $paidOn, array $opts = []): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $paidOn, $dm)
            || !checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1])) {
            throw new \RuntimeException('Neplatné datum platby.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \RuntimeException('Částka platby musí být větší než 0.');
        }
        // DECIMAL(12,2) — bez kontroly by overflow shodil INSERT na PDOException (500).
        if ($amount > 9999999999.99) {
            throw new \RuntimeException('Částka platby je mimo rozsah.');
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT i.id, i.supplier_id, i.invoice_type, i.status, i.amount_to_pay, i.paid_total,
                    cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ?'
        );
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false) {
            throw new \RuntimeException('Faktura nenalezena.');
        }
        if (!in_array((string) $invoice['invoice_type'], self::PAYABLE_TYPES, true)) {
            throw new \RuntimeException('Platby lze evidovat jen u faktur a zálohových faktur.');
        }
        if (!in_array((string) $invoice['status'], ['issued', 'sent', 'reminded', 'paid'], true)) {
            throw new \RuntimeException('Platby lze evidovat jen u vystaveného dokladu.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $ins = $pdo->prepare(
                'INSERT INTO invoice_payments
                   (supplier_id, invoice_id, paid_on, amount, currency,
                    variable_symbol, bank_reference, note, source, bank_transaction_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                (int) $invoice['supplier_id'],
                $invoiceId,
                $paidOn,
                $amount,
                (string) $invoice['currency'],
                self::trimOrNull($opts['variable_symbol'] ?? null, 20),
                self::trimOrNull($opts['bank_reference'] ?? null, 120),
                self::trimOrNull($opts['note'] ?? null, 255),
                in_array($opts['source'] ?? '', ['manual', 'mark_paid', 'bank'], true) ? $opts['source'] : 'manual',
                isset($opts['bank_transaction_id']) && (int) $opts['bank_transaction_id'] > 0
                    ? (int) $opts['bank_transaction_id'] : null,
                isset($opts['created_by']) && (int) $opts['created_by'] > 0 ? (int) $opts['created_by'] : null,
            ]);
            $paymentId = (int) $pdo->lastInsertId();

            $transition = $this->recomputeLocked($pdo, $invoiceId);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->afterTransition($invoiceId, $transition);

        return [
            'payment_id'  => $paymentId,
            'became_paid' => $transition['became_paid'],
            'remaining'   => $transition['remaining'],
        ];
    }

    /**
     * Rekonciliace: naváže JIŽ EXISTUJÍCÍ platbu zaplacené faktury na bankovní
     * transakci (sloučená úhrada zaplacených faktur). Použití: faktura už byla
     * označená jako zaplacená (ručně/legacy/import) bez vazby na konkrétní platbu
     * z výpisu, a uživatel teď páruje příchozí bankovní transakci k téhle faktuře.
     *
     * NEMĚNÍ paid_total ani stav faktury (jen doplní bank_transaction_id k existující
     * platbě) — proto žádné dvojí zdanění/přeplacení. Vyžaduje PRÁVĚ JEDNU dosud
     * nenavázanou platbu (bank_transaction_id IS NULL); 0 = faktura už je spárovaná
     * s jinou transakcí, >1 = nejednoznačné (rekonciliaci proveď ručně). Tím je
     * zaručeno, že nikdy neporušíme UNIQUE(bank_transaction_id, invoice_id).
     *
     * Musí běžet uvnitř transakce volajícího (faktura by měla být zamčená FOR UPDATE).
     *
     * @param array{variable_symbol?: ?string, bank_reference?: ?string} $opts
     * @return array{payment_id: int, amount: float}
     */
    public function reconcileToBankTransaction(int $invoiceId, int $txId, array $opts = []): array
    {
        $pdo = $this->db->pdo();
        $sel = $pdo->prepare(
            'SELECT id, amount FROM invoice_payments
              WHERE invoice_id = ? AND bank_transaction_id IS NULL
           ORDER BY id'
        );
        $sel->execute([$invoiceId]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 0) {
            throw new \RuntimeException(
                'Faktura nemá nenavázanou platbu k rekonciliaci (je už spárovaná s jinou transakcí).'
            );
        }
        if (count($rows) > 1) {
            throw new \RuntimeException(
                'Faktura má více nenavázaných plateb — rekonciliaci proveď ručně v detailu faktury.'
            );
        }
        $paymentId = (int) $rows[0]['id'];
        $pdo->prepare(
            'UPDATE invoice_payments
                SET bank_transaction_id = ?,
                    variable_symbol = COALESCE(variable_symbol, ?),
                    bank_reference  = COALESCE(bank_reference, ?)
              WHERE id = ? AND bank_transaction_id IS NULL'
        )->execute([
            $txId,
            self::trimOrNull($opts['variable_symbol'] ?? null, 20),
            self::trimOrNull($opts['bank_reference'] ?? null, 120),
            $paymentId,
        ]);

        return ['payment_id' => $paymentId, 'amount' => round((float) $rows[0]['amount'], 2)];
    }

    /**
     * Smaže platbu a přepočítá paid_total + status (může revertovat 'paid').
     *
     * Guardy (vynechatelné přes $skipBankGuard pro bank-unmatch flow, který maže
     * platbu navázané transakce záměrně):
     *   - platba s bankovní vazbou → mazat přes „Zrušit spárování" v detailu výpisu
     *   - platba s vystaveným daňovým dokladem → nejdřív smazat/stornovat doklad
     *
     * @return array{became_unpaid: bool, remaining: float}
     */
    public function deletePayment(int $paymentId, bool $skipBankGuard = false): array
    {
        $payment = $this->findPayment($paymentId);
        if ($payment === null) {
            throw new \RuntimeException('Platba nenalezena.');
        }
        if (!$skipBankGuard && $payment['bank_transaction_id'] !== null) {
            throw new \RuntimeException(
                'Platba je navázaná na bankovní transakci. Zruš spárování v detailu výpisu.'
            );
        }
        if ($payment['tax_document_invoice_id'] !== null) {
            $pdo = $this->db->pdo();
            $td = $pdo->prepare("SELECT status FROM invoices WHERE id = ?");
            $td->execute([(int) $payment['tax_document_invoice_id']]);
            $tdStatus = $td->fetchColumn();
            if ($tdStatus !== false && $tdStatus !== 'cancelled') {
                throw new \RuntimeException(
                    'K platbě je vystavený daňový doklad. Nejdřív ho smaž (koncept) nebo stornuj.'
                );
            }
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('DELETE FROM invoice_payments WHERE id = ?')->execute([$paymentId]);
            $transition = $this->recomputeLocked($pdo, (int) $payment['invoice_id']);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->afterTransition((int) $payment['invoice_id'], $transition);

        return [
            'became_unpaid' => $transition['became_unpaid'],
            'remaining'     => $transition['remaining'],
        ];
    }

    /**
     * Smaže VŠECHNY platby faktury (unmark-paid flow). Guardy (bankovní vazby,
     * daňové doklady) ověřuje volající. Vrací počet smazaných plateb.
     */
    public function deleteAllForInvoice(int $invoiceId): int
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $del = $pdo->prepare('DELETE FROM invoice_payments WHERE invoice_id = ?');
            $del->execute([$invoiceId]);
            $count = $del->rowCount();
            $transition = $this->recomputeLocked($pdo, $invoiceId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->afterTransition($invoiceId, $transition);
        return $count;
    }

    /**
     * Odpojí/smaže platby navázané na bankovní transakci (unmatch flow). No-op pokud
     * žádná neexistuje (legacy match z dob před evidencí plateb). U sloučené úhrady
     * (jedna platba → více faktur, migrace 0119) je plateb víc.
     *
     * Dva režimy podle původu platby:
     *   - `source = 'bank'` → platbu vytvořilo párování této transakce → SMAZAT
     *     (přepočte paid_total, může revertovat 'paid');
     *   - jiný source (legacy/manual/mark_paid) → platba existovala už PŘED párováním
     *     (rekonciliace zaplacené faktury, viz reconcileToBankTransaction) → jen ODPOJIT
     *     (vynulovat bank_transaction_id), platbu i paid_total ponechat. Jinak by unmatch
     *     smazal reálnou předchozí úhradu a fakturu nesprávně vrátil na nezaplacenou.
     *
     * @return bool true pokud aspoň jedna platba existovala
     */
    public function deleteForBankTransaction(int $bankTransactionId): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, source FROM invoice_payments WHERE bank_transaction_id = ? ORDER BY id'
        );
        $stmt->execute([$bankTransactionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return false;
        }
        foreach ($rows as $r) {
            if ((string) $r['source'] === 'bank') {
                $this->deletePayment((int) $r['id'], skipBankGuard: true);
            } else {
                // Rekonciliovaná dříve existující platba → jen odpojit (paid_total beze změny).
                $pdo->prepare('UPDATE invoice_payments SET bank_transaction_id = NULL WHERE id = ?')
                    ->execute([(int) $r['id']]);
            }
        }
        return true;
    }

    /**
     * Přepočet paid_total + lifecycle transition. Volat UVNITŘ transakce.
     *
     * @return array{became_paid: bool, became_unpaid: bool, remaining: float, paid_total: float}
     */
    private function recomputeLocked(PDO $pdo, int $invoiceId): array
    {
        $pdo->prepare(
            'UPDATE invoices i
                SET i.paid_total = (SELECT COALESCE(SUM(p.amount), 0)
                                      FROM invoice_payments p WHERE p.invoice_id = i.id)
              WHERE i.id = ?'
        )->execute([$invoiceId]);

        $stmt = $pdo->prepare(
            'SELECT status, amount_to_pay, paid_total, sent_at FROM invoices WHERE id = ?'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Faktura nenalezena.');
        }

        $due       = (float) $row['amount_to_pay'];
        $paidTotal = (float) $row['paid_total'];
        $remaining = round($due - $paidTotal, 2);
        $covered   = $paidTotal > 0 && $paidTotal >= $due - self::TOLERANCE;

        $becamePaid = false;
        $becameUnpaid = false;

        if ($covered && in_array((string) $row['status'], ['issued', 'sent', 'reminded'], true)) {
            // paid_at = datum poslední evidované platby (ne dnešek) — DUZP/cash-flow přesnost.
            $pdo->prepare(
                'UPDATE invoices
                    SET status = "paid",
                        paid_at = (SELECT MAX(p.paid_on) FROM invoice_payments p WHERE p.invoice_id = invoices.id)
                  WHERE id = ?'
            )->execute([$invoiceId]);
            $becamePaid = true;
        } elseif (!$covered && (string) $row['status'] === 'paid' && $due > 0) {
            // Revert jen u dokladů s reálnou částkou k úhradě — finální doklady kryté
            // zálohou (amount_to_pay <= 0, auto-paid při vystavení) se nerevertují.
            $pdo->prepare(
                "UPDATE invoices
                    SET status  = IF(sent_at IS NOT NULL, 'sent', 'issued'),
                        paid_at = NULL
                  WHERE id = ? AND status = 'paid'"
            )->execute([$invoiceId]);
            $becameUnpaid = true;
        }

        return [
            'became_paid'   => $becamePaid,
            'became_unpaid' => $becameUnpaid,
            'remaining'     => $remaining,
            'paid_total'    => $paidTotal,
        ];
    }

    /** Side-effecty po commitu: PDF cache (UHRAZENO stamp / QR) + dashboard stats. */
    private function afterTransition(int $invoiceId, array $transition): void
    {
        if ($transition['became_paid']) {
            $this->pdf->invalidate($invoiceId, 'invalidate_mark_paid');
        } elseif ($transition['became_unpaid']) {
            $this->pdf->invalidate($invoiceId, 'invalidate_unmark_paid');
        } else {
            // I bez status flipu se mění obsah PDF — řádek „Uhrazeno / Zbývá uhradit"
            // a QR na zbývající částku. Cached PDF by jinak chtělo starou částku.
            $this->pdf->invalidate($invoiceId, 'invalidate_payment_change');
        }
        // Pohledávkové agregace (po splatnosti, aging) pracují s amount_to_pay - paid_total,
        // takže přepočet je vhodný i bez změny lifecycle statusu. StatsRecomputer si ale
        // otevírá VLASTNÍ transakci — uvnitř transakce volajícího (StatementMatcher,
        // bank unmatch) by spadl na „already an active transaction". Vnořené volání
        // recompute přeskočí (shodné s dosavadním chováním bankovního párování;
        // cache se dopočte při nejbližší přímé akci / cronu).
        if (!$this->db->pdo()->inTransaction()) {
            $this->stats->recomputeForInvoiceId($invoiceId);
        }
    }

    /** @return array<string,mixed> */
    private static function castPayment(array $row): array
    {
        $row['id']          = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['invoice_id']  = (int) $row['invoice_id'];
        $row['amount']      = (float) $row['amount'];
        $row['bank_transaction_id']     = $row['bank_transaction_id'] !== null ? (int) $row['bank_transaction_id'] : null;
        $row['tax_document_invoice_id'] = $row['tax_document_invoice_id'] !== null ? (int) $row['tax_document_invoice_id'] : null;
        $row['created_by']  = $row['created_by'] !== null ? (int) $row['created_by'] : null;
        if (array_key_exists('bank_statement_id', $row)) {
            $row['bank_statement_id'] = $row['bank_statement_id'] !== null ? (int) $row['bank_statement_id'] : null;
        }
        return $row;
    }

    private static function trimOrNull(mixed $value, int $maxLen): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $maxLen);
    }
}

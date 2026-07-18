<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Cross-source dedup GPC ← e-mailové avízo (EmailNoticeReconciler).
 *
 * Scénář: platba dorazila nejdřív e-mailovým avízem a spárovala se (i sloučená úhrada
 * / split). Pak se importuje oficiální GPC výpis se stejnou platbou — reconciler musí
 * PŘEVZÍT párování na GPC transakci (přepojit platby), avízo rozpárovat a NEzaložit
 * duplicitní platbu (jinak falešný přeplatek).
 *
 * Izolace: rok 2099, vlastní statementy/transakce/faktury, vše se v tearDown smaže.
 * Soft-skip bez cfg.php / DB / vhodného supplieru.
 */
#[Group('integration')]
final class EmailNoticeReconcilerTest extends TestCase
{
    private Connection $db;
    private InvoicePaymentService $payments;
    private EmailNoticeReconciler $reconciler;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;

    private const FILE_MARKER = '__entwin__';
    private const VS_A = '2099-70001';
    private const VS_B = '2099-70002';
    private const ALL_VS = [self::VS_A, self::VS_B];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->payments = $c->get(InvoicePaymentService::class);
            $this->reconciler = $c->get(EmailNoticeReconciler::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $cur = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            $this->markTestSkipped('Chybí CZK currency s account_number.');
        }
        $this->currencyId = (int) $cur['id'];
        $this->supplierId = (int) $cur['supplier_id'];
        $this->account = (string) $cur['account_number'];
        $this->bankCode = $cur['bank_code'] !== null ? (string) $cur['bank_code'] : null;

        $this->clientId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->clientId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/user pro supplier.');
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $place = implode(',', array_fill(0, count(self::ALL_VS), '?'));
        $pdo->prepare(
            "DELETE p FROM invoice_payments p
               JOIN invoices i ON i.id = p.invoice_id
              WHERE i.supplier_id = ? AND i.varsymbol IN ($place)"
        )->execute([$this->supplierId, ...self::ALL_VS]);
        $pdo->prepare("DELETE FROM bank_statements WHERE file_name LIKE ?")
            ->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare("DELETE FROM invoices WHERE supplier_id = ? AND varsymbol IN ($place)")
            ->execute([$this->supplierId, ...self::ALL_VS]);
    }

    private function insertInvoice(string $vs, float $amount): int
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, 0, ?)"
        )->execute([
            $vs, $this->clientId, $this->supplierId, $d, $d, $d,
            $this->currencyId, $amount, $amount, $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Vloží výpis + jednu transakci. $source = 'gpc'/'email_notice'/'idoklad',
     * tx source se odvodí ('statement' pro gpc, jinak stejně jako výpis).
     *
     * @return array{0:int,1:int} [statementId, txId]
     */
    private function insertStatementWithTx(string $source, float $amount, string $vs, string $tag): array
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';
        $pdo->prepare(
            "INSERT INTO bank_statements
                (source, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, ?, 'CZK', ?)"
        )->execute([
            $source,
            self::FILE_MARKER . $tag . '.gpc',
            hash('sha256', self::FILE_MARKER . $tag . $amount . $vs),
            $this->account, $this->bankCode, $d,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $txSource = $source === 'gpc' ? 'statement' : $source;
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([$statementId, $txSource, $d, $amount, $vs]);

        return [$statementId, (int) $pdo->lastInsertId()];
    }

    /** Označí avízo-transakci za spárovanou (po zaevidování plateb). */
    private function markTxMatched(int $txId, int $matchedInvoiceId, string $status = 'manual'): void
    {
        $this->db->pdo()->prepare(
            "UPDATE bank_transactions
                SET match_status = ?, matched_invoice_id = ?, matched_at = NOW(), matched_by = ?
              WHERE id = ?"
        )->execute([$status, $matchedInvoiceId, $this->userId, $txId]);
    }

    private function invoiceStatus(int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT status FROM invoices WHERE id = $id")->fetchColumn();
    }

    private function paymentCountForTx(int $txId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = $txId"
        )->fetchColumn();
    }

    // ── Převzetí jednoduchého párování ───────────────────────────────────────

    public function testTakeOverSingleMatch(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [$emailStmt, $emailTx] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'auto_exact');
        // matched_count avíza = 1 (jako po jeho spárování)
        $this->db->pdo()->exec("UPDATE bank_statements SET matched_count = 1 WHERE id = $emailStmt");
        self::assertSame('paid', $this->invoiceStatus($invA));

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNotNull($result, 'Mělo dojít k převzetí.');
        self::assertSame($emailTx, $result['email_tx_id']);
        // Platba přepojena na GPC tx, avízo bez plateb.
        self::assertSame(1, $this->paymentCountForTx($gpcTx));
        self::assertSame(0, $this->paymentCountForTx($emailTx));
        // Faktura zůstává zaplacená (žádné dvojí započtení).
        self::assertSame('paid', $this->invoiceStatus($invA));
        $paid = (float) $this->db->pdo()->query("SELECT paid_total FROM invoices WHERE id = $invA")->fetchColumn();
        self::assertSame(1000.0, $paid);

        // GPC tx přebírá match status + matched_invoice_id; avízo je rozpárované.
        $gpc = $this->db->pdo()->query(
            "SELECT match_status, matched_invoice_id FROM bank_transactions WHERE id = $gpcTx"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('auto_exact', $gpc['match_status']);
        self::assertSame($invA, (int) $gpc['matched_invoice_id']);

        $email = $this->db->pdo()->query(
            "SELECT match_status, matched_invoice_id FROM bank_transactions WHERE id = $emailTx"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('unmatched', $email['match_status']);
        self::assertNull($email['matched_invoice_id']);

        // matched_count avízo-výpisu klesl na 0 → smazání výpisu se může nabídnout.
        $mc = (int) $this->db->pdo()->query("SELECT matched_count FROM bank_statements WHERE id = $emailStmt")->fetchColumn();
        self::assertSame(0, $mc);
    }

    public function testGpcTakesOverIdokladPaymentAndMarksSecondaryIgnored(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [, $idokladTx] = $this->insertStatementWithTx('idoklad', 1000.00, self::VS_A, 'idoklad');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $idokladTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($idokladTx, $invA, 'auto_exact');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNotNull($result);
        self::assertSame('idoklad', $result['secondary_source']);
        self::assertSame(1, $this->paymentCountForTx($gpcTx));
        self::assertSame(0, $this->paymentCountForTx($idokladTx));
        $secondary = $this->db->pdo()->query(
            "SELECT match_status, matched_invoice_id FROM bank_transactions WHERE id = $idokladTx"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('ignored', $secondary['match_status']);
        self::assertNull($secondary['matched_invoice_id']);
    }

    public function testIdokladIsIgnoredWhenGpcAlreadyExists(): void
    {
        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc-first');
        [, $idokladTx] = $this->insertStatementWithTx('idoklad', 1000.00, self::VS_A, 'idoklad-second');

        self::assertSame($gpcTx, $this->reconciler->ignoreSecondaryWhenAuthoritativeTwinExists($idokladTx));
        $status = $this->db->pdo()->query(
            "SELECT match_status FROM bank_transactions WHERE id = $idokladTx"
        )->fetchColumn();
        self::assertSame('ignored', $status);
        self::assertSame(0, $this->paymentCountForTx($idokladTx));
    }

    // ── Převzetí sloučené úhrady (split, migrace 0119) ───────────────────────

    public function testTakeOverSplitMatch(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        $invB = $this->insertInvoice(self::VS_B, 500.00);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 1500.00, self::VS_A, 'email');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->payments->recordPayment($invB, 500.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'manual');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1500.00, self::VS_A, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNotNull($result);
        // Obě platby přepojené na GPC tx.
        self::assertSame(2, $this->paymentCountForTx($gpcTx));
        self::assertSame(0, $this->paymentCountForTx($emailTx));
        self::assertSame('paid', $this->invoiceStatus($invA));
        self::assertSame('paid', $this->invoiceStatus($invB));
    }

    // ── Bezpečnostní brzdy ───────────────────────────────────────────────────

    public function testAmbiguousCandidatesSkipped(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [, $emailTx1] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email1');
        [, $emailTx2] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email2');
        // Dvě identické platby (dvojí avízo) — obě spárované s reálnou platbou na invA
        // (UNIQUE(bank_tx, invoice) povolí 2 řádky, různé bank_transaction_id).
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx1, 'created_by' => $this->userId,
        ]);
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx2, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx1, $invA, 'manual');
        $this->markTxMatched($emailTx2, $invA, 'manual');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNull($result, 'Při >1 kandidátovi se nic nepřevezme.');
        self::assertSame(1, $this->paymentCountForTx($emailTx1), 'Platba zůstává na avízu.');
        self::assertSame(0, $this->paymentCountForTx($gpcTx));
    }

    /** Tenant brzda: dvojník, jehož platba patří JINÉMU supplierovi, se nepřevezme. */
    public function testForeignSupplierTwinNotTakenOver(): void
    {
        $otherSupplier = (int) ($this->db->pdo()->query(
            "SELECT id FROM supplier WHERE id <> {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($otherSupplier === 0) {
            $this->markTestSkipped('Jen jeden supplier — tenant scope nelze ověřit.');
        }

        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'manual');
        // Přepiš vlastnictví platby na jiného existujícího supplierа → mimo scope GPC účtu.
        $this->db->pdo()->prepare(
            "UPDATE invoice_payments SET supplier_id = ? WHERE bank_transaction_id = ?"
        )->execute([$otherSupplier, $emailTx]);

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNull($result, 'Dvojník patřící jinému supplierovi se nepřevezme.');
        self::assertSame(0, $this->paymentCountForTx($gpcTx));
    }

    /** Bez rozpoznaného supplierа (cizí účet) se nic nepřebírá. */
    public function testUnknownAccountNotTakenOver(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'manual');

        // GPC výpis na účtu, který nepatří žádnému supplierovi → resolveSupplierId = 0.
        $pdo = $this->db->pdo();
        $d = '2099-06-15';
        $pdo->prepare(
            "INSERT INTO bank_statements (source, file_name, file_hash, account_number, currency, statement_date)
             VALUES ('gpc', ?, ?, '999000999000', 'CZK', ?)"
        )->execute([self::FILE_MARKER . 'unknown.gpc', hash('sha256', self::FILE_MARKER . 'unknown'), $d]);
        $unknownStmt = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, source, posted_at, amount, currency, variable_symbol)
             VALUES (?, 'statement', ?, 1000.00, 'CZK', ?)"
        )->execute([$unknownStmt, $d, self::VS_A]);
        $gpcTx = (int) $pdo->lastInsertId();

        self::assertNull($this->reconciler->takeOverFromEmailNotice($gpcTx));
    }

    /** Daňový doklad k platbě (proforma): repoint zachová tax_document_invoice_id, nevznikne 2. */
    public function testTakeOverPreservesTaxDocumentLink(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        $invTaxDoc = $this->insertInvoice(self::VS_B, 1000.00); // placeholder „daňový doklad"
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email');
        $r = $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        // Naváž daňový doklad na platbu (jako PaymentTaxDocumentCreator).
        $this->db->pdo()->prepare(
            'UPDATE invoice_payments SET tax_document_invoice_id = ? WHERE id = ?'
        )->execute([$invTaxDoc, $r['payment_id']]);
        $this->markTxMatched($emailTx, $invA, 'auto_partial');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc');
        self::assertNotNull($this->reconciler->takeOverFromEmailNotice($gpcTx));

        // Platba je teď na GPC tx a stále nese vazbu na daňový doklad (žádný 2. doklad).
        $link = $this->db->pdo()->query(
            "SELECT tax_document_invoice_id FROM invoice_payments WHERE bank_transaction_id = $gpcTx"
        )->fetchColumn();
        self::assertSame($invTaxDoc, (int) $link);
    }

    public function testUnmatchedTwinNotTakenOver(): void
    {
        $this->insertInvoice(self::VS_A, 1000.00);
        // Avízo dorazilo, ale nespárovalo se (match_status zůstal unmatched).
        $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email');

        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_A, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNull($result, 'Nespárované avízo se nepřebírá.');
    }

    public function testVsMismatchNotTakenOver(): void
    {
        $invA = $this->insertInvoice(self::VS_A, 1000.00);
        [, $emailTx] = $this->insertStatementWithTx('email_notice', 1000.00, self::VS_A, 'email');
        $this->payments->recordPayment($invA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $emailTx, 'created_by' => $this->userId,
        ]);
        $this->markTxMatched($emailTx, $invA, 'manual');

        // GPC tx má JINÝ VS → není to tatáž platba.
        [, $gpcTx] = $this->insertStatementWithTx('gpc', 1000.00, self::VS_B, 'gpc');
        $result = $this->reconciler->takeOverFromEmailNotice($gpcTx);

        self::assertNull($result, 'Rozdílný VS nesmí převzít cizí párování.');
        self::assertSame(1, $this->paymentCountForTx($emailTx));
    }
}

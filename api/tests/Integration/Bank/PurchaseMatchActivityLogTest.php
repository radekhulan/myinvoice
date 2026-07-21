<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regrese: ODCHOZÍ platba spárovaná s přijatou fakturou musí
 *   1) vrátit purchase_invoice_id se status auto_exact a fakturu označit jako paid,
 *   2) zapsat aktivitu `purchase_invoice.payment_matched` proti dokladu, ať je
 *      auto-úhrada vidět v těle faktury (dřív matcher neměl logger → žádný log).
 *
 * Souvisí s opravou e-mailových avíz (BankEmailNoticeScanner), kde se úhrada přijaté
 * faktury chybně hlásila jako match_failed (čteno jen invoice_id, ne purchase_invoice_id).
 *
 * Izolace: rok 2099, vlastní statement/transakce/faktura + úklid v tearDown.
 */
#[Group('integration')]
final class PurchaseMatchActivityLogTest extends TestCase
{
    private Connection $db;
    private StatementMatcher $matcher;
    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;

    private int $purchaseId = 0;
    private int $statementId = 0;
    private int $transactionId = 0;

    private const FILE_MARKER = '__purchmatch2099__';
    private const TEST_VS = '2099000260';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            // ActivityLogger injektován → ověřujeme zápis do activity_log; mailer/payments null.
            $this->matcher = new StatementMatcher(
                $this->db,
                $c->get(FinalFromProformaCreator::class),
                null,
                null,
                null,
                $c->get(ActivityLogger::class),
            );
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

        $this->vendorId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vendorId === 0 || $this->userId === 0) {
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
        // Aktivitu navázanou na testovací přijaté faktury (dle markeru) smaž nejdřív.
        $pdo->prepare(
            "DELETE al FROM activity_log al
               JOIN purchase_invoices pi ON pi.id = al.entity_id
              WHERE al.entity_type = 'purchase_invoice' AND pi.vendor_invoice_number = ?"
        )->execute([self::TEST_VS]);
        // Statementy (cascade → transakce + payment_matches), pak faktury.
        $pdo->prepare("DELETE FROM bank_statements WHERE file_name LIKE ?")->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare("DELETE FROM payment_matches WHERE supplier_id = ? AND purchase_invoice_id IN (SELECT id FROM purchase_invoices WHERE vendor_invoice_number = ?)")
            ->execute([$this->supplierId, self::TEST_VS]);
        $pdo->prepare("DELETE FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ?")
            ->execute([$this->supplierId, self::TEST_VS]);
        $this->purchaseId = $this->statementId = $this->transactionId = 0;
    }

    /**
     * @param 'received'|'booked'|'paid' $status Stav přijaté faktury
     * @param ?string $txVs VS na bankovní transakci (null = karetní platba bez VS)
     */
    private function seed(float $amount, string $status = 'received', ?string $txVs = self::TEST_VS): void
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';

        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot,
                 total_without_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, 'invoice', ?, ?, ?, ?, ?, '{}', ?, ?, ?, ?)"
        )->execute([
            $this->supplierId, $this->vendorId, self::TEST_VS, self::TEST_VS,
            $d, $d, $d, $d, $this->currencyId, $amount, $amount, $status, $this->userId,
        ]);
        $this->purchaseId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([
            self::FILE_MARKER . '.gpc',
            hash('sha256', self::FILE_MARKER . self::TEST_VS . $status . ($txVs ?? 'novs')),
            $this->account, $this->bankCode, $d,
        ]);
        $this->statementId = (int) $pdo->lastInsertId();

        // ODCHOZÍ platba = záporná částka → matcher routuje na přijatou fakturu.
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, 'CZK', ?)"
        )->execute([$this->statementId, $d, -$amount, $txVs]);
        $this->transactionId = (int) $pdo->lastInsertId();
    }

    public function testOutgoingPaymentMatchesPurchaseAndLogsActivity(): void
    {
        $this->seed(2500.00);

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('auto_exact', $res['status'] ?? null, 'Odchozí platba se musí spárovat s přijatou fakturou.');
        self::assertSame($this->purchaseId, $res['purchase_invoice_id'] ?? null);
        self::assertArrayNotHasKey('invoice_id', $res, 'Přijatá faktura nesmí vracet invoice_id (jen purchase_invoice_id).');

        $status = $this->db->pdo()->query("SELECT status FROM purchase_invoices WHERE id = {$this->purchaseId}")->fetchColumn();
        self::assertSame('paid', $status, 'Spárovaná přijatá faktura má být zaplacená.');

        $logCount = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log
              WHERE entity_type = 'purchase_invoice' AND entity_id = {$this->purchaseId}
                AND action = 'purchase_invoice.payment_matched'"
        )->fetchColumn();
        self::assertSame(1, $logCount, 'Auto-spárování platby musí zapsat aktivitu purchase_invoice.payment_matched.');
    }

    public function testOutgoingCardPaymentMatchesPaidPurchaseByAmountAndDate(): void
    {
        // Karetní platba (BEZ VS) k faktuře, která je už PAID — fuzzy ji vynechá (jen
        // received/booked), takže dřív zůstala unmatched, i když ruční nabídka kandidátů
        // ji podle částky+data našla. Nová amount+date záchrana (zahrnuje paid, právě jeden
        // kandidát) ji musí spárovat automaticky — bez změny statusu faktury.
        $this->seed(2500.00, 'paid', null);

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('auto_partial', $res['status'] ?? null, 'Karetní platba k paid faktuře se musí spárovat dle částky+data.');
        self::assertSame($this->purchaseId, $res['purchase_invoice_id'] ?? null);
        self::assertTrue($res['amount_date'] ?? false, 'Match má proběhnout přes amount+date záchranu.');

        // Vazba je zapsaná do payment_matches; status paid faktury zůstává.
        $pmCount = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId} AND purchase_invoice_id = {$this->purchaseId}"
        )->fetchColumn();
        self::assertSame(1, $pmCount, 'Musí vzniknout jeden payment_matches záznam.');
        self::assertSame('paid', $this->db->pdo()->query("SELECT status FROM purchase_invoices WHERE id = {$this->purchaseId}")->fetchColumn());
    }

    public function testAmbiguousAmountDateStaysUnmatched(): void
    {
        // Dvě přijaté faktury stejné částky+data bez VS na platbě → nejednoznačné,
        // amount+date záchrana radši nechá unmatched (ať nespáruje špatnou).
        $this->seed(2500.00, 'paid', null);
        $pdo = $this->db->pdo();
        // Druhá faktura stejné částky ve stejném okně (jiné vendor_invoice_number kvůli unikátu).
        $secondVno = self::TEST_VS . '-B';
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot,
                 total_without_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, 'invoice', '2099-06-15','2099-06-15','2099-06-15','2099-06-15', ?, '{}', ?, ?, 'paid', ?)"
        )->execute([
            $this->supplierId, $this->vendorId, $secondVno, $secondVno,
            $this->currencyId, 2500.00, 2500.00, $this->userId,
        ]);
        $secondId = (int) $pdo->lastInsertId();

        try {
            $res = $this->matcher->match($this->transactionId);
            self::assertSame('unmatched', $res['status'] ?? null, 'Dvojznačná shoda se nesmí automaticky spárovat.');
            self::assertSame('ambiguous_amount_date_match', $res['reason'] ?? null);
        } finally {
            $pdo->prepare("DELETE FROM payment_matches WHERE purchase_invoice_id = ?")->execute([$secondId]);
            $pdo->prepare("DELETE FROM purchase_invoices WHERE id = ?")->execute([$secondId]);
        }
    }
}

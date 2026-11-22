<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Currency;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Audit 2026-07 (fix 4): kurz ČNB u VYSTAVENÝCH faktur se musí zjistit k datu
 * uskutečnění zdanitelného plnění (DUZP = tax_date), ne k datu vystavení
 * (issue_date). § 4 odst. 8 ZDPH — kurz platný ke dni vzniku daňové
 * povinnosti. DUZP a vystavení se běžně liší (§ 28 odst. 8 dává až 15 dní).
 *
 * Soft-skip bez cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class ExchangeRateApplierDuzpTest extends TestCase
{
    private Connection $db;
    private InvoiceRepository $invoices;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $eurId = 0;
    private int $clientId = 0;
    /** @var int[] */
    private array $invoiceIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->invoices = $c->get(InvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user/country).');
        }
        $this->eurId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->eurId === 0) {
            $pdo->exec("INSERT INTO currencies (code, name) VALUES ('EUR', 'Euro')");
            $this->eurId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_customer)
             VALUES (?, "Kurz odběratel", "Test 1", "Praha", "11000", ?, "t@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $czId, $this->eurId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        if ($this->clientId) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    public function testAppliesRateAtDuzpNotIssueDate(): void
    {
        $pdo = $this->db->pdo();
        // DUZP 31.1. (konec měsíce), vystaveno až 5.2. (v rámci lhůty § 28/8) — kurz
        // musí být k DUZP (31.1.), ne k datu vystavení (5.2.).
        $taxDate = '2099-01-31';
        $issueDate = '2099-02-05';
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, "2099KURZ01", "invoice", ?, ?, ?, ?, ?, 0, 1000, 0, 1000, "issued", NULL, ?)'
        )->execute([$this->supplierId, $this->clientId, $issueDate, $taxDate, $issueDate, $this->eurId, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $id;

        // Zachyť datum, se kterým se ptáme ČNB na kurz.
        $captured = null;
        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRate')->willReturnCallback(
            function (string $code, \DateTimeImmutable $date) use (&$captured): array {
                $captured = $date->format('Y-m-d');
                return ['rate' => 25.0, 'rate_date' => $date->format('Y-m-d'), 'fallback_used' => false, 'source' => 'fresh'];
            }
        );

        (new ExchangeRateApplier($this->db, $this->invoices, $cnb))->applyToInvoice($id);

        $this->assertSame($taxDate, $captured,
            'Kurz ČNB se musí zjistit k DUZP (tax_date), ne k datu vystavení (issue_date)');
    }

    public function testManualRateIsStoredAtDuzp(): void
    {
        $pdo = $this->db->pdo();
        $taxDate = '2099-01-31';
        $issueDate = '2099-02-05';
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, "2099KURZ02", "invoice", ?, ?, ?, ?, ?, 0, 1000, 0, 1000, "draft", NULL, ?)'
        )->execute([$this->supplierId, $this->clientId, $issueDate, $taxDate, $issueDate, $this->eurId, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $id;

        $cnb = $this->createMock(CnbExchangeRateClient::class);
        $cnb->expects($this->never())->method('getRate');

        $stored = (new ExchangeRateApplier($this->db, $this->invoices, $cnb))->applyManualRate($id, 24.9999);

        $this->assertTrue($stored);
        $row = $pdo->query('SELECT exchange_rate, exchange_rate_date FROM invoices WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);
        $this->assertEqualsWithDelta(24.9999, (float) $row['exchange_rate'], 1e-9);
        $this->assertSame($taxDate, substr((string) $row['exchange_rate_date'], 0, 10),
            'Ruční kurz se ukládá k DUZP, ne k datu vystavení');
    }
}

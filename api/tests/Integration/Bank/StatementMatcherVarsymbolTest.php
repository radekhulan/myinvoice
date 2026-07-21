<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end ověření normalizace VS při párování (issue #58): faktura uložená s číslem
 * dokladu obsahujícím pomlčku („2099-00042") se spáruje s bankovní transakcí, která nese
 * jen číslice („209900042") — banka pomlčku nepřenese. Numerická větev WHERE v matcheru
 * (CAST(REGEXP_REPLACE(...)) ) to musí dorovnat.
 *
 * Izolace: data v roce 2099 (nekříží se s reálnými), vlastní bank_statement +
 * bank_transaction + invoice, vše se v tearDown smaže. Mailer záměrně null (žádné
 * vedlejší e-maily). Soft-skip bez cfg.php / DB / vhodného supplieru s účtem.
 */
#[Group('integration')]
final class StatementMatcherVarsymbolTest extends TestCase
{
    private Connection $db;
    private StatementMatcher $matcher;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;
    private \DateTimeImmutable $date;

    private int $invoiceId = 0;
    private int $statementId = 0;
    private int $transactionId = 0;

    private const FILE_MARKER = '__vs58_test__';
    /** Konkrétní VS používané testy — deterministický úklid (i po spadnutém běhu). */
    private const TEST_VARSYMBOLS = ['2099-00042', '2099-00077', '2099000099', '2099000123', '2099000260', '2099000261'];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            // Mailer null: párování nesmí v testu posílat reálné e-maily.
            $this->matcher = new StatementMatcher($this->db, $c->get(FinalFromProformaCreator::class), null);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        // CZK currency s vyplněným účtem → určuje supplier_id při párování (recipient_account).
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

        $this->date = new \DateTimeImmutable('2099-06-15');
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
        // Smaž testovací výpisy (cascade smaže i jejich transakce) a faktury podle markeru.
        $pdo->prepare("DELETE FROM bank_statements WHERE file_name LIKE ?")
            ->execute(['%' . self::FILE_MARKER . '%']);
        $placeholders = implode(',', array_fill(0, count(self::TEST_VARSYMBOLS), '?'));
        // Nejdřív finály (parent_invoice_id != NULL), pak zbytek — kvůli FK na parent.
        $pdo->prepare("DELETE FROM invoices WHERE supplier_id = ? AND varsymbol IN ($placeholders) AND parent_invoice_id IS NOT NULL")
            ->execute([$this->supplierId, ...self::TEST_VARSYMBOLS]);
        $pdo->prepare("DELETE FROM invoices WHERE supplier_id = ? AND varsymbol IN ($placeholders)")
            ->execute([$this->supplierId, ...self::TEST_VARSYMBOLS]);
        $this->invoiceId = $this->statementId = $this->transactionId = 0;
    }

    /**
     * @param numeric-string $varsymbol
     */
    private function seed(string $varsymbol, string $txVs, float $amount, string $status = 'issued', float $paidTotal = 0.0): void
    {
        $pdo = $this->db->pdo();
        $d = $this->date->format('Y-m-d');

        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $varsymbol, $this->clientId, $this->supplierId, $d, $d, $d,
            $this->currencyId, $status, $amount, $amount, $paidTotal, $this->userId,
        ]);
        $this->invoiceId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([
            self::FILE_MARKER . $varsymbol . '.gpc',
            hash('sha256', self::FILE_MARKER . $varsymbol . $txVs),
            $this->account, $this->bankCode, $d,
        ]);
        $this->statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, 'CZK', ?)"
        )->execute([$this->statementId, $d, $amount, $txVs]);
        $this->transactionId = (int) $pdo->lastInsertId();
    }

    public function testDashedInvoiceVsMatchesNumericBankVs(): void
    {
        // Faktura „2099-00042", banka hlásí „209900042" (bez pomlčky).
        $this->seed('2099-00042', '209900042', 1234.50);

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('auto_exact', $res['status'] ?? null, 'VS s pomlčkou se musí spárovat na číselný VS z banky.');
        self::assertSame($this->invoiceId, $res['invoice_id'] ?? null);

        $status = $this->db->pdo()->query("SELECT status FROM invoices WHERE id = {$this->invoiceId}")->fetchColumn();
        self::assertSame('paid', $status, 'Spárovaná faktura má být označená jako zaplacená.');
    }

    public function testLeadingZeroVsStillMatches(): void
    {
        // Faktura uložená s vodicími nulami, banka je ořízne — numerická shoda to dorovná.
        $this->seed('2099-00077', '209900077', 555.00);

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('auto_exact', $res['status'] ?? null);
        self::assertSame($this->invoiceId, $res['invoice_id'] ?? null);
    }

    public function testCleanNumericVsUnaffected(): void
    {
        // Regrese: čistě číselný VS (žádná pomlčka) se páruje přesnou shodou jako dřív.
        $this->seed('2099000099', '2099000099', 42.00);

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('auto_exact', $res['status'] ?? null);
        self::assertSame($this->invoiceId, $res['invoice_id'] ?? null);
    }

    public function testAlreadyPaidInvoiceStillLinks(): void
    {
        // Regrese: faktura je už zaplacená (status paid, paid_total = celá částka → remaining 0).
        // Dřív matcher porovnával platbu proti zbytku (0) a plnou platbu nikdy nespároval →
        // paid faktura visela ve výpisu jako unmatched. Teď se transakce naváže (auto_exact,
        // already_paid) a status/paid_at zůstane netknutý.
        $this->seed('2099000123', '2099000123', 999.00, 'paid', 999.00);

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('auto_exact', $res['status'] ?? null, 'Zaplacená faktura se musí navázat na platbu z výpisu.');
        self::assertSame($this->invoiceId, $res['invoice_id'] ?? null);
        self::assertTrue($res['already_paid'] ?? false, 'Match zaplacené faktury má nést příznak already_paid.');

        $pdo = $this->db->pdo();
        // Status ani paid_total se u ručně/dřív zaplacené faktury nemění.
        self::assertSame('paid', $pdo->query("SELECT status FROM invoices WHERE id = {$this->invoiceId}")->fetchColumn());
        // Transakce je navázaná na fakturu.
        self::assertSame(
            $this->invoiceId,
            (int) $pdo->query("SELECT matched_invoice_id FROM bank_transactions WHERE id = {$this->transactionId}")->fetchColumn()
        );
    }

    public function testPaidProformaWithSettledFinalStillLinks(): void
    {
        // Uhrazená ZÁLOHOVÁ faktura (proforma paid, paid_total = plná částka) s vystaveným
        // finálem, který je taky vyrovnaný (amount_to_pay=0, paid_total=0 — pohledávku i
        // platbu drží proforma). Matcher dřív platbu přesměroval na finál a porovnával ji
        // proti nule → záloha se nikdy nespárovala. Teď zůstane na proformě a naváže ji.
        $pdo = $this->db->pdo();
        $d = $this->date->format('Y-m-d');
        $amount = 45000.00;

        // Proforma (paid, plně uhrazená).
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('proforma', '2099000260', ?, ?, ?, ?, ?, ?, 'paid', ?, ?, ?, ?)"
        )->execute([
            $this->clientId, $this->supplierId, $d, $d, $d, $this->currencyId,
            $amount, $amount, $amount, $this->userId,
        ]);
        $proformaId = (int) $pdo->lastInsertId();
        $this->invoiceId = $proformaId;

        // Finál z proformy — vyrovnaný, bez zbývající pohledávky.
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, parent_invoice_id, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, advance_paid_amount, paid_total, created_by)
             VALUES ('invoice', ?, '2099000261', ?, ?, ?, ?, ?, ?, 'paid', ?, ?, ?, 0, ?)"
        )->execute([
            $proformaId, $this->clientId, $this->supplierId, $d, $d, $d, $this->currencyId,
            $amount, $amount, $amount, $this->userId,
        ]);

        $pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([
            self::FILE_MARKER . 'proforma.gpc',
            hash('sha256', self::FILE_MARKER . '2099000260proforma'),
            $this->account, $this->bankCode, $d,
        ]);
        $this->statementId = (int) $pdo->lastInsertId();

        // Příchozí platba (kladná) pod VS proformy, plná částka.
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, 'CZK', '2099000260')"
        )->execute([$this->statementId, $d, $amount]);
        $this->transactionId = (int) $pdo->lastInsertId();

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('auto_exact', $res['status'] ?? null, 'Uhrazená záloha se musí navázat na platbu z výpisu.');
        self::assertSame($proformaId, $res['invoice_id'] ?? null, 'Platba se má navázat na proformu, ne na vyrovnaný finál.');
        self::assertTrue($res['already_paid'] ?? false);
        self::assertSame(
            $proformaId,
            (int) $pdo->query("SELECT matched_invoice_id FROM bank_transactions WHERE id = {$this->transactionId}")->fetchColumn()
        );
    }

    public function testWrongVsDoesNotMatch(): void
    {
        // Jiný VS (po normalizaci nesedí) → nesmí se spárovat na naši fakturu.
        $this->seed('2099-00042', '209900999', 1234.50);

        $res = $this->matcher->match($this->transactionId);

        self::assertNotSame('auto_exact', $res['status'] ?? null);
        self::assertNotSame($this->invoiceId, $res['invoice_id'] ?? null);
    }
}

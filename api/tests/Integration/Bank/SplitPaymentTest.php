<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Sloučená úhrada: jedna příchozí bankovní transakce uhradí VÍCE vystavených faktur
 * (klient zaplatil 2 faktury jednou platbou). Ověřuje datový model (migrace 0119),
 * service vrstvu (InvoicePaymentService) i Action guardy (manualMatchSplit).
 *
 * Izolace: rok 2099, vlastní statement+transakce+faktury+2. klient, vše se v tearDown
 * smaže. Soft-skip bez cfg.php / DB / vhodného supplieru.
 */
#[Group('integration')]
final class SplitPaymentTest extends TestCase
{
    private Connection $db;
    private InvoicePaymentService $payments;
    private BankStatementAction $action;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $client2Id = 0;
    private int $currencyId = 0;
    private int $countryId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;

    private int $invoiceA = 0; // 1000, klient 1
    private int $invoiceB = 0; // 500,  klient 1
    private int $invoiceC = 0; // 999,  klient 1 (na test nesedícího součtu)
    private int $invoiceD = 0; // 500,  klient 2 (na test smíchání klientů)
    private int $statementId = 0;
    private int $transactionId = 0;

    private const FILE_MARKER = '__split_test__';
    private const CLIENT2_MARKER = '__split_test_client2__';
    private const VS_A = '2099-50001';
    private const VS_B = '2099-50002';
    private const VS_C = '2099-50003';
    private const VS_D = '2099-50004';
    private const ALL_VS = [self::VS_A, self::VS_B, self::VS_C, self::VS_D];

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
            $this->action = $c->get(BankStatementAction::class);
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

        $primary = $pdo->query("SELECT id, country_id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->clientId = (int) ($primary['id'] ?? 0);
        $this->countryId = (int) ($primary['country_id'] ?? 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->clientId === 0 || $this->userId === 0 || $this->countryId === 0) {
            $this->markTestSkipped('Chybí client/user/country pro supplier.');
        }

        $this->cleanup();
        $this->seed();
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
        // Platby (FK) → výpisy (cascade tx) → faktury → druhý klient.
        $pdo->prepare(
            "DELETE p FROM invoice_payments p
               JOIN invoices i ON i.id = p.invoice_id
              WHERE i.supplier_id = ? AND i.varsymbol IN ($place)"
        )->execute([$this->supplierId, ...self::ALL_VS]);
        $pdo->prepare("DELETE FROM bank_statements WHERE file_name LIKE ?")
            ->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare("DELETE FROM invoices WHERE supplier_id = ? AND varsymbol IN ($place)")
            ->execute([$this->supplierId, ...self::ALL_VS]);
        $pdo->prepare("DELETE FROM clients WHERE supplier_id = ? AND company_name = ?")
            ->execute([$this->supplierId, self::CLIENT2_MARKER]);
        $this->invoiceA = $this->invoiceB = $this->invoiceC = $this->invoiceD = 0;
        $this->statementId = $this->transactionId = $this->client2Id = 0;
    }

    private function insertInvoice(string $vs, float $amount, int $clientId): int
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, 0, ?)"
        )->execute([
            $vs, $clientId, $this->supplierId, $d, $d, $d,
            $this->currencyId, $amount, $amount, $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function seed(): void
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';

        // Druhý klient (pro test smíchání klientů).
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 9", "Praha", "11000", ?, "c2@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, self::CLIENT2_MARKER, $this->countryId, $this->currencyId]);
        $this->client2Id = (int) $pdo->lastInsertId();

        // Faktury: A 1000 + B 500 = 1500 (jedna platba); C 999 (mismatch), D 500 (jiný klient).
        $this->invoiceA = $this->insertInvoice(self::VS_A, 1000.00, $this->clientId);
        $this->invoiceB = $this->insertInvoice(self::VS_B, 500.00, $this->clientId);
        $this->invoiceC = $this->insertInvoice(self::VS_C, 999.00, $this->clientId);
        $this->invoiceD = $this->insertInvoice(self::VS_D, 500.00, $this->client2Id);

        $pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([
            self::FILE_MARKER . '.gpc',
            hash('sha256', self::FILE_MARKER . $d),
            $this->account, $this->bankCode, $d,
        ]);
        $this->statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, 1500.00, 'CZK', '0')"
        )->execute([$this->statementId, $d]);
        $this->transactionId = (int) $pdo->lastInsertId();
    }

    private function invoiceStatus(int $invoiceId): string
    {
        return (string) $this->db->pdo()
            ->query("SELECT status FROM invoices WHERE id = $invoiceId")->fetchColumn();
    }

    /** @param array<string,mixed> $body @return array{status:int, body:array<string,mixed>} */
    private function callMatch(array $body): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $this->transactionId . '/match')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $resp = $this->action->manualMatch($req, new Psr7Response(), ['id' => (string) $this->transactionId]);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true) ?: [];
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @param array<string,mixed> $query @return array<int,array<string,mixed>> seznam návrhů */
    private function callSuggestions(array $query): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/bank-transactions/' . $this->transactionId . '/split-suggestions')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withQueryParams($query);
        $resp = $this->action->splitSuggestions($req, new Psr7Response(), ['id' => (string) $this->transactionId]);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true) ?: [];
        return $decoded['suggestions'] ?? [];
    }

    /** @param array<string,mixed> $s @return list<int> id faktur v návrhu */
    private static function invoiceIdsOf(array $s): array
    {
        return array_map(static fn ($i) => (int) $i['id'], $s['invoices'] ?? []);
    }

    // ── Service-level (datový model + migrace 0119) ──────────────────────────

    public function testSplitPaymentMarksBothInvoicesPaid(): void
    {
        $rA = $this->payments->recordPayment($this->invoiceA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $this->transactionId, 'created_by' => $this->userId,
        ]);
        $rB = $this->payments->recordPayment($this->invoiceB, 500.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $this->transactionId, 'created_by' => $this->userId,
        ]);

        self::assertTrue($rA['became_paid']);
        self::assertTrue($rB['became_paid']);
        self::assertSame('paid', $this->invoiceStatus($this->invoiceA));
        self::assertSame('paid', $this->invoiceStatus($this->invoiceB));

        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn();
        self::assertSame(2, $count, 'Sloučená úhrada má založit 2 platby na 1 transakci.');
    }

    public function testUnmatchDeletesBothPaymentsAndReverts(): void
    {
        $this->payments->recordPayment($this->invoiceA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $this->transactionId, 'created_by' => $this->userId,
        ]);
        $this->payments->recordPayment($this->invoiceB, 500.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $this->transactionId, 'created_by' => $this->userId,
        ]);

        $deleted = $this->payments->deleteForBankTransaction($this->transactionId);
        self::assertTrue($deleted);

        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn();
        self::assertSame(0, $count);
        self::assertSame('issued', $this->invoiceStatus($this->invoiceA));
        self::assertSame('issued', $this->invoiceStatus($this->invoiceB));
        $paidA = (float) $this->db->pdo()->query("SELECT paid_total FROM invoices WHERE id = {$this->invoiceA}")->fetchColumn();
        self::assertSame(0.0, $paidA);
    }

    public function testSamePairTwiceRejectedByUniqueIndex(): void
    {
        $this->payments->recordPayment($this->invoiceA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $this->transactionId, 'created_by' => $this->userId,
        ]);
        $this->expectException(\PDOException::class);
        $this->payments->recordPayment($this->invoiceA, 1000.00, '2099-06-15', [
            'source' => 'bank', 'bank_transaction_id' => $this->transactionId, 'created_by' => $this->userId,
        ]);
    }

    // ── Action-level (guardy manualMatchSplit) ───────────────────────────────

    public function testActionSplitHappyPath(): void
    {
        // A (1000) + B (500) = 1500 = částka platby → obě paid, 2 platby.
        $res = $this->callMatch(['invoice_ids' => [$this->invoiceA, $this->invoiceB]]);

        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertTrue($res['body']['split'] ?? false);
        self::assertSame('paid', $this->invoiceStatus($this->invoiceA));
        self::assertSame('paid', $this->invoiceStatus($this->invoiceB));

        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn();
        self::assertSame(2, $count);

        // matched_invoice_id ukazuje na první fakturu (kompat. UI/unmatch).
        $matched = (int) $this->db->pdo()->query(
            "SELECT matched_invoice_id FROM bank_transactions WHERE id = {$this->transactionId}"
        )->fetchColumn();
        self::assertSame($this->invoiceA, $matched);
    }

    public function testActionSumMismatchRejected(): void
    {
        // A (1000) + C (999) = 1999 ≠ 1500 → 409, žádné platby.
        $res = $this->callMatch(['invoice_ids' => [$this->invoiceA, $this->invoiceC]]);

        self::assertSame(409, $res['status']);
        self::assertSame('sum_mismatch', $res['body']['error']['code'] ?? null);
        self::assertSame('issued', $this->invoiceStatus($this->invoiceA));
        self::assertSame('issued', $this->invoiceStatus($this->invoiceC));
        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn();
        self::assertSame(0, $count, 'Při nesedícím součtu nesmí vzniknout žádná platba.');
    }

    public function testActionClientMismatchRejected(): void
    {
        // A (klient 1) + D (klient 2), součet 1500 sedí, ale různí klienti → 409.
        $res = $this->callMatch(['invoice_ids' => [$this->invoiceA, $this->invoiceD]]);

        self::assertSame(409, $res['status']);
        self::assertSame('client_mismatch', $res['body']['error']['code'] ?? null);
        self::assertSame('issued', $this->invoiceStatus($this->invoiceA));
        self::assertSame('issued', $this->invoiceStatus($this->invoiceD));
        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn();
        self::assertSame(0, $count);
    }

    // ── Našeptávač (splitSuggestions) ────────────────────────────────────────

    public function testSuggestionsFindCombination(): void
    {
        // Pro platbu 1500 musí najít kombinaci A(1000)+B(500), klient 1.
        $suggestions = $this->callSuggestions([]);
        self::assertNotEmpty($suggestions, 'Měla by existovat aspoň jedna kombinace.');

        $hasAB = false;
        foreach ($suggestions as $s) {
            $ids = self::invoiceIdsOf($s);
            sort($ids);
            $expected = [$this->invoiceA, $this->invoiceB];
            sort($expected);
            if ($ids === $expected) {
                $hasAB = true;
                self::assertSame($this->clientId, (int) $s['client_id']);
                self::assertEqualsWithDelta(1500.0, (float) $s['total'], 1.0);
            }
        }
        self::assertTrue($hasAB, 'Kombinace A+B musí být mezi návrhy.');

        // Faktura D (jiný klient) se nesmí objevit v žádném návrhu (kombinace jen 1 klient).
        foreach ($suggestions as $s) {
            self::assertNotContains($this->invoiceD, self::invoiceIdsOf($s));
        }
    }

    public function testSuggestionsAnchorRestrictsToInvoice(): void
    {
        // Kotva = faktura A → všechny návrhy ji musí obsahovat.
        $suggestions = $this->callSuggestions(['invoice_id' => $this->invoiceA]);
        self::assertNotEmpty($suggestions);
        foreach ($suggestions as $s) {
            self::assertContains($this->invoiceA, self::invoiceIdsOf($s), 'Každý návrh musí obsahovat kotvu.');
        }
    }

    // ── Rekonciliace ZAPLACENÝCH faktur (split nabízí i 'paid') ──────────────

    /** Dřívější (ne-bankovní) úhrada → faktura 'paid', platba bez bank_transaction_id. */
    private function markPaidLegacy(int $invoiceId, float $amount): int
    {
        $r = $this->payments->recordPayment($invoiceId, $amount, '2099-06-15', [
            'source' => 'manual', 'created_by' => $this->userId,
        ]);
        return (int) $r['payment_id'];
    }

    public function testActionSplitReconcilesAlreadyPaidInvoices(): void
    {
        // A (1000) i B (500) už zaplacené dřívější úhradou (mimo banku).
        $payA = $this->markPaidLegacy($this->invoiceA, 1000.00);
        $payB = $this->markPaidLegacy($this->invoiceB, 500.00);
        self::assertSame('paid', $this->invoiceStatus($this->invoiceA));

        // Sloučená úhrada na zaplacené faktury → rekonciliace (navázat existující platby).
        $res = $this->callMatch(['invoice_ids' => [$this->invoiceA, $this->invoiceB]]);
        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertTrue($res['body']['split'] ?? false);

        // Navázané jsou TYTÉŽ platby (ne nové) → shodná id, žádné přibyly.
        $ids = array_map('intval', $this->db->pdo()->query(
            "SELECT id FROM invoice_payments WHERE bank_transaction_id = {$this->transactionId} ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN));
        sort($ids);
        $expected = [$payA, $payB];
        sort($expected);
        self::assertSame($expected, $ids, 'Rekonciliace nesmí vytvořit nové platby, jen navázat existující.');

        // paid_total beze změny → žádné dvojí zdanění/přeplacení.
        $paidA = (float) $this->db->pdo()->query("SELECT paid_total FROM invoices WHERE id = {$this->invoiceA}")->fetchColumn();
        self::assertSame(1000.0, $paidA);
    }

    public function testActionSplitMixedPaidAndUnpaid(): void
    {
        // A zaplacená (legacy) → rekonciliace; B nezaplacená → nová bank platba.
        $payA = $this->markPaidLegacy($this->invoiceA, 1000.00);
        $res = $this->callMatch(['invoice_ids' => [$this->invoiceA, $this->invoiceB]]);

        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertSame('paid', $this->invoiceStatus($this->invoiceB));

        $rows = $this->db->pdo()->query(
            "SELECT invoice_id, source FROM invoice_payments
              WHERE bank_transaction_id = {$this->transactionId} ORDER BY invoice_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        $bySource = [];
        foreach ($rows as $r) {
            $bySource[(int) $r['invoice_id']] = (string) $r['source'];
        }
        self::assertSame('manual', $bySource[$this->invoiceA] ?? null, 'A = rekonciliovaná původní platba.');
        self::assertSame('bank', $bySource[$this->invoiceB] ?? null, 'B = nová bankovní platba.');
    }

    public function testUnmatchUnlinksReconciledPaymentsKeepsPaid(): void
    {
        $payA = $this->markPaidLegacy($this->invoiceA, 1000.00);
        $payB = $this->markPaidLegacy($this->invoiceB, 500.00);
        $this->callMatch(['invoice_ids' => [$this->invoiceA, $this->invoiceB]]);

        $deleted = $this->payments->deleteForBankTransaction($this->transactionId);
        self::assertTrue($deleted);

        // Rekonciliované platby se nesmí smazat — jen odpojit; faktury zůstávají 'paid'.
        $still = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE id IN ($payA, $payB)"
        )->fetchColumn();
        self::assertSame(2, $still, 'Rekonciliovanou platbu unmatch jen odpojí, nemaže.');
        $linked = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn();
        self::assertSame(0, $linked);
        self::assertSame('paid', $this->invoiceStatus($this->invoiceA));
        self::assertSame('paid', $this->invoiceStatus($this->invoiceB));
    }

    public function testSuggestionsIncludePaidForReconciliation(): void
    {
        $this->markPaidLegacy($this->invoiceA, 1000.00);
        $this->markPaidLegacy($this->invoiceB, 500.00);

        $suggestions = $this->callSuggestions([]);
        $hasAB = false;
        foreach ($suggestions as $s) {
            $ids = self::invoiceIdsOf($s);
            sort($ids);
            $expected = [$this->invoiceA, $this->invoiceB];
            sort($expected);
            if ($ids === $expected) {
                $hasAB = true;
                foreach ($s['invoices'] as $inv) {
                    self::assertTrue($inv['is_paid'] ?? false, 'Zaplacená faktura musí nést is_paid.');
                }
            }
        }
        self::assertTrue($hasAB, 'Návrh A+B musí být i pro zaplacené faktury (rekonciliace).');
    }
}

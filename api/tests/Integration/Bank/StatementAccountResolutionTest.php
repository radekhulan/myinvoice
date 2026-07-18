<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/**
 * Určení cílového bankovního účtu při GPC/ABO uploadu (#206).
 *
 * GPC hlavička 074 nese jen ČÍSLO účtu (kód banky vlastního účtu chybí; kód v 075
 * je banka protistrany). Když má dodavatel víc účtů se STEJNÝM číslem účtu, které
 * se liší jen kódem banky (např. Fio 1234567890/2010 a RB 1234567890/5500), nesmí
 * se výpis tiše přiřadit k prvnímu (typicky výchozímu) účtu — import musí skončit
 * 409 `ambiguous_account_currency` a vyžádat si ruční výběr. Po předání `account_id`
 * proběhne import pod zvoleným účtem.
 *
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class StatementAccountResolutionTest extends TestCase
{
    private Connection $db;
    private BankStatementAction $action;
    private int $supplierId = 0;
    private int $userId = 0;

    /** @var int[] */
    private array $currencyIds = [];
    /** @var int[] */
    private array $statementIds = [];
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container nedostupný.');
            }
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(BankStatementAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
        $this->userId = (int) ($this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->statementIds as $id) {
            $pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);
        }
        foreach ($this->currencyIds as $id) {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
        }
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) @unlink($f);
        }
        $this->db->close();
    }

    /**
     * #206: stejné číslo účtu u dvou bank (obojí CZK) → bez volby MUSÍ vrátit 409
     * a nabídnout kandidáty, ne tiše importovat pod první/výchozí variantou.
     */
    public function testSameAccountNumberDifferentBankRequiresChoice(): void
    {
        $account = '9912345678';
        // Výchozí RB účet je založen jako první → dřív by lookup vzal tuhle variantu.
        $this->registerCurrency('CZK', $account, '5500', isDefault: true);
        $this->registerCurrency('CZK', $account, '2010');

        [$resp, $body] = $this->upload($this->gpc($account));

        $this->assertSame(409, $resp->getStatusCode(), 'dvě banky se stejným číslem účtu musí být nejednoznačné');
        $this->assertSame('ambiguous_account_currency', $body['error']['code'] ?? null);
        $candidates = $body['error']['candidates'] ?? [];
        $this->assertCount(2, $candidates, 'oba účty musí být nabídnuty jako kandidáti');

        // Labely musí být odlišitelné — obsahují kód banky (obě varianty jsou CZK).
        $labels = array_map(static fn ($c) => (string) $c['label'], $candidates);
        $this->assertTrue(
            (bool) array_filter($labels, static fn ($l) => str_contains($l, '2010'))
            && (bool) array_filter($labels, static fn ($l) => str_contains($l, '5500')),
            'labely kandidátů musí rozlišit banky (2010 vs 5500), ne jen měnu'
        );

        // Žádný výpis se nesmí uložit.
        $this->assertSame(0, $this->countStatements($account), 'nejednoznačný upload nesmí nic importovat');
    }

    /**
     * S předaným `account_id` (volba Fio /2010) proběhne import pod zvoleným účtem
     * — statement.bank_code = 2010, ne výchozí 5500.
     */
    public function testExplicitAccountIdImportsUnderChosenBank(): void
    {
        $account = '9912345679';
        $this->registerCurrency('CZK', $account, '5500', isDefault: true);
        $fioId = $this->registerCurrency('CZK', $account, '2010');

        [$resp, $body] = $this->upload($this->gpc($account), accountId: $fioId);

        $this->assertSame(200, $resp->getStatusCode(), 's volbou účtu musí import projít: ' . json_encode($body));
        $sid = (int) ($body['statement_id'] ?? 0);
        $this->assertGreaterThan(0, $sid);
        $this->statementIds[] = $sid;

        $stmt = $this->db->pdo()->prepare('SELECT bank_code FROM bank_statements WHERE id = ?');
        $stmt->execute([$sid]);
        $this->assertSame('2010', (string) $stmt->fetchColumn(), 'výpis musí být pod zvoleným Fio účtem (2010), ne výchozím RB (5500)');

        // Regrese seznamu výpisů: list dříve přepsal správné bs.bank_code prvním
        // currencies.bank_code pro stejné číslo účtu (bez ORDER BY), takže zobrazil
        // např. /5500 společně s labelem Fio. Detail přitom správně ukazoval /2010.
        $listResponse = $this->action->list(
            $this->mockRequest($this->supplierId, 'admin', [], [], ['filter' => ['account' => $account]]),
            new Response()
        );
        /** @var array{items?:list<array<string,mixed>>} $listBody */
        $listBody = json_decode((string) $listResponse->getBody(), true) ?: [];
        $listed = array_values(array_filter(
            $listBody['items'] ?? [],
            static fn (array $item): bool => (int) ($item['id'] ?? 0) === $sid
        ));
        $this->assertCount(1, $listed, 'importovaný výpis musí být v seznamu');
        $this->assertSame('2010', (string) ($listed[0]['bank_code'] ?? ''), 'seznam musí respektovat autoritativní bank_code výpisu');
        $this->assertStringContainsString('2010', (string) ($listed[0]['account_label'] ?? ''), 'label seznamu musí patřit ke stejnému účtu');
    }

    /**
     * Jediný odpovídající účet → import proběhne automaticky (regrese #167/#206 nesmí
     * rozbít běžný jednoúčtový případ).
     */
    public function testSingleMatchingAccountImportsAutomatically(): void
    {
        $account = '9912345680';
        $this->registerCurrency('CZK', $account, '2010');

        [$resp, $body] = $this->upload($this->gpc($account));

        $this->assertSame(200, $resp->getStatusCode(), 'jediný účet musí projít bez volby: ' . json_encode($body));
        $sid = (int) ($body['statement_id'] ?? 0);
        $this->assertGreaterThan(0, $sid);
        $this->statementIds[] = $sid;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function registerCurrency(string $code, string $accountNumber, string $bankCode, bool $isDefault = false): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code, iban)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, ?, ?, ?, NULL)'
        )->execute([
            $this->supplierId, $code, "TEST {$code}/{$bankCode} #206", $code, $code, $code,
            $isDefault ? 1 : 0, $accountNumber, $bankCode,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->currencyIds[] = $id;
        return $id;
    }

    /**
     * Nahraje GPC obsah přes akci `upload` a vrátí [Response, decoded-body].
     *
     * @return array{0: Response, 1: array<string,mixed>}
     */
    private function upload(string $content, ?int $accountId = null): array
    {
        $file = $this->uploadedFile($content);
        $parsedBody = $accountId !== null ? ['account_id' => $accountId] : [];
        $req = $this->mockRequest($this->supplierId, 'admin', ['file' => $file], $parsedBody);
        $resp = $this->action->upload($req, new Response());
        /** @var array<string,mixed> $body */
        $body = json_decode((string) $resp->getBody(), true) ?: [];
        return [$resp, $body];
    }

    private function mockRequest(int $sid, string $role, array $files, array $parsedBody, array $queryParams = []): ServerRequestInterface
    {
        $req = $this->createStub(ServerRequestInterface::class);
        $req->method('getAttribute')->willReturnCallback(function (string $name, $default = null) use ($sid, $role) {
            if ($name === SupplierScopeMiddleware::ATTR_CURRENT_ID) return $sid;
            if ($name === AuthMiddleware::ATTR_USER) return ['id' => $this->userId, 'role' => $role];
            return $default;
        });
        $req->method('getUploadedFiles')->willReturn($files);
        $req->method('getParsedBody')->willReturn($parsedBody);
        $req->method('getQueryParams')->willReturn($queryParams);
        $req->method('getServerParams')->willReturn([]);
        $req->method('getHeaderLine')->willReturn('');
        return $req;
    }

    private function uploadedFile(string $content, string $name = 'vypis.gpc'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gpctest');
        file_put_contents($tmp, $content);
        $this->tmpFiles[] = $tmp;
        return new UploadedFile($tmp, $name, 'text/plain', strlen($content), UPLOAD_ERR_OK);
    }

    private function countStatements(string $account): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM bank_statements
              WHERE TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(account_number, ''), '[^0-9]', ''))
                  = TRIM(LEADING '0' FROM REGEXP_REPLACE(?, '[^0-9]', ''))"
        );
        $stmt->execute([$account]);
        $n = (int) $stmt->fetchColumn();
        // Uklidit případné (nemělo by nastat) osiřelé řádky, ať tearDown nenechá smetí.
        if ($n > 0) {
            $ids = $this->db->pdo()->prepare(
                "SELECT id FROM bank_statements
                  WHERE TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(account_number, ''), '[^0-9]', ''))
                      = TRIM(LEADING '0' FROM REGEXP_REPLACE(?, '[^0-9]', ''))"
            );
            $ids->execute([$account]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $this->statementIds[] = (int) $id;
            }
        }
        return $n;
    }

    /**
     * Minimální validní GPC (074 header + 1× 075 transakce) pro dané číslo účtu.
     * Layout přesně dle {@see \MyInvoice\Service\Bank\GpcParser}.
     */
    private function gpc(string $account, string $stmtNo = '001'): string
    {
        $acc16 = str_pad($account, 16, '0', STR_PAD_LEFT);
        $header = '074' . $acc16
            . str_pad('TEST UCET 206', 20)
            . '010326'
            . str_pad('1337', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('133700', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('0', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('132363', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad($stmtNo, 3, '0', STR_PAD_LEFT)
            . '310326'
            . 'FIO';

        $tx = '075' . $acc16
            . str_pad('', 16, '0')
            . str_pad('10000000001', 13, '0', STR_PAD_LEFT)
            . str_pad('92518', 12, '0', STR_PAD_LEFT)
            . '2'
            . str_pad('', 10, '0')
            . '00'
            . '0000'
            . '0000'
            . str_pad('', 10, '0')
            . '120326'
            . str_pad('PRICHOZI TEST', 20)
            . '00203'
            . '120326';

        return $header . "\r\n" . $tx . "\r\n";
    }
}

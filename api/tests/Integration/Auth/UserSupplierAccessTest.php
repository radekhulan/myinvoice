<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Action\Admin\UserAdminAction;
use MyInvoice\Action\Auth\MeAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Auth\UserSupplierAccess;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Per-user omezení na dodavatele (migrace 0148, user_supplier_access).
 *
 * Ověřuje enforcement v SupplierScopeMiddleware (403 na datových cestách, tichá
 * korekce na /auth/*, fallback = MIN povolených), filtraci /auth/me a admin
 * správu přiřazení přes UserAdminAction.
 *
 * Izolace: 2 test useři (marker e-maily) + naklonovaný 2. supplier (marker
 * company_name), vše se v tearDown smaže. Soft-skip bez cfg.php / DB.
 */
#[Group('integration')]
final class UserSupplierAccessTest extends TestCase
{
    private Connection $db;
    private SupplierScopeMiddleware $middleware;
    private MeAction $me;
    private UserAdminAction $userAdmin;
    private UserSupplierAccess $access;

    private int $supplierA = 0;   // existující (MIN id)
    private int $supplierB = 0;   // klon pro test
    private int $restrictedUserId = 0;
    private int $plainUserId = 0;

    private const SUPPLIER_MARKER = '__usa_test_supplier__';
    private const EMAIL_RESTRICTED = 'usa-test-restricted@example.test';
    private const EMAIL_PLAIN = 'usa-test-plain@example.test';
    private const FAKE_HASH = '$2y$10$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->middleware = $c->get(SupplierScopeMiddleware::class);
            $this->me = $c->get(MeAction::class);
            $this->userAdmin = $c->get(UserAdminAction::class);
            $this->access = $c->get(UserSupplierAccess::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'user_supplier_access'")->fetchColumn();
        if (!$tableExists) {
            $this->markTestSkipped('Migrace 0148_user_supplier_access neběžela.');
        }

        $this->supplierA = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierA === 0) {
            $this->markTestSkipped('Žádný supplier v DB.');
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

    private function seed(): void
    {
        $pdo = $this->db->pdo();

        // Druhý supplier: klon prvního (dynamický sloupcový list — test tak nerozbije
        // přidání dalšího NOT NULL sloupce do `supplier`).
        $cols = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier' AND COLUMN_NAME <> 'id'
              ORDER BY ORDINAL_POSITION"
        )->fetchAll(PDO::FETCH_COLUMN);
        $colList = implode(', ', array_map(static fn (string $c): string => "`$c`", $cols));
        $pdo->exec("INSERT INTO supplier ($colList) SELECT $colList FROM supplier WHERE id = {$this->supplierA}");
        $this->supplierB = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET company_name = ? WHERE id = ?')
            ->execute([self::SUPPLIER_MARKER, $this->supplierB]);

        $ins = $pdo->prepare(
            "INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, ?, 'accountant', 'cs', 1)"
        );
        $ins->execute([self::EMAIL_RESTRICTED, self::FAKE_HASH, 'USA Restricted']);
        $this->restrictedUserId = (int) $pdo->lastInsertId();
        $ins->execute([self::EMAIL_PLAIN, self::FAKE_HASH, 'USA Plain']);
        $this->plainUserId = (int) $pdo->lastInsertId();

        // Omezený user smí jen supplier B
        $this->access->replaceForUser($this->restrictedUserId, [$this->supplierB]);
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        // users/supplier delete kaskádují do user_supplier_access
        $pdo->prepare('DELETE FROM users WHERE email IN (?, ?)')
            ->execute([self::EMAIL_RESTRICTED, self::EMAIL_PLAIN]);
        $pdo->prepare('DELETE FROM supplier WHERE company_name = ?')
            ->execute([self::SUPPLIER_MARKER]);
        $this->restrictedUserId = $this->plainUserId = $this->supplierB = 0;
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $user */
    private function request(string $path, array $user, ?int $headerSupplierId = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, $user);
        if ($headerSupplierId !== null) {
            $request = $request->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $headerSupplierId);
        }
        return $request;
    }

    /** Prožene request middlewarem; vrátí [status, resolved supplier attr | null]. */
    private function runMiddleware(ServerRequestInterface $request): array
    {
        $handler = new class implements RequestHandlerInterface {
            public mixed $captured = null;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID);
                return new Psr7Response(200);
            }
        };
        $response = $this->middleware->process($request, $handler);
        return [$response->getStatusCode(), $handler->captured];
    }

    private function restrictedUser(): array
    {
        return ['id' => $this->restrictedUserId, 'role' => 'accountant', 'email' => self::EMAIL_RESTRICTED, 'name' => 'USA Restricted', 'locale' => 'cs'];
    }

    // ── SupplierScopeMiddleware ────────────────────────────────────────────

    public function testUnrestrictedUserKeepsDefaultFallback(): void
    {
        $user = ['id' => $this->plainUserId, 'role' => 'accountant'];
        [$status, $sid] = $this->runMiddleware($this->request('/api/invoices', $user));
        self::assertSame(200, $status);
        self::assertSame($this->supplierA, $sid, 'Bez omezení platí MIN(supplier.id).');
    }

    public function testRestrictedFallbackRespectsAllowedSet(): void
    {
        [$status, $sid] = $this->runMiddleware($this->request('/api/invoices', $this->restrictedUser()));
        self::assertSame(200, $status);
        self::assertSame($this->supplierB, $sid, 'Fallback bez headeru = MIN povolených, ne MIN všech.');
    }

    public function testRestrictedAllowedHeaderPasses(): void
    {
        [$status, $sid] = $this->runMiddleware($this->request('/api/invoices', $this->restrictedUser(), $this->supplierB));
        self::assertSame(200, $status);
        self::assertSame($this->supplierB, $sid);
    }

    public function testRestrictedForbiddenHeaderIs403OnDataPath(): void
    {
        [$status, $sid] = $this->runMiddleware($this->request('/api/invoices', $this->restrictedUser(), $this->supplierA));
        self::assertSame(403, $status, 'Nepovolený X-Supplier-Id na datové cestě musí vrátit 403.');
        self::assertNull($sid, 'Handler se nesmí zavolat.');
    }

    public function testRestrictedForbiddenQueryParamIs403(): void
    {
        $request = $this->request('/api/invoices/1/pdf', $this->restrictedUser())
            ->withQueryParams(['supplier_id' => (string) $this->supplierA]);
        [$status] = $this->runMiddleware($request);
        self::assertSame(403, $status, '?supplier_id= fallback podléhá stejné kontrole.');
    }

    public function testAuthPathSilentlyCorrectsInsteadOf403(): void
    {
        [$status, $sid] = $this->runMiddleware($this->request('/api/auth/me', $this->restrictedUser(), $this->supplierA));
        self::assertSame(200, $status, '/auth/* nesmí 403 (lockout FE) — tichá korekce.');
        self::assertSame($this->supplierB, $sid);
    }

    public function testAdminIgnoresRestrictionRows(): void
    {
        // I kdyby měl admin řádky v user_supplier_access, roli admin se nic neomezuje
        $this->access->replaceForUser($this->plainUserId, [$this->supplierB]);
        $user = ['id' => $this->plainUserId, 'role' => 'admin'];
        [$status, $sid] = $this->runMiddleware($this->request('/api/invoices', $user, $this->supplierA));
        self::assertSame(200, $status);
        self::assertSame($this->supplierA, $sid);
    }

    // ── /auth/me filtrace ──────────────────────────────────────────────────

    public function testMeActionFiltersSupplierList(): void
    {
        $request = $this->request('/api/auth/me', $this->restrictedUser())
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['csrf_token' => 'x'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierB);
        $response = ($this->me)($request, new Psr7Response(200));
        $payload = json_decode((string) $response->getBody(), true);

        $ids = array_column($payload['data']['suppliers'] ?? $payload['suppliers'] ?? [], 'id');
        self::assertSame([$this->supplierB], $ids, 'Omezený user vidí ve switcheru jen povolené dodavatele.');
    }

    // ── UserAdminAction: správa přiřazení ──────────────────────────────────

    public function testAdminUpdateReplacesAndClearsAssignment(): void
    {
        $admin = ['id' => 1, 'role' => 'admin'];
        $factory = new ServerRequestFactory();

        // nastav [B] → ověř
        $request = $factory->createServerRequest('PUT', '/api/admin/users/' . $this->plainUserId)
            ->withAttribute(AuthMiddleware::ATTR_USER, $admin)
            ->withParsedBody(['supplier_ids' => [$this->supplierB]]);
        $response = $this->userAdmin->update($request, new Psr7Response(200), ['id' => (string) $this->plainUserId]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$this->supplierB], $this->access->allowedIds($this->plainUserId));

        // prázdné pole = zrušení omezení
        $request = $factory->createServerRequest('PUT', '/api/admin/users/' . $this->plainUserId)
            ->withAttribute(AuthMiddleware::ATTR_USER, $admin)
            ->withParsedBody(['supplier_ids' => []]);
        $response = $this->userAdmin->update($request, new Psr7Response(200), ['id' => (string) $this->plainUserId]);
        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->access->allowedIds($this->plainUserId));
    }

    public function testAdminUpdateRejectsUnknownSupplier(): void
    {
        $admin = ['id' => 1, 'role' => 'admin'];
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/admin/users/' . $this->plainUserId)
            ->withAttribute(AuthMiddleware::ATTR_USER, $admin)
            ->withParsedBody(['supplier_ids' => [999999999]]);
        $response = $this->userAdmin->update($request, new Psr7Response(200), ['id' => (string) $this->plainUserId]);
        self::assertSame(400, $response->getStatusCode(), 'Neexistující supplier_id musí být validation_failed.');
    }

    // ── API token ──────────────────────────────────────────────────────────

    /** Token vázaný na dodavatele mimo povolený set nesmí projít ani bez hlavičky. */
    public function testBoundApiTokenOutsideAllowedSetIs403(): void
    {
        $request = $this->request('/api/invoices', $this->restrictedUser())
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['id' => 1, 'supplier_id' => $this->supplierA]);
        [$status] = $this->runMiddleware($request);
        self::assertSame(403, $status, 'Supplier-bound token mimo povolený set → 403.');
    }

    public function testBoundApiTokenInsideAllowedSetPasses(): void
    {
        $request = $this->request('/api/invoices', $this->restrictedUser())
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['id' => 1, 'supplier_id' => $this->supplierB]);
        [$status, $sid] = $this->runMiddleware($request);
        self::assertSame(200, $status);
        self::assertSame($this->supplierB, $sid, 'Token forcuje svého dodavatele i proti hlavičce.');
    }

    // ── Úklid po smazání dodavatele ────────────────────────────────────────

    /**
     * Smazání dodavatele přes admin endpoint musí přiřazení uklidit. Delete běží
     * s FOREIGN_KEY_CHECKS = 0 (cyklický FK supplier↔currencies), takže se
     * ON DELETE CASCADE NEPROVEDE a řádky je nutné smazat ručně — jinak by
     * omezenému uživateli zůstal v setu neexistující dodavatel a fallback
     * MIN(povolených) by ho zamknul mimo aplikaci.
     */
    public function testDeletingSupplierClearsAssignments(): void
    {
        $pdo = $this->db->pdo();
        self::assertSame([$this->supplierB], $this->access->allowedIds($this->restrictedUserId));

        $settings = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Action\Settings\SettingsAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/api/suppliers/' . $this->supplierB)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);
        $response = $settings->deleteSupplierById($request, new Psr7Response(200), ['id' => (string) $this->supplierB]);

        if ($response->getStatusCode() !== 200) {
            $response->getBody()->rewind();
            self::markTestSkipped('Dodavatele nelze smazat (má data): ' . (string) $response->getBody());
        }

        $orphans = (int) $pdo->query(
            'SELECT COUNT(*) FROM user_supplier_access WHERE supplier_id = ' . $this->supplierB
        )->fetchColumn();
        self::assertSame(0, $orphans, 'Po smazání dodavatele nesmí zůstat osiřelé přiřazení.');
        $this->supplierB = 0; // cleanup už ho nemá co mazat
    }
}

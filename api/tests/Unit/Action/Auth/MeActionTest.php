<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\MeAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\Auth\UserSupplierAccess;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class MeActionTest extends TestCase
{
    public function testResponseContainsMfaAndAuthoritativeSessionState(): void
    {
        $supplierStatement = $this->createMock(\PDOStatement::class);
        $supplierStatement->expects(self::once())
            ->method('fetchAll')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn([]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())
            ->method('query')
            ->willReturn($supplierStatement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('hasColumn')->with('supplier', 'oss_enabled')->willReturn(true);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(1);
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())
            ->method('now')
            ->willReturn(new \DateTimeImmutable('2026-07-24 12:00:00 UTC'));
        $config = new Config([
            'session' => ['lock_after_minutes' => 15],
            'auth' => [
                'require_mfa' => true,
                'require_totp' => false,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]);
        // Admin bez omezení na dodavatele → allowedIdsForUser vrací null (vidí vše).
        $access = $this->createMock(UserSupplierAccess::class);
        $access->method('allowedIdsForUser')->willReturn(null);
        $action = new MeAction(
            $db,
            $config,
            $credentials,
            new MfaPolicyService($config),
            new SessionLockPolicy($config),
            $clock,
            $access,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/auth/me')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 17,
                'email' => 'synthetic@example.invalid',
                'name' => 'Synthetic User',
                'role' => 'admin',
                'locale' => 'cs',
                'totp_enabled' => true,
                'session_lock_after_minutes' => 5,
            ])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, [
                'csrf_token' => str_repeat('b', 64),
                'assurance_level' => 'strong',
                'last_user_activity_at' => '2026-07-24 11:58:00.000000',
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['passkey', 'totp'], $body['user']['mfa_methods']);
        self::assertTrue($body['user']['mfa_enabled']);
        self::assertSame(1, $body['user']['passkey_count']);
        self::assertFalse($body['user']['must_setup_mfa']);
        self::assertTrue($body['require_mfa']);
        self::assertSame(['passkey', 'totp'], $body['allowed_mfa_methods']);
        self::assertSame('active', $body['session_state']);
        self::assertSame(5, $body['lock_after_minutes']);
        self::assertSame('2026-07-24T12:00:00.000000Z', $body['server_time']);
        self::assertSame('2026-07-24T12:03:00.000000Z', $body['idle_expires_at']);
        self::assertSame(str_repeat('b', 64), $body['csrf_token']);
    }
}

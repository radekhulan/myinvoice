<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\Auth\SessionLockPreferenceService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SessionLockPreferenceServiceTest extends TestCase
{
    public function testGetDescribesUserPreferenceAndAdminLimit(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([17]);
        $statement->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn([
            'session_lock_after_minutes' => '5',
        ]);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        $preference = $this->service($db, 15, true)->get(17);

        self::assertSame([
            'user_lock_after_minutes' => 5,
            'admin_lock_after_minutes' => 15,
            'maximum_lock_after_minutes' => 15,
            'effective_lock_after_minutes' => 5,
        ], $preference);
    }

    public function testUpdateRejectsTimeoutAboveAdminLimitBeforeDatabaseWrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        $this->expectException(\InvalidArgumentException::class);
        $this->service($db, 15, true)->update(17, 30);
    }

    public function testUpdateRejectsCustomTimeoutWithoutUsablePasskey(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('passkey');
        $this->service($db, 15, false)->update(17, 10);
    }

    public function testUpdateAllowsClearingPreferenceWithoutPasskey(): void
    {
        $update = $this->createMock(PDOStatement::class);
        $update->expects(self::once())->method('execute')->with([null, 17]);
        $read = $this->createMock(PDOStatement::class);
        $read->method('execute');
        $read->method('fetch')->willReturn(['session_lock_after_minutes' => null]);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($update, $read);
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $preference = $this->service($db, 15, false)->update(17, null);

        self::assertNull($preference['user_lock_after_minutes']);
        self::assertSame(15, $preference['effective_lock_after_minutes']);
    }

    private function service(
        Connection $db,
        int $adminTimeout,
        bool $usablePasskey,
    ): SessionLockPreferenceService {
        $policy = new SessionLockPolicy(new Config([
            'session' => ['lock_after_minutes' => $adminTimeout],
        ]));
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->method('countActiveForUser')->willReturn($usablePasskey ? 1 : 0);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->method('isAvailable')->willReturn(true);

        return new SessionLockPreferenceService($db, $policy, $credentials, $passkeys);
    }
}

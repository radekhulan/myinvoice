<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use MyInvoice\Infrastructure\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigEnvOverridesTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/myinvoice-config-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
        file_put_contents($this->tmpDir . '/cfg.php', <<<'PHP'
<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'myinvoice',
        'user' => 'root',
        'pass' => 'root',
    ],
    'redis' => [
        'enabled' => false,
        'host' => '127.0.0.1',
        'port' => 6379,
    ],
];
PHP);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
                continue;
            }
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        @unlink($this->tmpDir . '/cfg.php');
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function testUnresolvedRailwayLikeReferencesAreIgnored(): void
    {
        $this->setEnv('MYSQL_HOST', '${DB_HOST}');
        $this->setEnv('MYSQL_PORT', '${DB_PORT}');
        $this->setEnv('REDIS_URL', '${REDIS_URL}');

        $cfg = Config::load($this->tmpDir);

        self::assertSame('127.0.0.1', $cfg->get('db.host'));
        self::assertSame(3306, $cfg->get('db.port'));
        self::assertSame('127.0.0.1', $cfg->get('redis.host'));
        self::assertSame(6379, $cfg->get('redis.port'));
        self::assertFalse($cfg->get('redis.enabled'));
    }

    public function testValidEnvironmentOverridesStillApply(): void
    {
        $this->setEnv('MYSQL_HOST', 'db.internal');
        $this->setEnv('MYSQL_PORT', '3307');

        $cfg = Config::load($this->tmpDir);

        self::assertSame('db.internal', $cfg->get('db.host'));
        self::assertSame(3307, $cfg->get('db.port'));
    }

    public function testSessionLockDefaultsToDisabledWithoutExplicitConfiguration(): void
    {
        $this->unsetEnv('MYINVOICE_SESSION_LOCK_AFTER_MINUTES');

        $cfg = Config::load($this->tmpDir);

        self::assertSame(0, $cfg->get('session.lock_after_minutes'));
    }

    public function testEmptyEnvDoesNotOverrideConfig(): void
    {
        // Docker Compose dosadí u `KEY: ${VAR}` prázdný řetězec, když VAR
        // v .env chybí. Takový prázdný override nesmí přebít hodnotu z cfg.php.
        $this->setEnv('MYSQL_HOST', '');
        $this->setEnv('MYSQL_PORT', '');

        $cfg = Config::load($this->tmpDir);

        self::assertSame('127.0.0.1', $cfg->get('db.host'));
        self::assertSame(3306, $cfg->get('db.port'));
    }

    public function testWebAuthnPolicyEnvironmentOverridesApply(): void
    {
        $this->setEnv('MYINVOICE_SESSION_LOCK_AFTER_MINUTES', '30');
        $this->setEnv('MYINVOICE_AUTH_REQUIRE_MFA', 'true');
        $this->setEnv('MYINVOICE_AUTH_MFA_METHODS', ' passkey, totp ');
        $this->setEnv('MYINVOICE_AUTH_PASSWORDLESS_LOGIN', 'true');

        $cfg = Config::load($this->tmpDir);

        self::assertSame(30, $cfg->get('session.lock_after_minutes'));
        self::assertTrue($cfg->get('auth.require_mfa'));
        self::assertSame(['passkey', 'totp'], $cfg->get('auth.allowed_mfa_methods'));
        self::assertTrue($cfg->get('auth.passwordless_login.enabled'));
    }

    public function testPasswordlessLoginDefaultsToDisabled(): void
    {
        $this->unsetEnv('MYINVOICE_AUTH_PASSWORDLESS_LOGIN');

        $cfg = Config::load($this->tmpDir);

        self::assertFalse($cfg->get('auth.passwordless_login.enabled'));
    }

    public function testInvalidSessionLockEnvironmentOverrideIsPreservedForPolicyValidation(): void
    {
        $this->setEnv('MYINVOICE_SESSION_LOCK_AFTER_MINUTES', '15 minutes');

        $cfg = Config::load($this->tmpDir);

        self::assertSame('15 minutes', $cfg->get('session.lock_after_minutes'));
    }

    public function testOverdueBoundaryDefaultsToTomorrowAndSupportsBooleanEnv(): void
    {
        $this->unsetEnv('MYINVOICE_OVERDUE_INCLUDES_TODAY');
        self::assertFalse(Config::load($this->tmpDir)->get('invoices.overdue_includes_today'));
        $this->setEnv('MYINVOICE_OVERDUE_INCLUDES_TODAY', 'true');
        self::assertTrue(Config::load($this->tmpDir)->get('invoices.overdue_includes_today'));
        $this->setEnv('MYINVOICE_OVERDUE_INCLUDES_TODAY', 'false');
        self::assertFalse(Config::load($this->tmpDir)->get('invoices.overdue_includes_today'));
    }

    public function testInAppUpgradeDefaultsToEnabledAndSupportsBooleanEnv(): void
    {
        $this->unsetEnv('MYINVOICE_IN_APP_UPGRADE');
        self::assertTrue(Config::load($this->tmpDir)->get('updates.in_app_upgrade'));
        $this->setEnv('MYINVOICE_IN_APP_UPGRADE', 'false');
        self::assertFalse(Config::load($this->tmpDir)->get('updates.in_app_upgrade'));
    }

    private function setEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->envBackup)) {
            $current = getenv($name);
            $this->envBackup[$name] = ($current === false) ? false : $current;
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function unsetEnv(string $name): void
    {
        if (!array_key_exists($name, $this->envBackup)) {
            $current = getenv($name);
            $this->envBackup[$name] = ($current === false) ? false : $current;
        }
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}

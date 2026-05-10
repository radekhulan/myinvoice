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
}

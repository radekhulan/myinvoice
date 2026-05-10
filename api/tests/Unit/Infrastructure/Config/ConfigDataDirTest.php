<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use MyInvoice\Infrastructure\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Pokrývá funkčnost MYINVOICE_DATA_DIR — sjednocení všech stateful adresářů
 * (log/, storage/, private/) pod jediný path. Cíl: clean Docker volumes,
 * read-only kontejner mimo /data.
 */
final class ConfigDataDirTest extends TestCase
{
    private string $tmpRoot;
    private string $dataDir;

    /** @var array<string,string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/myinvoice-cfg-' . bin2hex(random_bytes(6));
        $this->dataDir = sys_get_temp_dir() . '/myinvoice-data-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0700, true);
        mkdir($this->dataDir, 0700, true);

        // Backup ENV proměnné, které testy přepínají.
        foreach (['MYINVOICE_DATA_DIR', 'MYINVOICE_APP_ENV'] as $name) {
            $this->envBackup[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->rrmdir($this->tmpRoot);
        $this->rrmdir($this->dataDir);
    }

    public function testWithoutDataDirPathsAreUnchanged(): void
    {
        $this->writeBaseCfg([
            'logging' => ['path' => '/some/legacy/log/app.log'],
            'storage' => ['invoices_dir' => '/some/legacy/storage/invoices'],
        ]);

        $config = Config::load($this->tmpRoot);

        self::assertNull($config->dataDir());
        self::assertSame('/some/legacy/log/app.log', $config->get('logging.path'));
        self::assertSame('/some/legacy/storage/invoices', $config->get('storage.invoices_dir'));
    }

    public function testDataDirRewritesAllStatefulPaths(): void
    {
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);

        $this->writeBaseCfg([
            'logging' => ['path' => '/will/be/overridden/app.log'],
            'storage' => [
                'invoices_dir' => '/x/invoices',
                'uploads_dir'  => '/x/uploads',
                'backup_dir'   => '/x/backup',
                'sessions_dir' => '/x/sessions',
                'cache_dir'    => '/x/cache',
            ],
            'cron' => ['backup' => ['output_dir' => '/x/backup']],
            'smtp' => ['dkim' => [
                'private_key_path' => '/x/dkim/key.pem',
                'public_key_path'  => '/x/dkim/key.pub',
                'dns_doc_path'     => '/x/dkim/dns.txt',
            ]],
        ]);

        $config = Config::load($this->tmpRoot);
        $sep    = DIRECTORY_SEPARATOR;

        self::assertSame($this->dataDir, $config->dataDir());
        self::assertSame($this->dataDir . $sep . 'log' . $sep . 'app.log', $config->get('logging.path'));
        self::assertSame($this->dataDir . $sep . 'storage' . $sep . 'invoices', $config->get('storage.invoices_dir'));
        self::assertSame($this->dataDir . $sep . 'storage' . $sep . 'uploads',  $config->get('storage.uploads_dir'));
        self::assertSame($this->dataDir . $sep . 'storage' . $sep . 'backup',   $config->get('storage.backup_dir'));
        self::assertSame($this->dataDir . $sep . 'storage' . $sep . 'sessions', $config->get('storage.sessions_dir'));
        self::assertSame($this->dataDir . $sep . 'storage' . $sep . 'cache',    $config->get('storage.cache_dir'));
        self::assertSame($this->dataDir . $sep . 'storage' . $sep . 'backup',   $config->get('cron.backup.output_dir'));
        self::assertSame($this->dataDir . $sep . 'private' . $sep . 'dkim' . $sep . 'myinvoice.pem', $config->get('smtp.dkim.private_key_path'));
        self::assertSame($this->dataDir . $sep . 'private' . $sep . 'dkim' . $sep . 'myinvoice.pub', $config->get('smtp.dkim.public_key_path'));
        self::assertSame($this->dataDir . $sep . 'private' . $sep . 'dkim' . $sep . 'dns.txt',       $config->get('smtp.dkim.dns_doc_path'));
    }

    public function testDataDirTrimsTrailingSeparator(): void
    {
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir . '/');
        $this->writeBaseCfg([]);

        $config = Config::load($this->tmpRoot);

        self::assertSame($this->dataDir, $config->dataDir());
    }

    public function testEmptyDataDirEnvIsTreatedAsUnset(): void
    {
        putenv('MYINVOICE_DATA_DIR=   ');
        $this->writeBaseCfg(['logging' => ['path' => '/legacy/app.log']]);

        $config = Config::load($this->tmpRoot);

        self::assertNull($config->dataDir());
        self::assertSame('/legacy/app.log', $config->get('logging.path'));
    }

    public function testCfgLocalFromDataDirIsMerged(): void
    {
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);
        $this->writeBaseCfg([
            'app' => ['env' => 'production', 'pepper' => 'base'],
        ]);
        file_put_contents(
            $this->dataDir . '/cfg.local.php',
            "<?php return ['app' => ['pepper' => 'from-data-dir']];",
        );

        $config = Config::load($this->tmpRoot);

        self::assertSame('from-data-dir', $config->get('app.pepper'));
        self::assertSame('production', $config->get('app.env'));
    }

    public function testEnvOverridesStillWinOverDataDirCfgLocal(): void
    {
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);
        putenv('MYINVOICE_APP_ENV=staging');
        $this->writeBaseCfg(['app' => ['env' => 'production']]);
        file_put_contents(
            $this->dataDir . '/cfg.local.php',
            "<?php return ['app' => ['env' => 'development']];",
        );

        $config = Config::load($this->tmpRoot);

        self::assertSame('staging', $config->get('app.env'));
    }

    /** @param array<string,mixed> $arr */
    private function writeBaseCfg(array $arr): void
    {
        $exported = var_export($arr, true);
        file_put_contents($this->tmpRoot . '/cfg.php', "<?php return {$exported};\n");
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

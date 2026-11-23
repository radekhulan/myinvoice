<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Update;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Update\NativeUpdateService;
use MyInvoice\Service\Update\VersionService;
use PDO;
use PHPUnit\Framework\TestCase;

final class VersionServiceUpgradeStateTest extends TestCase
{
    private string $stateDir;
    private string|false $previousDataDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/version-service-' . bin2hex(random_bytes(6));
        mkdir($this->stateDir . '/storage', 0775, true);
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        putenv('MYINVOICE_DATA_DIR=' . $this->stateDir);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDataDir === false
            ? 'MYINVOICE_DATA_DIR'
            : 'MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        foreach (glob($this->stateDir . '/storage/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->stateDir . '/storage');
        @rmdir($this->stateDir);
    }

    /**
     * Prošlý požadavek na starou verzi (watcher nikdy neběžel, instance se
     * mezitím aktualizovala jinudy) nesmí na stránce viset navždy.
     */
    public function testDropsUnconfirmedResultOnceInstalledVersionPassedTarget(): void
    {
        $service = $this->service();
        $this->writeResult(['status' => 'unknown', 'target_version' => '0.0.1']);

        self::assertNull($service->loadUpgradeResult());
        self::assertFileDoesNotExist($service->upgradeResultPath());
    }

    public function testDropsUnconfirmedResultWhenTargetVersionIsInstalled(): void
    {
        $service = $this->service();
        $this->writeResult(['status' => 'unknown', 'target_version' => $service->getCurrentVersion()]);

        self::assertNull($service->loadUpgradeResult());
    }

    /** Docker může nasadit novou image (VERSION = cíl) a pak selhat na migraci. */
    public function testKeepsFailedResultWhenTargetVersionIsInstalled(): void
    {
        $service = $this->service();
        $this->writeResult(['status' => 'failed', 'target_version' => $service->getCurrentVersion()]);

        self::assertSame('failed', $service->loadUpgradeResult()['status'] ?? null);
    }

    public function testDropsFailedResultOnceInstalledVersionPassedTarget(): void
    {
        $service = $this->service();
        $this->writeResult(['status' => 'failed', 'target_version' => '0.0.1']);

        self::assertNull($service->loadUpgradeResult());
    }

    public function testKeepsSuccessResultForInstalledVersion(): void
    {
        $service = $this->service();
        $this->writeResult(['status' => 'applied', 'target_version' => $service->getCurrentVersion()]);

        self::assertSame('applied', $service->loadUpgradeResult()['status'] ?? null);
    }

    public function testKeepsFailedResultForVersionNotYetInstalled(): void
    {
        $service = $this->service();
        $this->writeResult(['status' => 'failed', 'target_version' => '999.0.0']);

        self::assertSame('failed', $service->loadUpgradeResult()['status'] ?? null);
        self::assertFileExists($service->upgradeResultPath());
    }

    public function testInAppUpgradeIsEnabledByDefault(): void
    {
        self::assertTrue($this->service(new Config([]))->isInAppUpgradeEnabled());
    }

    public function testTriggerRefusesWhenInAppUpgradeIsDisabled(): void
    {
        $service = $this->service(new Config(['updates' => ['in_app_upgrade' => false]]));

        $result = $service->triggerUpgrade('999.0.0', 'admin@example.com');

        self::assertSame('error', $result['status']);
        self::assertFileDoesNotExist($service->upgradeFlagPath());
    }

    private function service(?Config $config = null): VersionService
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('pdo')->willReturn($this->createStub(PDO::class));

        return new VersionService(
            $connection,
            $this->createStub(NativeUpdateService::class),
            $config ?? new Config([]),
        );
    }

    /** @param array<string,string> $result */
    private function writeResult(array $result): void
    {
        file_put_contents($this->stateDir . '/storage/upgrade-result.json', json_encode($result));
    }
}

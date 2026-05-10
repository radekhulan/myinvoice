<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use PHPUnit\Framework\TestCase;

/**
 * Pojistka proti regresi 3.2.0: Dockerfile NESMÍ natvrdo nastavovat
 * `ENV MYINVOICE_DATA_DIR=/data` — ENV musí zůstat unset, aby default
 * chování image bylo legacy 3-volume layout (zpětná kompat s 3.1.x).
 *
 * Single-volume mód je opt-in přes `docker-compose.single-volume.yml`
 * nebo explicit env override.
 */
final class DockerfileDataDirEnvTest extends TestCase
{
    public function testDockerfileDoesNotHardcodeDataDirEnv(): void
    {
        $repoRoot = dirname(__DIR__, 5);
        $dockerfilePath = $repoRoot . '/Dockerfile';

        self::assertFileExists($dockerfilePath, 'Dockerfile not found at repo root');

        $contents = file_get_contents($dockerfilePath);
        self::assertIsString($contents);

        // Strip comments — they may legitimately mention MYINVOICE_DATA_DIR.
        $codeOnly = implode("\n", array_filter(
            preg_split('/\R/', $contents) ?: [],
            static fn (string $line) => !preg_match('/^\s*#/', $line),
        ));

        self::assertDoesNotMatchRegularExpression(
            '/^\s*ENV\s+MYINVOICE_DATA_DIR\b/m',
            $codeOnly,
            'Dockerfile must NOT set ENV MYINVOICE_DATA_DIR — it must remain unset by default '
            . 'so that 3.1.x → 3.2.x Docker upgrades keep the legacy 3-volume layout. '
            . 'Single-volume mode is opt-in via docker-compose.single-volume.yml.',
        );
    }
}

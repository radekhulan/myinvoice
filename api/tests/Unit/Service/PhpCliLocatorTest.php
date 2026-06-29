<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service;

use MyInvoice\Service\PhpCliLocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Worker se pod php-fpm (oficiální Docker image) spouštěl s binárkou `php-fpm`
 * místo CLI php — php-fpm CLI skript nepochopí, vypíše usage a skončí, takže
 * import zůstal navždy „queued". `PhpCliLocator` proto musí ne-CLI SAPI binárky
 * (php-fpm, php-cgi, php-win, phpdbg) odmítnout.
 */
final class PhpCliLocatorTest extends TestCase
{
    /** @return list<array{string}> */
    public static function nonCliNames(): array
    {
        return [
            ['php-fpm'],
            ['php-fpm8'],
            ['php-fpm8.5'],
            ['/usr/local/sbin/php-fpm'],
            ['php-cgi'],
            ['php-cgi.exe'],
            ['php-win.exe'],
            ['phpdbg'],
            ['phpdbg.exe'],
            ['PHP-FPM'], // case-insensitivní
        ];
    }

    #[DataProvider('nonCliNames')]
    public function testRejectsNonCliSapiBinaries(string $path): void
    {
        self::assertTrue(
            PhpCliLocator::isNonCliSapiName(basename($path)),
            "$path má být odmítnuto jako ne-CLI SAPI"
        );
    }

    /** @return list<array{string}> */
    public static function cliNames(): array
    {
        return [
            ['php'],
            ['php.exe'],
            ['php8.5'],
            ['/usr/local/bin/php'],
            ['C:\\Program Files\\PHP\\php.exe'],
        ];
    }

    #[DataProvider('cliNames')]
    public function testAcceptsCliBinaries(string $path): void
    {
        self::assertFalse(
            PhpCliLocator::isNonCliSapiName(basename($path)),
            "$path má být přijato jako CLI php"
        );
    }

    public function testResolveNeverReturnsNonCliBinary(): void
    {
        $resolved = PhpCliLocator::resolve();
        if ($resolved === null) {
            self::markTestSkipped('Žádné php nenalezeno v tomto prostředí.');
        }
        self::assertFalse(
            PhpCliLocator::isNonCliSapiName(basename($resolved)),
            "resolve() nesmí vrátit ne-CLI SAPI binárku, vráceno: $resolved"
        );
    }
}

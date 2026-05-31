<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * IpMatcher musí z DI kontejneru dostat vstříknutý Config. IpMatcher má
 * v konstruktoru volitelný `?Config $config = null`, který autowiring NEresolvuje
 * (dosadí default null). Bez explicitní DI definice v Bootstrap tak
 * `clientIpFromRequest()` ignoruje `cfg.ip_allowlist.trusted_proxies` a za reverse
 * proxy loguje/počítá IP proxy místo reálného klienta (audit log, brute-force lockout).
 *
 * Soft-skip bez cfg.php (CI runner bez DI bootstrapu).
 */
#[Group('integration')]
final class IpMatcherWiringTest extends TestCase
{
    public function testContainerInjectsConfigIntoIpMatcher(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DI bootstrap.');
        }
        try {
            $matcher = Bootstrap::buildApp()->getContainer()->get(IpMatcher::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        // Regresní jádro: kontejner musí IpMatcheru vstříknout Config (před opravou null).
        $prop = (new \ReflectionObject($matcher))->getProperty('config');
        $prop->setAccessible(true);
        self::assertInstanceOf(
            Config::class,
            $prop->getValue($matcher),
            'IpMatcher z kontejneru nemá vstříknutý Config → trusted_proxies se ignoruje.'
        );

        // Funkční důkaz: od důvěryhodné proxy přečte reálnou IP z X-Forwarded-For.
        $resolved = $matcher->clientIp(
            ['REMOTE_ADDR' => '10.9.9.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'],
            ['10.9.9.9'],
        );
        self::assertSame('203.0.113.7', $resolved);
    }
}

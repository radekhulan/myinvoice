<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\VarsymbolGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Testuje zpětné vyparsování counteru z varsymbolu (buildCounterMatcher) — pure logika
 * bez DB, jádro samoopravného číslování (highestUsedCounter / syncCounter). Volá privátní
 * metodu přes reflexi nad instancí bez konstruktoru.
 */
final class VarsymbolCounterMatcherTest extends TestCase
{
    private VarsymbolGenerator $gen;

    protected function setUp(): void
    {
        $this->gen = (new \ReflectionClass(VarsymbolGenerator::class))->newInstanceWithoutConstructor();
    }

    /** @return array{0: ?string, 1: string} */
    private function matcher(string $template, string $date): array
    {
        $m = new \ReflectionMethod(VarsymbolGenerator::class, 'buildCounterMatcher');
        return $m->invoke($this->gen, $template, new \DateTimeImmutable($date));
    }

    public function testExtractsCounterAndLikePrefix(): void
    {
        [$regex, $like] = $this->matcher('9{YY}{MM}{CCC}', '2026-04-15');

        self::assertNotNull($regex);
        self::assertSame('92604', $like, 'LIKE prefix = literál + dosazené datum před counterem');

        self::assertSame(1, preg_match($regex, '92604042', $m));
        self::assertSame(42, (int) $m[1]);

        self::assertSame(1, preg_match($regex, '92604001', $m));
        self::assertSame(1, (int) $m[1]);
    }

    public function testDoesNotMatchOtherPeriod(): void
    {
        [$regex] = $this->matcher('9{YY}{MM}{CCC}', '2026-04-15');
        // Jiný měsíc (05) ani jiný rok se nesmí započítat do counteru období 2026-04.
        self::assertSame(0, preg_match($regex, '92605001'));
        self::assertSame(0, preg_match($regex, '92704001'));
    }

    public function testRegexIsAnchored(): void
    {
        [$regex] = $this->matcher('{YYYY}{MM}{CCC}', '2026-04-15');
        // Nesmí matchnout číslo s navíc znaky (kotvení ^...$), jinak by parsoval cizí řady.
        self::assertSame(0, preg_match($regex, '202604042X'));
        self::assertSame(0, preg_match($regex, 'X202604042'));
        self::assertSame(1, preg_match($regex, '202604042'));
    }

    public function testYearPeriodTemplate(): void
    {
        [$regex, $like] = $this->matcher('JD{YYYY}-{CC}', '2026-04-15');
        self::assertSame('JD2026-', $like);
        self::assertSame(1, preg_match($regex, 'JD2026-07', $m));
        self::assertSame(7, (int) $m[1]);
    }

    public function testTemplateWithoutCounterReturnsNull(): void
    {
        [$regex, $like] = $this->matcher('FIX-{YYYY}', '2026-04-15');
        self::assertNull($regex, 'Bez {C+} nelze counter vyparsovat');
        self::assertSame('', $like);
    }

    public function testLiteralRegexCharsEscaped(): void
    {
        // Tečka/závorky v template musí být brány doslovně, ne jako regex meta.
        [$regex] = $this->matcher('F.{YYYY}({CCC})', '2026-04-15');
        self::assertSame(1, preg_match($regex, 'F.2026(042)', $m));
        self::assertSame(42, (int) $m[1]);
        // 'X' místo doslovné tečky se nesmí trefit
        self::assertSame(0, preg_match($regex, 'FX2026(042)'));
    }
}

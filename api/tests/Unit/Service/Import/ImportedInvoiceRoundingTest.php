<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\ImportedInvoiceRounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #258: zaokrouhlení vydaného dokladu převzaté z Fakturoidu / iDokladu.
 */
final class ImportedInvoiceRoundingTest extends TestCase
{
    public static function fakturoidDocs(): array
    {
        return [
            'total obsahuje zaokrouhlení nahoru' => [['total' => '1641.0', 'rounding_adjustment' => '0.24'], 1640.76, 0.24],
            'total obsahuje zaokrouhlení dolů'   => [['total' => '1056.0', 'rounding_adjustment' => '-0.33'], 1056.33, -0.33],
            'total bez zaokrouhlení, jen adjustment' => [['total' => '1640.76', 'rounding_adjustment' => '0.24'], 1640.76, 0.24],
            'bez zaokrouhlení'                   => [['total' => '1640.76', 'rounding_adjustment' => '0.0'], 1640.76, 0.0],
            'dobropis'                           => [['total' => '-1641.0'], -1640.76, -0.24],
            'nesedící položky se nehádají'       => [['total' => '1985.0', 'rounding_adjustment' => '0.24'], 1640.76, 0.0],
            'chybí total'                        => [['rounding_adjustment' => '0.4'], 1000.0, 0.4],
            'nevěrohodný adjustment'             => [['rounding_adjustment' => '5'], 1000.0, 0.0],
        ];
    }

    #[DataProvider('fakturoidDocs')]
    public function testFakturoidRounding(array $doc, float $computed, float $expected): void
    {
        self::assertEqualsWithDelta($expected, ImportedInvoiceRounding::fromFakturoid($doc, $computed), 0.0001);
    }

    public function testIdokladRoundingItem(): void
    {
        $items = [
            ['ItemType' => 0, 'Amount' => 1, 'Prices' => ['TotalWithVat' => 3179.64]],
            ['ItemType' => 1, 'Amount' => 1, 'Prices' => ['TotalWithVat' => 0.36]],
        ];

        self::assertTrue(ImportedInvoiceRounding::isIdokladRoundingItem($items[1]));
        self::assertFalse(ImportedInvoiceRounding::isIdokladRoundingItem($items[0]));
        self::assertEqualsWithDelta(0.36, ImportedInvoiceRounding::fromIdokladItems($items), 0.0001);
    }

    public function testIdokladWithoutRoundingItemReturnsNull(): void
    {
        self::assertNull(ImportedInvoiceRounding::fromIdokladItems([['ItemType' => 0, 'Prices' => ['TotalWithVat' => 100]]]));
        self::assertNull(ImportedInvoiceRounding::fromIdokladItems([]));
    }

    public function testIdokladImplausibleRoundingStaysAsItem(): void
    {
        self::assertNull(ImportedInvoiceRounding::fromIdokladItems([['ItemType' => 1, 'Prices' => ['TotalWithVat' => 12.5]]]));
    }

    public function testIdokladRoundingFallsBackToUnitPrice(): void
    {
        self::assertEqualsWithDelta(-0.33, ImportedInvoiceRounding::fromIdokladItems([['ItemType' => 1, 'Amount' => 1, 'UnitPrice' => -0.33]]), 0.0001);
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Export\MoneyS3XmlExporter;
use PHPUnit\Framework\TestCase;

final class MoneyS3XmlExporterTest extends TestCase
{
    private MoneyS3XmlExporter $exporter;

    protected function setUp(): void
    {
        /** @var InvoiceRepository $repo */
        $repo = (new \ReflectionClass(InvoiceRepository::class))->newInstanceWithoutConstructor();
        /** @var Connection $db */
        $db = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        $this->exporter = new MoneyS3XmlExporter($repo, $db);
    }

    public function testDocumentEnvelopeAndSingleRateBucketsMatchRealSample(): void
    {
        // Shape mirrors a genuine Money S3 export sample: SazbaDPH1 stays at
        // the agenda's configured reduced rate (12) even when unused by any
        // line — it's a fixed agenda constant, not derived per-document (the
        // real sample itself declares SazbaDPH1=12 with only a 21%-taxed
        // line). Zaklad22/DPH22 is the legacy "standard rate" bucket
        // regardless of the literal 21 in its name.
        $xml = $this->exporter->buildXml([$this->invoice()]);

        self::assertSame('0', $this->xpathOne($xml, '/MoneyData/@VyberZaznamu'));
        self::assertSame('CZ', $this->xpathOne($xml, '/MoneyData/@JazykVerze'));
        self::assertSame('1026001', $this->xpathOne($xml, '//FaktVyd/Doklad'));
        self::assertSame('2026-06-16', $this->xpathOne($xml, '//FaktVyd/Vystaveno'));
        self::assertSame('2026-06-30', $this->xpathOne($xml, '//FaktVyd/Splatno'));
        self::assertSame('12.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH1'));
        self::assertSame('21.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH2'));
        self::assertSame('0.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad5'));
        self::assertSame('0.00', $this->xpathOne($xml, '//SouhrnDPH/DPH5'));
        self::assertSame('32526.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad22'));
        self::assertSame('6830.46', $this->xpathOne($xml, '//SouhrnDPH/DPH22'));
        self::assertSame('39356.46', $this->xpathOne($xml, '//FaktVyd/Celkem'));
        self::assertSame('39356.46', $this->xpathOne($xml, '//FaktVyd/Proplatit'));
    }

    public function testMixedReducedAndStandardRatesSplitIntoBothBuckets(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item([
                    'description' => 'Zbozi se sazbou 12',
                    'vat_rate_snapshot' => 12.0,
                    'total_without_vat' => 1000.0,
                    'total_vat' => 120.0,
                    'total_with_vat' => 1120.0,
                ]),
                $this->item([
                    'description' => 'Zbozi se sazbou 21',
                    'vat_rate_snapshot' => 21.0,
                    'total_without_vat' => 2000.0,
                    'total_vat' => 420.0,
                    'total_with_vat' => 2420.0,
                ]),
            ],
            'total_with_vat' => 3540.0,
            'amount_to_pay' => 3540.0,
        ])]);

        self::assertSame('12.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH1'));
        self::assertSame('21.00', $this->xpathOne($xml, '//FaktVyd/SazbaDPH2'));
        self::assertSame('1000.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad5'));
        self::assertSame('120.00', $this->xpathOne($xml, '//SouhrnDPH/DPH5'));
        self::assertSame('2000.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad22'));
        self::assertSame('420.00', $this->xpathOne($xml, '//SouhrnDPH/DPH22'));
    }

    public function testZeroRateItemLandsInZeroBucketAlongsideStandardRate(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item([
                    'description' => 'Pouzite zbozi (par. 90)',
                    'vat_rate_snapshot' => 0.0,
                    'total_without_vat' => 500.0,
                    'total_vat' => 0.0,
                    'total_with_vat' => 500.0,
                ]),
                $this->item([
                    'description' => 'Bezne zbozi',
                    'vat_rate_snapshot' => 21.0,
                    'total_without_vat' => 1000.0,
                    'total_vat' => 210.0,
                    'total_with_vat' => 1210.0,
                ]),
            ],
            'total_with_vat' => 1710.0,
            'amount_to_pay' => 1710.0,
        ])]);

        self::assertSame('500.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad0'));
        self::assertSame('1000.00', $this->xpathOne($xml, '//SouhrnDPH/Zaklad22'));
    }

    public function testUnsupportedVatRateFailsExport(): void
    {
        // Only 0 / 12 / 21 map onto Money S3's fixed agenda buckets; a legacy
        // or foreign rate (e.g. 15) can't be represented and must hard-fail
        // rather than silently landing in the wrong bucket.
        $this->expectException(\RuntimeException::class);

        $this->exporter->buildXml([$this->invoice([
            'items' => [$this->item(['vat_rate_snapshot' => 15.0])],
        ])]);
    }

    public function testBuyerPartyUsesClientSnapshotAndSupplierUsesOwnCompany(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice()]);

        self::assertSame('Kamody s.r.o.', $this->xpathOne($xml, '//FaktVyd/DodOdb/Nazev'));
        self::assertSame('Sokolska 217', $this->xpathOne($xml, '//FaktVyd/DodOdb/Adresa/Ulice'));
        self::assertSame('27467392', $this->xpathOne($xml, '//FaktVyd/DodOdb/ICO'));
        self::assertSame('Kamody s.r.o.', $this->xpathOne($xml, '//FaktVyd/KonecPrij/Nazev'));
        self::assertSame('SPORT s.r.o.', $this->xpathOne($xml, '//FaktVyd/MojeFirma/Nazev'));
        self::assertSame('12345678', $this->xpathOne($xml, '//FaktVyd/MojeFirma/ICO'));
        self::assertSame('CZK', $this->xpathOne($xml, '//FaktVyd/MojeFirma/MenaKod'));
    }

    public function testForeignCurrencySetsValutyPropFlag(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice(['currency' => 'EUR'])]);

        self::assertSame('1', $this->xpathOne($xml, '//FaktVyd/ValutyProp'));
        self::assertSame('EUR', $this->xpathOne($xml, '//FaktVyd/MojeFirma/MenaKod'));
    }

    public function testItemLinePerUnitBucketsMatchLineTotalsForSingleUnit(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice()]);

        self::assertSame('32526.00', $this->xpathOne($xml, '//SeznamPolozek/Polozka/SouhrnDPH/Zaklad_MJ'));
        self::assertSame('6830.46', $this->xpathOne($xml, '//SeznamPolozek/Polozka/SouhrnDPH/DPH_MJ'));
        self::assertSame('32526.00', $this->xpathOne($xml, '//SeznamPolozek/Polozka/SouhrnDPH/Zaklad'));
        self::assertSame('6830.46', $this->xpathOne($xml, '//SeznamPolozek/Polozka/SouhrnDPH/DPH'));
    }

    private function xpathOne(string $xml, string $expr): ?string
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Money S3 XML neni well-formed.');
        $xp = new \DOMXPath($dom);
        $node = $xp->query($expr)->item(0);

        return $node?->textContent;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function invoice(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'invoice_type' => 'invoice',
            'varsymbol' => '1026001',
            'issue_date' => '2026-06-16',
            'tax_date' => '2026-06-16',
            'due_date' => '2026-06-30',
            'currency' => 'CZK',
            'payment_method' => 'bank_transfer',
            'created_by' => 7,
            'created_by_name' => 'Chytra Lola',
            'advance_paid_amount' => 0.0,
            'total_with_vat' => 39356.46,
            'amount_to_pay' => 39356.46,
            'constant_symbol' => '0008',
            'client_snapshot' => [
                'company_name' => 'Kamody s.r.o.',
                'street' => 'Sokolska 217',
                'city' => 'Usti nad Orlici',
                'zip' => '56204',
                'country_iso2' => 'CZ',
                'ic' => '27467392',
                'dic' => 'CZ27467392',
            ],
            'supplier_snapshot' => [
                'company_name' => 'SPORT s.r.o.',
                'street' => 'Merhautova 128',
                'city' => 'Brno-Cerna Pole',
                'zip' => '61300',
                'country_iso2' => 'CZ',
                'ic' => '12345678',
                'dic' => 'CZ12345678',
                'email' => 'info@demosport.cz',
                'is_vat_payer' => true,
            ],
            'bank_snapshot' => [
                'account_number' => '7098760359',
                'bank_code' => '0100',
                'bank_name' => 'Komercni banka, a.s.',
            ],
            'items' => [$this->item()],
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'description' => 'prodej zbozi',
            'quantity' => 1.0,
            'unit' => 'ks',
            'unit_price_without_vat' => 32526.0,
            'vat_rate_snapshot' => 21.0,
            'total_without_vat' => 32526.0,
            'total_vat' => 6830.46,
            'total_with_vat' => 39356.46,
        ], $overrides);
    }
}

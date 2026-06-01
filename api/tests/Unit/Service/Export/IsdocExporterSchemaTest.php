<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Import\IsdocParser;
use PHPUnit\Framework\TestCase;

/**
 * Validuje výstup IsdocExporter::buildXml() proti oficiálnímu XSD
 * (api/xsd/isdoc-invoice-6.0.2.xsd, staženo z https://isdoc.cz/6.0.2/xsd/).
 *
 * Co test chytá: strukturální regrese — pořadí elementů v sekvenci, povinné
 * elementy, datové typy. Element ordering je v exportéru ručně udržované (viz
 * komentáře "přesné pořadí dle isdoc-invoice-6.0.2.xsd"), takže je křehké.
 *
 * Co test NEchytá: business-rule omezení, která čisté XSD neumí vyjádřit. ISDOC
 * 6.0.2 nemá žádný <xs:assert> a `LineExtensionAmountCurr` je minOccurs="0", takže
 * pravidlo "doklad v cizí měně musí nést *Curr hodnoty" se validací neověří —
 * to je Schematron-level kontrola. Cizoměnové faktury proto projdou XSD validací
 * i s aktuálním (vůči standardu nekonformním) mapováním cizí měny do <UnitPrice>.
 */
final class IsdocExporterSchemaTest extends TestCase
{
    private const XSD = __DIR__ . '/../../../../xsd/isdoc-invoice-6.0.2.xsd';

    private IsdocExporter $exporter;

    protected function setUp(): void
    {
        if (!is_file(self::XSD)) {
            self::markTestSkipped('ISDOC XSD chybí — spusť cmd/download-xsd.sh isdoc.');
        }
        // buildXml() pracuje čistě nad polem; resolve* sahá na DB jen pro
        // supplier_id/client_id > 0. Faktury níže nesou jen snapshoty, takže
        // stuby repo/db nejsou nikdy zavolané.
        $this->exporter = new IsdocExporter(
            $this->createStub(InvoiceRepository::class),
            $this->createStub(Connection::class),
        );
    }

    public function testDomesticCzkInvoiceIsSchemaValid(): void
    {
        $this->assertValidIsdoc($this->exporter->buildXml($this->invoice()));
    }

    public function testForeignCurrencyInvoiceIsSchemaValid(): void
    {
        $xml = $this->exporter->buildXml($this->invoice([
            'currency'      => 'EUR',
            'exchange_rate' => 24.36,
        ]));
        $this->assertValidIsdoc($xml);
    }

    public function testCreditNoteIsSchemaValid(): void
    {
        $this->assertValidIsdoc($this->exporter->buildXml($this->invoice([
            'invoice_type' => 'credit_note',
        ])));
    }

    // ─── DocumentType číselník (ISDOC 6.0.2) + nedaňový doklad (4.1.5) ───

    public function testCreditNoteIsDocumentType2(): void
    {
        $xml = $this->exporter->buildXml($this->invoice(['invoice_type' => 'credit_note']));
        self::assertSame('2', $this->xpathOne($xml, '//i:Invoice/i:DocumentType'));
    }

    public function testProformaIsDocumentType4AndNonTax(): void
    {
        // Zálohová faktura = nedaňový zálohový list (DocumentType 4). Dle ISDOC 4.1.5 musí
        // být doklad i všechny jeho řádky nedaňové → VATApplicable=false na úrovni dokladu
        // i uvnitř každého ClassifiedTaxCategory. Výstup musí být validní vůči XSD.
        $xml = $this->exporter->buildXml($this->invoice([
            'invoice_type' => 'proforma',
            'items'        => [
                $this->item(['description' => 'Vývoj', 'quantity' => 10.0, 'unit_price_without_vat' => 1000.0]),
                $this->item(['description' => 'Konzultace', 'quantity' => 2.0, 'unit_price_without_vat' => 1500.0, 'vat_rate_snapshot' => 12.0]),
            ],
            'vat_breakdown' => [
                ['rate' => 21.0, 'base' => 10000.0, 'vat' => 2100.0],
                ['rate' => 12.0, 'base' => 3000.0,  'vat' => 360.0],
            ],
            'totals'        => ['without_vat' => 13000.0, 'with_vat' => 15460.0, 'rounding' => 0.0],
            'amount_to_pay' => 15460.0,
        ]));

        $this->assertValidIsdoc($xml);
        self::assertSame('4', $this->xpathOne($xml, '//i:Invoice/i:DocumentType'));
        self::assertSame('false', $this->xpathOne($xml, '//i:Invoice/i:VATApplicable'));

        // 4.1.5: každý řádek nedaňový — VATApplicable=false v ClassifiedTaxCategory.
        self::assertSame(2, $this->xpathCount($xml, '//i:InvoiceLine'));
        self::assertSame(2, $this->xpathCount($xml, '//i:InvoiceLine/i:ClassifiedTaxCategory[i:VATApplicable="false"]'));
    }

    public function testMissingCustomerIcEmitsEmptyIdNotZero(): void
    {
        // Odběratel bez IČO (B2C / fyzická osoba) → <ID> prázdné, ne fiktivní "0".
        // XSD to dovolí (IDType = neomezený xs:string) a doklad zůstává validní.
        $xml = $this->exporter->buildXml($this->invoice([
            'client_snapshot' => [
                'company_name' => 'Jan Novák',
                'street'       => 'Nádražní 7',
                'city'         => 'Ostrava',
                'zip'          => '70030',
                'country_iso2' => 'CZ',
            ],
        ]));

        $this->assertValidIsdoc($xml);
        self::assertSame('', $this->xpathOne($xml, '//i:AccountingCustomerParty/i:Party/i:PartyIdentification/i:ID'));
        // Dodavatel (tenant) IČO má → není prázdné.
        self::assertSame('01698401', $this->xpathOne($xml, '//i:AccountingSupplierParty/i:Party/i:PartyIdentification/i:ID'));
    }

    public function testTaxInvoiceLinesOmitVatApplicable(): void
    {
        // Obrácené pravidlo neplatí: na daňovém dokladu řádky VATApplicable mít nemusejí
        // (vynecháváme ho → položka je daňová). Pojistka proti regresi 4.1.5 do druhé strany.
        $xml = $this->exporter->buildXml($this->invoice());
        self::assertSame('true', $this->xpathOne($xml, '//i:Invoice/i:VATApplicable'));
        self::assertSame(0, $this->xpathCount($xml, '//i:InvoiceLine/i:ClassifiedTaxCategory/i:VATApplicable'));
    }

    public function testReverseChargeInvoiceIsSchemaValid(): void
    {
        $this->assertValidIsdoc($this->exporter->buildXml($this->invoice([
            'reverse_charge' => true,
        ])));
    }

    public function testReverseChargeUnitPriceTaxInclusiveMatchesNet(): void
    {
        // Tuzemský §92 reverse charge: nominální sazba 21 %, ale daň se přenáší → 0.
        // Jednotková cena s DPH NESMÍ být dopočtena nominální sazbou (to by dalo falešné
        // brutto 121 000 vedle LineExtensionAmountTaxInclusive 100 000 → řádek by si protiřečil).
        // Musí sednout na řádkový total_with_vat (= netto, daň 0).
        $xml = $this->exporter->buildXml($this->invoice([
            'reverse_charge' => true,
            'items'          => [$this->item([
                'quantity'               => 1.0,
                'unit_price_without_vat' => 100000.0,
                'vat_rate_snapshot'      => 21.0,
                'total_without_vat'      => 100000.0,
                'total_vat'              => 0.0,
                'total_with_vat'         => 100000.0,
            ])],
            'vat_breakdown'  => [['rate' => 21.0, 'base' => 100000.0, 'vat' => 0.0]],
            'totals'         => ['without_vat' => 100000.0, 'with_vat' => 100000.0, 'rounding' => 0.0],
            'amount_to_pay'  => 100000.0,
        ]));

        $this->assertValidIsdoc($xml);
        self::assertSame('100000.00', $this->xpathOne($xml, '//i:InvoiceLine/i:UnitPrice'));
        self::assertSame('100000.00', $this->xpathOne($xml, '//i:InvoiceLine/i:UnitPriceTaxInclusive'));
        self::assertSame('100000.00', $this->xpathOne($xml, '//i:InvoiceLine/i:LineExtensionAmountTaxInclusive'));
        self::assertSame('0.00', $this->xpathOne($xml, '//i:InvoiceLine/i:LineExtensionTaxAmount'));
        // Rekapitulace nese RC příznak s nominální sazbou.
        self::assertSame('true', $this->xpathOne($xml, '//i:TaxTotal/i:TaxSubTotal/i:TaxCategory/i:LocalReverseChargeFlag'));
        self::assertSame('21.00', $this->xpathOne($xml, '//i:TaxTotal/i:TaxSubTotal/i:TaxCategory/i:Percent'));
    }

    public function testNormalInvoiceUnitPriceTaxInclusiveIsGross(): void
    {
        // Kontrola, že běžná (ne-RC) faktura má UnitPriceTaxInclusive = brutto (netto + DPH).
        $xml = $this->exporter->buildXml($this->invoice());
        self::assertSame('2520.00', $this->xpathOne($xml, '//i:InvoiceLine/i:UnitPrice'));
        self::assertSame('3049.20', $this->xpathOne($xml, '//i:InvoiceLine/i:UnitPriceTaxInclusive'));
        self::assertSame('3049.20', $this->xpathOne($xml, '//i:InvoiceLine/i:LineExtensionAmountTaxInclusive'));
    }

    public function testMultiItemInvoiceWithProjectAndContractIsSchemaValid(): void
    {
        $this->assertValidIsdoc($this->exporter->buildXml($this->invoice([
            'project_number'  => 'OBJ-2026-12',
            'contract_number' => 'SML-7',
            'items'           => [
                $this->item(['description' => 'Vývoj', 'quantity' => 10.0, 'unit_price_without_vat' => 1000.0]),
                $this->item(['description' => 'Konzultace', 'quantity' => 2.0, 'unit_price_without_vat' => 1500.0, 'vat_rate_snapshot' => 12.0]),
            ],
            'vat_breakdown'   => [
                ['rate' => 21.0, 'base' => 10000.0, 'vat' => 2100.0],
                ['rate' => 12.0, 'base' => 3000.0,  'vat' => 360.0],
            ],
            'totals'          => ['without_vat' => 13000.0, 'with_vat' => 15460.0, 'rounding' => 0.0],
            'amount_to_pay'   => 15460.0,
        ])));
    }

    // ─── Měnová sémantika (business rule, kterou XSD samo nevynutí) ───

    public function testForeignCurrencyEmitsLocalCzkBaseAndForeignCurr(): void
    {
        // 1 ks à 2520 EUR, kurz 24.36 → base v CZK = 61 387.20, Curr v EUR = 2520.00.
        $xml = $this->exporter->buildXml($this->invoice([
            'currency'      => 'EUR',
            'exchange_rate' => 24.36,
            'amount_to_pay' => 3049.2,   // s DPH
        ]));

        // <UnitPrice> je dle ISDOC vždy lokální (CZK) a Curr variantu nemá.
        self::assertSame('61387.20', $this->xpathOne($xml, '//i:InvoiceLine/i:UnitPrice'));
        self::assertNull($this->xpathOne($xml, '//i:InvoiceLine/i:UnitPriceCurr'), 'UnitPrice nemá Curr variantu');

        // LineExtensionAmount: base v CZK, Curr v EUR.
        self::assertSame('2520.00',  $this->xpathOne($xml, '//i:InvoiceLine/i:LineExtensionAmountCurr'));
        self::assertSame('61387.20', $this->xpathOne($xml, '//i:InvoiceLine/i:LineExtensionAmount'));

        // Celková částka k zaplacení: PayableAmount v CZK (3049.2 × 24.36), PayableAmountCurr v EUR.
        self::assertSame('74278.51', $this->xpathOne($xml, '//i:LegalMonetaryTotal/i:PayableAmount'));
        self::assertSame('3049.20',  $this->xpathOne($xml, '//i:LegalMonetaryTotal/i:PayableAmountCurr'));
    }

    public function testDomesticInvoiceEmitsNoCurrElementsAndIsUnchanged(): void
    {
        $xml = $this->exporter->buildXml($this->invoice(['amount_to_pay' => 3049.2]));

        // ISDOC pravidlo: bez <ForeignCurrencyCode> nesmí existovat žádný *Curr element.
        self::assertSame(0, substr_count($xml, 'Curr>'), 'CZK faktura nesmí mít žádné *Curr elementy');
        self::assertNull($this->xpathOne($xml, '//i:ForeignCurrencyCode'));

        // Base hodnoty zůstávají v CZK = vstupní hodnoty (kurz 1).
        self::assertSame('2520.00', $this->xpathOne($xml, '//i:InvoiceLine/i:UnitPrice'));
        self::assertSame('3049.20', $this->xpathOne($xml, '//i:LegalMonetaryTotal/i:PayableAmount'));
    }

    public function testForeignCurrencyRoundTripsThroughParser(): void
    {
        // Export EUR faktury → import zpět → jednotková cena musí být v EUR (ne CZK).
        $xml = $this->exporter->buildXml($this->invoice([
            'currency'      => 'EUR',
            'exchange_rate' => 24.36,
            'items'         => [$this->item([
                'quantity'               => 4.0,
                'unit_price_without_vat' => 125.0,
                'total_without_vat'      => 500.0,
                'total_vat'              => 105.0,
                'total_with_vat'         => 605.0,
            ])],
            'vat_breakdown' => [['rate' => 21.0, 'base' => 500.0, 'vat' => 105.0]],
            'totals'        => ['without_vat' => 500.0, 'with_vat' => 605.0, 'rounding' => 0.0],
            'amount_to_pay' => 605.0,
        ]));

        $parsed = (new IsdocParser())->parse($xml);
        $invoice = $parsed['invoices'][0];

        self::assertSame('EUR', $invoice['currency']);
        // LineExtensionAmountCurr (500 EUR) / qty 4 = 125 EUR.
        self::assertSame(125.0, $invoice['items'][0]['unit_price_without_vat']);
    }

    /**
     * Vrátí textový obsah prvního uzlu pro XPath výraz (namespace `i:`), nebo null.
     */
    private function xpathOne(string $xml, string $expr): ?string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('i', 'http://isdoc.cz/namespace/2013');
        $node = $xp->query($expr)->item(0);
        return $node?->textContent;
    }

    /** Počet uzlů odpovídajících XPath výrazu (namespace `i:`). */
    private function xpathCount(string $xml, string $expr): int
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('i', 'http://isdoc.cz/namespace/2013');
        return $xp->query($expr)->length;
    }

    /**
     * Reálná data MyInvoice (snapshoty) — co vrací InvoiceRepository::find().
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function invoice(array $overrides = []): array
    {
        $base = [
            'id'               => 1,
            'invoice_type'     => 'invoice',
            'varsymbol'        => '2026001',
            'issue_date'       => '2026-05-04',
            'tax_date'         => '2026-05-04',
            'due_date'         => '2026-05-18',
            'currency'         => 'CZK',
            'exchange_rate'    => null,
            'reverse_charge'   => false,
            'project_number'   => null,
            'contract_number'  => null,
            'advance_paid_amount' => 0.0,
            'amount_to_pay'    => 2520.0,
            'supplier_snapshot' => [
                'ic'           => '01698401',
                'dic'          => 'CZ01698401',
                'company_name' => 'Dodavatel s.r.o.',
                'street'       => 'Kardinála Berana 1104/36',
                'city'         => 'Plzeň',
                'zip'          => '30100',
                'country_iso2' => 'CZ',
                'main_email'   => 'fakturace@dodavatel.cz',
            ],
            'client_snapshot'  => [
                'ic'           => '27140130',
                'dic'          => 'CZ27140130',
                'company_name' => 'Odběratel a.s.',
                'street'       => 'Václavské náměstí 1',
                'city'         => 'Praha 1',
                'zip'          => '11000',
                'country_iso2' => 'CZ',
            ],
            'bank_snapshot'    => [
                'account_number' => '1000000005',
                'bank_code'      => '0100',
                'bank_name'      => 'Komerční banka',
            ],
            'items'            => [$this->item()],
            'vat_breakdown'    => [['rate' => 21.0, 'base' => 2520.0, 'vat' => 529.2]],
            'totals'           => ['without_vat' => 2520.0, 'with_vat' => 3049.2, 'rounding' => 0.0],
        ];

        return array_merge($base, $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'description'            => 'Vývoj systému',
            'quantity'               => 1.0,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 2520.0,
            'vat_rate_snapshot'      => 21.0,
            'total_without_vat'      => 2520.0,
            'total_vat'              => 529.2,
            'total_with_vat'         => 3049.2,
        ], $overrides);
    }

    private function assertValidIsdoc(string $xml): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Export není well-formed XML.');

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $dom->schemaValidate(self::XSD);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            $lines = array_map(
                static fn (\LibXMLError $e): string => sprintf('  [ř. %d] %s', $e->line, trim($e->message)),
                $errors,
            );
            self::fail("ISDOC XML není validní vůči isdoc-invoice-6.0.2.xsd:\n" . implode("\n", $lines));
        }

        self::assertTrue($ok);
    }
}

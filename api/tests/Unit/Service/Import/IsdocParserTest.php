<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IsdocParser;
use PHPUnit\Framework\TestCase;

final class IsdocParserTest extends TestCase
{
    private const NS = 'http://isdoc.cz/namespace/2013';

    private IsdocParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IsdocParser();
    }

    private function minimalIsdoc(): string
    {
        $ns = self::NS;
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="$ns">
  <DocumentType>1</DocumentType>
  <ID>2605001</ID>
  <IssueDate>2026-05-01</IssueDate>
  <TaxPointDate>2026-05-01</TaxPointDate>
  <PaymentMeans>
    <Payment>
      <Details>
        <PaymentDueDate>2026-05-15</PaymentDueDate>
      </Details>
    </Payment>
  </PaymentMeans>
  <LocalCurrencyCode>CZK</LocalCurrencyCode>
  <CurrencyCode>CZK</CurrencyCode>
  <AccountingSupplierParty>
    <Party>
      <PartyIdentification>
        <ID>21370362</ID>
      </PartyIdentification>
    </Party>
  </AccountingSupplierParty>
  <AccountingCustomerParty>
    <Party>
      <PartyName>
        <Name>Test Klient s.r.o.</Name>
      </PartyName>
      <PartyIdentification>
        <ID>12345678</ID>
      </PartyIdentification>
    </Party>
  </AccountingCustomerParty>
  <InvoiceLines>
    <InvoiceLine>
      <Item>
        <Description>Konzultace</Description>
      </Item>
      <InvoicedQuantity unitCode="hod">10</InvoicedQuantity>
      <UnitPrice>1500</UnitPrice>
      <ClassifiedTaxCategory>
        <Percent>21</Percent>
      </ClassifiedTaxCategory>
    </InvoiceLine>
  </InvoiceLines>
</Invoice>
XML;
    }

    public function testHappyPathExtractsBasicFields(): void
    {
        $result = $this->parser->parse($this->minimalIsdoc());
        self::assertSame('21370362', $result['supplier_ic']);
        self::assertCount(1, $result['invoices']);

        $inv = $result['invoices'][0];
        self::assertArrayNotHasKey('__error', $inv);
        self::assertSame('invoice', $inv['invoice_type']);
        self::assertSame('2605001', $inv['varsymbol']);
        self::assertSame('2026-05-01', $inv['issue_date']);
        self::assertSame('2026-05-15', $inv['due_date']);
        self::assertSame('CZK', $inv['currency']);
        self::assertSame('Test Klient s.r.o.', $inv['client']['company_name']);
        self::assertSame('12345678', $inv['client']['ic']);
        self::assertCount(1, $inv['items']);
        self::assertSame(10.0, $inv['items'][0]['quantity']);
        self::assertSame('hod', $inv['items'][0]['unit']);
        self::assertSame(1500.0, $inv['items'][0]['unit_price_without_vat']);
        self::assertSame(21.0, $inv['items'][0]['vat_rate']);
    }

    public function testPayableAmountNullWhenNoMonetaryTotal(): void
    {
        $inv = $this->parser->parse($this->minimalIsdoc())['invoices'][0];
        self::assertNull($inv['payable_amount'], 'bez <LegalMonetaryTotal> je payable_amount null');
    }

    public function testExtractsPayableAmountWithRounding(): void
    {
        // Doklad se zaokrouhlením: základ+DPH 999,99, k úhradě po zaokrouhlení
        // 1000,00 (PayableRoundingAmount +0,01). Parser musí vrátit 1000,00.
        $monetary = <<<XML
  <LegalMonetaryTotal>
    <TaxExclusiveAmount>826.44</TaxExclusiveAmount>
    <TaxInclusiveAmount>999.99</TaxInclusiveAmount>
    <PayableRoundingAmount>0.01</PayableRoundingAmount>
    <PaidDepositsAmount>0</PaidDepositsAmount>
    <PayableAmount>1000.00</PayableAmount>
  </LegalMonetaryTotal>

XML;
        $xml = str_replace('</Invoice>', $monetary . '</Invoice>', $this->minimalIsdoc());
        $inv = $this->parser->parse($xml)['invoices'][0];
        self::assertSame(1000.0, $inv['payable_amount'], 'payable_amount = PayableAmount (k úhradě po zaokrouhlení)');
    }

    public function testPaymentAccountAbsentWhenOnlyDueDate(): void
    {
        $inv = $this->parser->parse($this->minimalIsdoc())['invoices'][0];
        self::assertArrayHasKey('payment', $inv);
        self::assertNull($inv['payment']['account_number']);
        self::assertNull($inv['payment']['iban']);
        self::assertNull($inv['payment']['variable_symbol']);
    }

    public function testExtractsPaymentAccountFromPaymentMeans(): void
    {
        $ns = self::NS;
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="$ns">
  <DocumentType>1</DocumentType>
  <ID>2605002</ID>
  <IssueDate>2026-05-01</IssueDate>
  <PaymentMeans>
    <Payment>
      <PaymentMeansCode>42</PaymentMeansCode>
      <Details>
        <PaymentDueDate>2026-05-15</PaymentDueDate>
        <ID>19-2000145399</ID>
        <BankCode>0800</BankCode>
        <Name>Česká spořitelna</Name>
        <IBAN>CZ65 0800 0000 1920 0014 5399</IBAN>
        <BIC>GIBACZPX</BIC>
        <VariableSymbol>2605002</VariableSymbol>
      </Details>
    </Payment>
  </PaymentMeans>
  <LocalCurrencyCode>CZK</LocalCurrencyCode>
  <AccountingSupplierParty>
    <Party><PartyIdentification><ID>21370362</ID></PartyIdentification></Party>
  </AccountingSupplierParty>
  <AccountingCustomerParty>
    <Party><PartyIdentification><ID>12345678</ID></PartyIdentification></Party>
  </AccountingCustomerParty>
  <InvoiceLines/>
</Invoice>
XML;
        $inv = $this->parser->parse($xml)['invoices'][0];
        self::assertSame('19-2000145399', $inv['payment']['account_number']);
        self::assertSame('0800', $inv['payment']['bank_code']);
        self::assertSame('CZ6508000000192000145399', $inv['payment']['iban']); // mezery odstraněny
        self::assertSame('GIBACZPX', $inv['payment']['bic']);
        self::assertSame('2605002', $inv['payment']['variable_symbol']);
    }

    public function testRejectsDoctypeBecauseOfXxe(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<Invoice xmlns="http://isdoc.cz/namespace/2013">
  <ID>&xxe;</ID>
</Invoice>
XML;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DOCTYPE/i');
        $this->parser->parse($xml);
    }

    public function testRejectsBillionLaughsViaDoctype(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE lolz [
  <!ENTITY lol "lol">
  <!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">
]>
<Invoice xmlns="http://isdoc.cz/namespace/2013">
  <ID>&lol2;</ID>
</Invoice>
XML;
        $this->expectException(\RuntimeException::class);
        $this->parser->parse($xml);
    }

    public function testRejectsNonInvoiceRoot(): void
    {
        $xml = '<?xml version="1.0"?><WrongRoot/>';
        $this->expectException(\RuntimeException::class);
        $this->parser->parse($xml);
    }

    public function testRejectsMalformedXml(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->parser->parse('<not xml>');
    }

    public function testProformaIsRecognized(): void
    {
        $xml = str_replace('<DocumentType>1</DocumentType>', '<DocumentType>4</DocumentType>', $this->minimalIsdoc());
        $result = $this->parser->parse($xml);
        self::assertSame('proforma', $result['invoices'][0]['invoice_type']);
    }

    public function testCreditNoteIsRecognized(): void
    {
        $xml = str_replace('<DocumentType>1</DocumentType>', '<DocumentType>2</DocumentType>', $this->minimalIsdoc());
        $result = $this->parser->parse($xml);
        self::assertSame('credit_note', $result['invoices'][0]['invoice_type']);
    }

    public function testReverseChargeFromLocalReverseChargeFlag(): void
    {
        // Reverse charge se čte z <LocalReverseChargeFlag>, ne z <VATApplicable>.
        $xml = str_replace(
            '<LocalCurrencyCode>CZK</LocalCurrencyCode>',
            '<LocalCurrencyCode>CZK</LocalCurrencyCode>'
            . '<TaxTotal><TaxSubTotal><TaxCategory>'
            . '<LocalReverseChargeFlag>true</LocalReverseChargeFlag>'
            . '</TaxCategory></TaxSubTotal></TaxTotal>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        self::assertTrue($result['invoices'][0]['reverse_charge']);
    }

    public function testVatApplicableFalseIsNotReverseCharge(): void
    {
        // issue #41: VATApplicable=false = neplátce DPH / plnění mimo DPH (typicky
        // faktura z iDokladu od neplátce) — NENÍ to reverse charge.
        $xml = str_replace(
            '<LocalCurrencyCode>CZK</LocalCurrencyCode>',
            '<VATApplicable>false</VATApplicable><LocalCurrencyCode>CZK</LocalCurrencyCode>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        self::assertFalse($result['invoices'][0]['reverse_charge']);
    }

    public function testNonTaxDocumentZeroesLineVatRate(): void
    {
        // VATApplicable=false na dokladu (neplátce / nedaňový zálohový list) → dle ISDOC
        // 4.1.5 jsou nedaňové i řádky, takže Percent (zde 21) NEimportujeme jako sazbu,
        // ale jako 0. Zabraňuje nárokování DPH z nedaňového dokladu (feedback_tax_audit).
        $xml = str_replace(
            '<LocalCurrencyCode>CZK</LocalCurrencyCode>',
            '<VATApplicable>false</VATApplicable><LocalCurrencyCode>CZK</LocalCurrencyCode>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        $inv = $result['invoices'][0];
        self::assertSame(0.0, $inv['items'][0]['vat_rate']);
        self::assertSame([], $inv['vat_recap']);
    }

    public function testNonTaxLineZeroesVatRateOnTaxDocument(): void
    {
        // Obrácené pravidlo 4.1.5: na daňovém dokladu může být jednotlivá nedaňová
        // položka (ClassifiedTaxCategory/VATApplicable=false) → její sazba je 0.
        $xml = str_replace(
            '<Percent>21</Percent>',
            '<Percent>21</Percent><VATCalculationMethod>0</VATCalculationMethod><VATApplicable>false</VATApplicable>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        self::assertSame(0.0, $result['invoices'][0]['items'][0]['vat_rate']);
    }

    // ─── Round-trip se schema-validním ISDOC 6.0.2 (od v3.6.2) ──────────────
    //
    // Tyto testy chrání proti regresi z 2026-05-18: exporter byl ve v3.6.2
    // upraven proti oficiální XSD (ForeignCurrencyCode, OrderReferences/SalesOrderID,
    // ContractReferences/ID, StreetName + BuildingNumber), ale parser zůstal
    // na legacy cestách — round-trip MyInvoice → ISDOC → MyInvoice ztratil
    // cizí měnu, project_number i číslo popisné. Tady ověřujeme, že parser
    // čte oba formáty (legacy CurrencyCode i schema-validní ForeignCurrencyCode).

    public function testForeignCurrencyCodeIsReadAsCurrency(): void
    {
        // Schema-validní podoba: <ForeignCurrencyCode> místo legacy <CurrencyCode>.
        $xml = str_replace(
            '<CurrencyCode>CZK</CurrencyCode>',
            '<ForeignCurrencyCode>EUR</ForeignCurrencyCode>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('EUR', $result['invoices'][0]['currency']);
    }

    public function testLegacyCurrencyCodeStillReadAsFallback(): void
    {
        // ISDOC od jiných systémů může pořád používat legacy <CurrencyCode>.
        $xml = str_replace(
            '<CurrencyCode>CZK</CurrencyCode>',
            '<CurrencyCode>USD</CurrencyCode>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('USD', $result['invoices'][0]['currency']);
    }

    public function testOrderReferencesWithSalesOrderID(): void
    {
        // Schema-validní wrapper struktura: <OrderReferences>/<OrderReference id="O1">/<SalesOrderID>.
        $xml = str_replace(
            '</AccountingCustomerParty>',
            '</AccountingCustomerParty>'
                . '<OrderReferences><OrderReference id="O1"><SalesOrderID>PRJ-2026-007</SalesOrderID></OrderReference></OrderReferences>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('PRJ-2026-007', $result['invoices'][0]['project_number']);
    }

    public function testLegacyOrderReferenceIDStillRead(): void
    {
        // Starší / non-conforming forma: <OrderReference>/<ID> přímo v rootu.
        $xml = str_replace(
            '</AccountingCustomerParty>',
            '</AccountingCustomerParty><OrderReference><ID>OLD-FORMAT-1</ID></OrderReference>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('OLD-FORMAT-1', $result['invoices'][0]['project_number']);
    }

    public function testContractReferencesWrapperReadAsProjectNumberFallback(): void
    {
        // Pokud OrderReferences chybí, padáme zpět na ContractReferences/ContractReference/ID.
        $xml = str_replace(
            '</AccountingCustomerParty>',
            '</AccountingCustomerParty>'
                . '<ContractReferences><ContractReference id="C1"><ID>SML-2026-42</ID><IssueDate>2026-01-15</IssueDate></ContractReference></ContractReferences>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('SML-2026-42', $result['invoices'][0]['project_number']);
    }

    public function testStreetNameAndBuildingNumberAreJoinedIntoStreet(): void
    {
        // Schema rozděluje adresu na <StreetName> + <BuildingNumber>; parser je
        // při čtení slévá zpátky do jednoho `street` (model má jen jedno pole).
        $xml = str_replace(
            '<PartyIdentification>
        <ID>12345678</ID>
      </PartyIdentification>',
            '<PartyIdentification><ID>12345678</ID></PartyIdentification>'
                . '<PostalAddress>'
                . '<StreetName>Vinohradská</StreetName>'
                . '<BuildingNumber>2233/100</BuildingNumber>'
                . '<CityName>Praha 3</CityName>'
                . '<PostalZone>13000</PostalZone>'
                . '<Country><IdentificationCode>CZ</IdentificationCode></Country>'
                . '</PostalAddress>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('Vinohradská 2233/100', $result['invoices'][0]['client']['street']);
        self::assertSame('Praha 3', $result['invoices'][0]['client']['city']);
    }

    public function testForeignCurrencyUnitPriceIsReadFromLineExtensionAmountCurr(): void
    {
        $ns = self::NS;
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="$ns">
  <DocumentType>1</DocumentType>
  <ID>2026-0007</ID>
  <IssueDate>2026-05-04</IssueDate>
  <LocalCurrencyCode>CZK</LocalCurrencyCode>
  <ForeignCurrencyCode>EUR</ForeignCurrencyCode>
  <CurrRate>24.36</CurrRate>
  <RefCurrRate>1</RefCurrRate>
  <PaymentMeans><Payment><Details><PaymentDueDate>2026-05-18</PaymentDueDate></Details></Payment></PaymentMeans>
  <AccountingSupplierParty><Party><PartyIdentification><ID>01698401</ID></PartyIdentification></Party></AccountingSupplierParty>
  <AccountingCustomerParty><Party><PartyIdentification><ID>27140130</ID></PartyIdentification></Party></AccountingCustomerParty>
  <InvoiceLines>
    <InvoiceLine>
      <InvoicedQuantity unitCode="ks">1.0</InvoicedQuantity>
      <LineExtensionAmountCurr>2520.0</LineExtensionAmountCurr>
      <LineExtensionAmount>61387.2</LineExtensionAmount>
      <UnitPrice>61387.2</UnitPrice>
      <ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory>
      <Item><Description>Vývoj systému</Description></Item>
    </InvoiceLine>
  </InvoiceLines>
</Invoice>
XML;
        $result = $this->parser->parse($xml);
        $item = $result['invoices'][0]['items'][0];
        self::assertSame('EUR', $result['invoices'][0]['currency']);
        self::assertSame(2520.0, $item['unit_price_without_vat']);
    }

    public function testForeignCurrencyMultipleQuantityUnitPriceDividedCorrectly(): void
    {
        $ns = self::NS;
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="$ns">
  <DocumentType>1</DocumentType>
  <ID>2026-0008</ID>
  <IssueDate>2026-05-04</IssueDate>
  <LocalCurrencyCode>CZK</LocalCurrencyCode>
  <ForeignCurrencyCode>EUR</ForeignCurrencyCode>
  <CurrRate>24.36</CurrRate>
  <RefCurrRate>1</RefCurrRate>
  <PaymentMeans><Payment><Details><PaymentDueDate>2026-05-18</PaymentDueDate></Details></Payment></PaymentMeans>
  <AccountingSupplierParty><Party><PartyIdentification><ID>01698401</ID></PartyIdentification></Party></AccountingSupplierParty>
  <AccountingCustomerParty><Party><PartyIdentification><ID>27140130</ID></PartyIdentification></Party></AccountingCustomerParty>
  <InvoiceLines>
    <InvoiceLine>
      <InvoicedQuantity unitCode="hod">5.0</InvoicedQuantity>
      <LineExtensionAmountCurr>500.0</LineExtensionAmountCurr>
      <LineExtensionAmount>12180.0</LineExtensionAmount>
      <UnitPrice>12180.0</UnitPrice>
      <ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory>
      <Item><Description>Konzultace</Description></Item>
    </InvoiceLine>
  </InvoiceLines>
</Invoice>
XML;
        $result = $this->parser->parse($xml);
        $item = $result['invoices'][0]['items'][0];
        self::assertSame(100.0, $item['unit_price_without_vat']);
    }

    public function testForeignCurrencyFallsBackToUnitPriceWhenNoLineExtensionAmountCurr(): void
    {
        $ns = self::NS;
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="$ns">
  <DocumentType>1</DocumentType>
  <ID>2026-0009</ID>
  <IssueDate>2026-05-04</IssueDate>
  <LocalCurrencyCode>CZK</LocalCurrencyCode>
  <ForeignCurrencyCode>EUR</ForeignCurrencyCode>
  <CurrRate>24.36</CurrRate>
  <RefCurrRate>1</RefCurrRate>
  <PaymentMeans><Payment><Details><PaymentDueDate>2026-05-18</PaymentDueDate></Details></Payment></PaymentMeans>
  <AccountingSupplierParty><Party><PartyIdentification><ID>01698401</ID></PartyIdentification></Party></AccountingSupplierParty>
  <AccountingCustomerParty><Party><PartyIdentification><ID>27140130</ID></PartyIdentification></Party></AccountingCustomerParty>
  <InvoiceLines>
    <InvoiceLine>
      <InvoicedQuantity unitCode="ks">1.0</InvoicedQuantity>
      <LineExtensionAmount>61387.2</LineExtensionAmount>
      <UnitPrice>2520.0</UnitPrice>
      <ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory>
      <Item><Description>Vývoj systému</Description></Item>
    </InvoiceLine>
  </InvoiceLines>
</Invoice>
XML;
        $result = $this->parser->parse($xml);
        $item = $result['invoices'][0]['items'][0];
        self::assertSame(2520.0, $item['unit_price_without_vat']);
    }

    public function testLocalCurrencyDiscountReadFromLineExtensionAmount(): void
    {
        // issue #48: iDoklad u slevy ponechá <UnitPrice> na plné (před-slevové)
        // ceně a slevu promítne jen do <LineExtensionAmount>. Parser musí
        // importovat sníženou efektivní cenu, jinak je součet faktury chybný.
        // Příklad: 1 ks, plná cena 1000, sleva 10 % → LineExtensionAmount 900.
        $xml = str_replace(
            '<UnitPrice>1500</UnitPrice>',
            '<LineExtensionAmount>900</LineExtensionAmount><UnitPrice>1000</UnitPrice>',
            str_replace(
                '<InvoicedQuantity unitCode="hod">10</InvoicedQuantity>',
                '<InvoicedQuantity unitCode="ks">1</InvoicedQuantity>',
                $this->minimalIsdoc()
            )
        );
        $result = $this->parser->parse($xml);
        self::assertSame(900.0, $result['invoices'][0]['items'][0]['unit_price_without_vat']);
    }

    public function testLocalCurrencyDiscountWithMultipleQuantity(): void
    {
        // 4 ks, plná jedn. cena 250 (LineExtensionAmount bez slevy by bylo 1000),
        // sleva → LineExtensionAmount 750 ⇒ efektivní jedn. cena 187.50.
        $xml = str_replace(
            '<UnitPrice>1500</UnitPrice>',
            '<LineExtensionAmount>750</LineExtensionAmount><UnitPrice>250</UnitPrice>',
            str_replace(
                '<InvoicedQuantity unitCode="hod">10</InvoicedQuantity>',
                '<InvoicedQuantity unitCode="ks">4</InvoicedQuantity>',
                $this->minimalIsdoc()
            )
        );
        $result = $this->parser->parse($xml);
        self::assertSame(187.5, $result['invoices'][0]['items'][0]['unit_price_without_vat']);
    }

    public function testLocalCurrencyNoDiscountKeepsUnitPrice(): void
    {
        // Bez slevy: LineExtensionAmount == UnitPrice × qty → ponecháme <UnitPrice>
        // beze změny (žádný zaokrouhlovací drift z dělení). 10 hod × 1500 = 15000.
        $xml = str_replace(
            '<UnitPrice>1500</UnitPrice>',
            '<LineExtensionAmount>15000</LineExtensionAmount><UnitPrice>1500</UnitPrice>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        self::assertSame(1500.0, $result['invoices'][0]['items'][0]['unit_price_without_vat']);
    }

    public function testOwnExportRoundTripDiscountAsNegativeLineIsPreserved(): void
    {
        // Round-trip ochrana: náš IsdocExporter slevu v % NEpromítá do ceny
        // původních položek — materializuje ji jako samostatnou zápornou položku
        // "Sleva 10 %" (InvoiceRepository::materializeDiscountLines). Re-import
        // takového ISDOC musí obě řádky zachovat beze změny:
        //   - běžná položka: LineExtensionAmount == UnitPrice × qty → ponechá UnitPrice
        //   - slevová položka: qty 1, LineExtensionAmount == UnitPrice (záporné) → beze změny
        // (oprava #48 se aktivuje jen při reálném rozdílu, který tu nenastane).
        $ns = self::NS;
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="$ns">
  <DocumentType>1</DocumentType>
  <ID>2605099</ID>
  <IssueDate>2026-05-01</IssueDate>
  <LocalCurrencyCode>CZK</LocalCurrencyCode>
  <CurrencyCode>CZK</CurrencyCode>
  <AccountingSupplierParty><Party><PartyIdentification><ID>21370362</ID></PartyIdentification></Party></AccountingSupplierParty>
  <AccountingCustomerParty><Party><PartyIdentification><ID>12345678</ID></PartyIdentification></Party></AccountingCustomerParty>
  <InvoiceLines>
    <InvoiceLine>
      <InvoicedQuantity unitCode="hod">10</InvoicedQuantity>
      <LineExtensionAmount>15000</LineExtensionAmount>
      <UnitPrice>1500</UnitPrice>
      <ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory>
      <Item><Description>Konzultace</Description></Item>
    </InvoiceLine>
    <InvoiceLine>
      <InvoicedQuantity unitCode="ks">1</InvoicedQuantity>
      <LineExtensionAmount>-1500</LineExtensionAmount>
      <UnitPrice>-1500</UnitPrice>
      <ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory>
      <Item><Description>Sleva 10 %</Description></Item>
    </InvoiceLine>
  </InvoiceLines>
</Invoice>
XML;
        $items = $this->parser->parse($xml)['invoices'][0]['items'];
        self::assertCount(2, $items);
        // Běžná položka — plná cena beze změny.
        self::assertSame('Konzultace', $items[0]['description']);
        self::assertSame(1500.0, $items[0]['unit_price_without_vat']);
        // Slevová položka — záporná cena beze změny.
        self::assertSame('Sleva 10 %', $items[1]['description']);
        self::assertSame(-1500.0, $items[1]['unit_price_without_vat']);
    }

    public function testStreetNameWithoutBuildingNumberStaysIntact(): void
    {
        // Pokud zdrojový ISDOC od jiného systému posílá adresu v jednom poli
        // (jen <StreetName>), parser nesmí přidávat prázdný separator.
        $xml = str_replace(
            '<PartyIdentification>
        <ID>12345678</ID>
      </PartyIdentification>',
            '<PartyIdentification><ID>12345678</ID></PartyIdentification>'
                . '<PostalAddress>'
                . '<StreetName>Karlova 5</StreetName>'
                . '<CityName>Brno</CityName>'
                . '</PostalAddress>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('Karlova 5', $result['invoices'][0]['client']['street']);
    }
}

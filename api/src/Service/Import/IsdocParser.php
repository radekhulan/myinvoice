<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Parser ISDOC 6.0.x — extrahuje fakturu do normalizovaného array.
 *
 * Vrací {supplier_ic, invoices[]} (pro konzistenci se single-file API; ISDOC typicky 1 invoice / file).
 *
 * Reverzní mapování k IsdocExporter — DocumentType, ID, Issue/TaxDate, parties, lines, totals.
 *
 * Output shape per invoice — viz PohodaXmlParser.
 */
final class IsdocParser
{
    /** ISDOC 6.x namespace (náš exporter, iDoklad, Fakturoid…). */
    private const NS_2013 = 'http://isdoc.cz/namespace/2013';
    /** Starší ISDOC 5.x namespace — struktura čtených elementů je kompatibilní. */
    private const NS_LEGACY = 'http://isdoc.cz/namespace/invoice';
    /** Namespace, které parser umí — prefix `i:` se navazuje na ten z kořene dokladu. */
    private const SUPPORTED_NS = [self::NS_2013, self::NS_LEGACY];

    /**
     * @return array{supplier_ic:?string, invoices:list<array<string,mixed>>}
     */
    public function parse(string $xml): array
    {
        // XXE / billion-laughs hardening: odmítni DOCTYPE před libxml parsováním.
        // V PHP 8 / libxml ≥ 2.9 jsou external entities default-off, ale interní
        // entity expansion může pořád způsobit DoS — proto blokujeme DOCTYPE rovnou.
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new \RuntimeException('ISDOC XML obsahuje DOCTYPE, což není povoleno.');
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET zakáže jakékoliv načítání ze sítě; nepoužíváme NOENT.
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded || $dom->documentElement === null) {
            throw new \RuntimeException('Nelze parsovat ISDOC XML.');
        }

        $root = $dom->documentElement;
        if ($root->localName !== 'Invoice') {
            throw new \RuntimeException('Není ISDOC — root není Invoice.');
        }

        // Namespace bereme z kořene dokladu, ať funguje ISDOC 6.x (…/2013) i starší
        // 5.x (…/invoice) — struktura čtených elementů je shodná, liší se jen NS.
        // Dřív byl NS natvrdo 2013, takže 5.2 doklad nenašel ani <ID> a spadl na
        // zavádějící „Chybí ISDOC ID" (issue #208).
        $ns = (string) $root->namespaceURI;
        if (!in_array($ns, self::SUPPORTED_NS, true)) {
            throw new \RuntimeException(sprintf(
                'Nepodporovaný ISDOC namespace „%s". Podporováno je ISDOC 6.x (%s) a 5.x (%s).',
                $ns !== '' ? $ns : '(žádný)',
                self::NS_2013,
                self::NS_LEGACY,
            ));
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('i', $ns);

        try {
            $parsed = $this->parseInvoice($root, $xpath);
        } catch (\Throwable $e) {
            return ['supplier_ic' => null, 'invoices' => [['__error' => $e->getMessage()]]];
        }

        // Top-level supplier_ic — zachováno pro BC s issued invoice import flow.
        // Plus per-invoice `supplier` party data (pro purchase invoice mapper —
        // vendor identifikace včetně adresy, DIČ, kontaktu).
        $supplierIc = $this->text($xpath, 'i:AccountingSupplierParty/i:Party/i:PartyIdentification/i:ID', $root) ?: null;

        return ['supplier_ic' => $supplierIc, 'invoices' => [$parsed]];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseInvoice(\DOMElement $root, \DOMXPath $xpath): array
    {
        // DocumentType dle ISDOC 6.0.2 číselníku DocumentTypeType:
        //   1 = faktura, 2 = dobropis, 3 = vrubopis, 4 = zálohová faktura (nedaňová),
        //   5 = daňový zálohový list, 6 = dobropis DZL, 7 = zjednodušený daň. doklad.
        // Dobropis i jeho zálohová varianta → credit_note; obě zálohové varianty → proforma.
        $docType = (int) ($this->text($xpath, 'i:DocumentType', $root) ?: '1');
        $invoiceType = match ($docType) {
            2, 6    => 'credit_note',
            4, 5    => 'proforma',
            default => 'invoice',
        };

        $varsymbol = $this->text($xpath, 'i:ID', $root);
        if ($varsymbol === '') {
            throw new \RuntimeException('Chybí ISDOC ID (varsymbol).');
        }

        $issueDate = $this->text($xpath, 'i:IssueDate', $root);
        $taxDate   = $this->text($xpath, 'i:TaxPointDate', $root) ?: null;
        $dueDate   = $this->text($xpath, 'i:PaymentMeans/i:Payment/i:Details/i:PaymentDueDate', $root) ?: $issueDate;

        $localCur = strtoupper($this->text($xpath, 'i:LocalCurrencyCode', $root) ?: 'CZK');
        // Schema-validní ISDOC 6.0.2 používá <ForeignCurrencyCode>; starší soubory
        // (i náš vlastní exporter před v3.6.2) používaly <CurrencyCode>. Čteme oboje
        // pro kompatibilitu s exporty od jiných systémů i s naším round-tripem.
        $foreignCur = strtoupper(
            $this->text($xpath, 'i:ForeignCurrencyCode', $root)
            ?: $this->text($xpath, 'i:CurrencyCode', $root)
            ?: ''
        );
        $currency = $foreignCur !== '' ? $foreignCur : $localCur;
        $rate = null;
        $rateRaw = $this->text($xpath, 'i:CurrRate', $root);
        if ($rateRaw !== '' && $currency !== $localCur) {
            $rate = (float) $rateRaw;
        }

        // Reverse charge (přenesená daň. povinnost) = ISDOC <LocalReverseChargeFlag>true</…>
        // na úrovni TaxTotal/TaxSubTotal/TaxCategory. POZOR: <VATApplicable>false</…>
        // NEZNAMENÁ reverse charge — to je neplátce DPH / plnění mimo DPH (např. faktura
        // z iDokladu od neplátce, issue #41). RC proto čteme výhradně z LocalReverseChargeFlag.
        $reverseCharge = false;
        foreach ($xpath->query('.//i:LocalReverseChargeFlag', $root) ?: [] as $flagEl) {
            if (strtolower(trim($flagEl->textContent)) === 'true') {
                $reverseCharge = true;
                break;
            }
        }

        // VATApplicable na úrovni dokladu: false = nedaňový doklad (neplátce DPH /
        // plnění mimo DPH / nedaňový zálohový list, DocumentType 4). Dle ISDOC 4.1.5
        // jsou pak nedaňové i všechny řádky → sazbu i rekapitulaci importujeme jako 0.
        // Chybějící element = daňový doklad (legacy, default true).
        $isTaxDocument = strtolower($this->text($xpath, 'i:VATApplicable', $root)) !== 'false';

        // Klient: AccountingCustomerParty/Party
        $partyEl = $xpath->query('i:AccountingCustomerParty/i:Party', $root)->item(0);
        $client = $partyEl instanceof \DOMElement ? $this->parseParty($xpath, $partyEl) : [];

        // Dodavatel: AccountingSupplierParty/Party — pro purchase invoice mapper,
        // kde my jsme zákazník a potřebujeme vytvořit vendor záznam z těchto dat.
        $supplierPartyEl = $xpath->query('i:AccountingSupplierParty/i:Party', $root)->item(0);
        $supplier = $supplierPartyEl instanceof \DOMElement ? $this->parseParty($xpath, $supplierPartyEl) : [];

        // Project number — schema-validní ISDOC 6.0.2 obaluje reference do wrapper
        // kolekce (<OrderReferences>/<OrderReference>/<SalesOrderID>) a v contract
        // referenci je @id atribut + <ID> element. Starší / non-conforming exporty
        // používaly přímý <OrderReference>/<ID>. Čteme nové cesty jako primární
        // a staré jako fallback pro kompat s ISDOC od jiných systémů.
        $projectNumber = $this->text($xpath, 'i:OrderReferences/i:OrderReference/i:SalesOrderID', $root)
            ?: $this->text($xpath, 'i:OrderReference/i:SalesOrderID', $root)
            ?: $this->text($xpath, 'i:OrderReference/i:ID', $root)
            ?: $this->text($xpath, 'i:ContractReferences/i:ContractReference/i:ID', $root)
            ?: $this->text($xpath, 'i:ContractReference/i:ID', $root)
            ?: null;

        // Items
        $hasForeignCurrency = $currency !== $localCur;
        $items = [];
        foreach ($xpath->query('i:InvoiceLines/i:InvoiceLine', $root) ?: [] as $lineEl) {
            if (!$lineEl instanceof \DOMElement) continue;
            $items[] = $this->parseLine($xpath, $lineEl, $hasForeignCurrency, $isTaxDocument);
        }

        return [
            'invoice_type'   => $invoiceType,
            'varsymbol'      => $varsymbol,
            'issue_date'     => $issueDate,
            'tax_date'       => $taxDate,
            'due_date'       => $dueDate,
            'currency'       => $currency,
            'exchange_rate'  => $rate,
            'reverse_charge' => $reverseCharge,
            'note_above'     => null,
            'project_number' => $projectNumber,
            'client'         => $client,    // AccountingCustomerParty (zákazník)
            'supplier'       => $supplier,  // AccountingSupplierParty (dodavatel — pro purchase invoice mapper)
            // Platební účet dodavatele z <PaymentMeans> — pro „Zaplatit pomocí QR"
            // u přijatých faktur (číslo účtu / IBAN / VS).
            'payment'        => $this->parsePayment($xpath, $root),
            'items'          => $items,
            // Částka „k úhradě" z <LegalMonetaryTotal>/<PayableAmount> — už zahrnuje
            // <PayableRoundingAmount> (haléřové zaokrouhlení dodavatele). Mapper z ní
            // dopočítá rounding offset proti součtu položek (viz IsdocToPurchaseInvoiceMapper).
            // U cizoměnového dokladu preferuje *Curr (v měně faktury, jako řádkové totály).
            'payable_amount' => $this->parsePayableAmount($xpath, $root, $hasForeignCurrency),
            // Rekapitulace DPH po sazbách z <TaxTotal>/<TaxSubTotal> — pro seed
            // override (PurchaseVatRecapSeeder), aby naše evidence seděla na doklad.
            // Nedaňový doklad (VATApplicable=false) DPH nepřiznává → prázdná rekapitulace.
            'vat_recap'      => $isTaxDocument ? $this->parseTaxRecap($xpath, $root) : [],
        ];
    }

    /**
     * Rekapitulace DPH po sazbách z `<TaxTotal>/<TaxSubTotal>`.
     *
     * Vrací rateKey (`number_format(rate,2,'.','')`) => kladné `{base, vat}`.
     * U cizoměnových dokladů preferuje `*Curr` varianty (TaxableAmountCurr/
     * TaxAmountCurr), protože ty jsou v měně faktury — stejně jako naše řádkové
     * totály; `TaxableAmount`/`TaxAmount` jsou dle ISDOC vždy v lokální měně (CZK).
     * Sazba 0 % se vynechá (není co srovnávat).
     *
     * @return array<string,array{base:float,vat:float}>
     */
    private function parseTaxRecap(\DOMXPath $xpath, \DOMElement $root): array
    {
        $out = [];
        foreach ($xpath->query('i:TaxTotal/i:TaxSubTotal', $root) ?: [] as $sub) {
            if (!$sub instanceof \DOMElement) {
                continue;
            }
            $percent = $this->text($xpath, 'i:TaxCategory/i:Percent', $sub);
            if ($percent === '') {
                continue;
            }
            $rate = (float) $percent;
            if ($rate <= 0.0) {
                continue;
            }
            $baseCurr = $this->text($xpath, 'i:TaxableAmountCurr', $sub);
            $vatCurr  = $this->text($xpath, 'i:TaxAmountCurr', $sub);
            $base = (float) (($baseCurr !== '' ? $baseCurr : $this->text($xpath, 'i:TaxableAmount', $sub)) ?: '0');
            $vat  = (float) (($vatCurr !== '' ? $vatCurr : $this->text($xpath, 'i:TaxAmount', $sub)) ?: '0');
            $key = number_format($rate, 2, '.', '');
            if (!isset($out[$key])) {
                $out[$key] = ['base' => 0.0, 'vat' => 0.0];
            }
            $out[$key]['base'] += abs($base);
            $out[$key]['vat']  += abs($vat);
        }
        return $out;
    }

    /**
     * Platební údaje dodavatele z `<PaymentMeans>/<Payment>/<Details>`.
     * Schema generuje BankAccount group jako INLINE elementy (žádný <BankAccount>
     * wrapper): ID = číslo účtu, BankCode, IBAN, BIC; plus VariableSymbol.
     * Slouží pro QR platbu u přijatých faktur (zdroj `isdoc`).
     *
     * @return array{account_number:?string,bank_code:?string,iban:?string,bic:?string,variable_symbol:?string}
     */
    private function parsePayment(\DOMXPath $xpath, \DOMElement $root): array
    {
        $base = 'i:PaymentMeans/i:Payment/i:Details/';
        $account = $this->text($xpath, $base . 'i:ID', $root);
        $bank    = $this->text($xpath, $base . 'i:BankCode', $root);
        $iban    = strtoupper((string) preg_replace('/\s+/', '', $this->text($xpath, $base . 'i:IBAN', $root)));
        $bic     = strtoupper((string) preg_replace('/\s+/', '', $this->text($xpath, $base . 'i:BIC', $root)));
        $vs      = $this->text($xpath, $base . 'i:VariableSymbol', $root);

        return [
            'account_number'  => $account !== '' ? $account : null,
            'bank_code'       => $bank !== '' ? $bank : null,
            'iban'            => $iban !== '' ? $iban : null,
            'bic'             => $bic !== '' ? $bic : null,
            'variable_symbol' => $vs !== '' ? $vs : null,
        ];
    }

    /**
     * @return array<string,?string>
     */
    private function parseParty(\DOMXPath $xpath, \DOMElement $party): array
    {
        // Schema rozděluje adresu na <StreetName> + <BuildingNumber>; pro náš
        // model držíme jednu jednolitou hodnotu `street`, takže je při čtení
        // zase slijeme. Pokud BuildingNumber chybí (legacy exporty), použijeme
        // jen StreetName beze změny.
        $streetName = $this->text($xpath, 'i:PostalAddress/i:StreetName', $party);
        $buildingNumber = $this->text($xpath, 'i:PostalAddress/i:BuildingNumber', $party);
        $street = trim($streetName . ($buildingNumber !== '' ? ' ' . $buildingNumber : ''));

        return [
            'company_name' => $this->text($xpath, 'i:PartyName/i:Name', $party) ?: null,
            'ic'           => $this->text($xpath, 'i:PartyIdentification/i:ID', $party) ?: null,
            'dic'          => $this->text($xpath, 'i:PartyTaxScheme/i:CompanyID', $party) ?: null,
            'street'       => $street !== '' ? $street : null,
            'city'         => $this->text($xpath, 'i:PostalAddress/i:CityName', $party) ?: null,
            'zip'          => $this->text($xpath, 'i:PostalAddress/i:PostalZone', $party) ?: null,
            'country_iso2' => strtoupper($this->text($xpath, 'i:PostalAddress/i:Country/i:IdentificationCode', $party)) ?: null,
            'email'        => $this->text($xpath, 'i:Contact/i:ElectronicMail', $party) ?: null,
            'phone'        => $this->text($xpath, 'i:Contact/i:Telephone', $party) ?: null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseLine(\DOMXPath $xpath, \DOMElement $line, bool $hasForeignCurrency = false, bool $isTaxDocument = true): array
    {
        $qtyEl = $xpath->query('i:InvoicedQuantity', $line)->item(0);
        $quantity = $qtyEl instanceof \DOMElement ? (float) $qtyEl->textContent : 1.0;
        $unit = $qtyEl instanceof \DOMElement ? ($qtyEl->getAttribute('unitCode') ?: 'ks') : 'ks';

        $unitPriceLocal = (float) ($this->text($xpath, 'i:UnitPrice', $line) ?: '0');

        // <LineExtensionAmount> = celková částka řádku bez DPH PO slevě. ISDOC 6.0.x
        // nemá na řádce dedikovaný discount element — sleva se promítá jen tím, že
        // LineExtensionAmount < UnitPrice × množství. iDoklad takhle exportuje i
        // dokladovou slevu (DiscountType=OnDocument), kterou rozpočítá do řádků.
        // Když efektivní jednotková cena (LineExtensionAmount / qty) nesedí na
        // <UnitPrice>, je v řádce sleva a musíme importovat sníženou cenu — jinak
        // se faktura naimportuje za plnou (před-slevovou) cenu a součet je chybný
        // (issue #48: import z iDokladu přes PDF s embedded ISDOC ignoroval slevu).
        if ($hasForeignCurrency) {
            // <UnitPrice> je dle ISDOC vždy v lokální měně (CZK), ne v měně faktury.
            // Cizoměnovou jednotkovou cenu (už po slevě) odvodíme z
            // <LineExtensionAmountCurr> / qty. Náš IsdocExporter Curr pole generuje
            // (od fixu cizí měny); fallback na <UnitPrice> drží pro non-konformní
            // exporty cizích systémů, které *Curr vynechají a cizí hodnotu (chybně)
            // zapíšou rovnou do <UnitPrice>.
            $lineAmountCurr = $this->text($xpath, 'i:LineExtensionAmountCurr', $line);
            $unitPrice = ($lineAmountCurr !== '' && $quantity > 0.0)
                ? (float) $lineAmountCurr / $quantity
                : $unitPriceLocal;
        } else {
            $unitPrice = $unitPriceLocal;
            $lineAmount = $this->text($xpath, 'i:LineExtensionAmount', $line);
            if ($lineAmount !== '' && $quantity > 0.0) {
                $effective = (float) $lineAmount / $quantity;
                // Přepsat jen při reálném rozdílu (= je tam sleva); u nediskontovaných
                // řádků ponecháme původní <UnitPrice>, ať dělením nezanášíme
                // zaokrouhlovací drift.
                if (abs($effective - $unitPriceLocal) > 0.005) {
                    $unitPrice = $effective;
                }
            }
        }

        $vatRate = (float) ($this->text($xpath, 'i:ClassifiedTaxCategory/i:Percent', $line) ?: '0');
        // ISDOC 4.1.5: nedaňová položka nepodléhá DPH bez ohledu na Percent. Řádek je
        // nedaňový, je-li nedaňový celý doklad (VATApplicable=false) nebo má-li vlastní
        // <ClassifiedTaxCategory><VATApplicable>false</VATApplicable></…>.
        $lineVatApplicable = strtolower($this->text($xpath, 'i:ClassifiedTaxCategory/i:VATApplicable', $line));
        if (!$isTaxDocument || $lineVatApplicable === 'false') {
            $vatRate = 0.0;
        }

        return [
            'description'            => $this->text($xpath, 'i:Item/i:Description', $line),
            'quantity'               => $quantity,
            'unit'                   => $unit,
            'unit_price_without_vat' => $unitPrice,
            'vat_rate'               => $vatRate,
        ];
    }

    /**
     * Částka „k úhradě" z <LegalMonetaryTotal>/<PayableAmount>. Už zahrnuje
     * <PayableRoundingAmount> (zaokrouhlení) i <PaidDepositsAmount> (zálohy).
     * U cizoměnového dokladu preferuje *Curr (v měně faktury, jako řádkové totály).
     * Vrací null, když doklad částku k úhradě neuvádí.
     */
    private function parsePayableAmount(\DOMXPath $xpath, \DOMElement $root, bool $foreignCurrency): ?float
    {
        $curr = $this->text($xpath, 'i:LegalMonetaryTotal/i:PayableAmountCurr', $root);
        $loc  = $this->text($xpath, 'i:LegalMonetaryTotal/i:PayableAmount', $root);
        $val  = ($foreignCurrency && $curr !== '') ? $curr : $loc;
        return $val !== '' ? (float) $val : null;
    }

    private function text(\DOMXPath $xpath, string $expr, \DOMNode $context): string
    {
        $node = $xpath->query($expr, $context)->item(0);
        return $node ? trim($node->textContent) : '';
    }
}

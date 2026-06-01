<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use Rikudou\Iban\Iban\CzechIbanAdapter;

/**
 * ISDOC 6.0.2 exporter (Czech standard XML invoice format).
 *
 * Spec: http://isdoc.cz/
 * Namespace: http://isdoc.cz/namespace/2013
 *
 * Vyrobí buď single .isdoc XML (pro 1 fakturu) nebo ZIP s více .isdoc soubory.
 *
 * Mapování DocumentType (ISDOC 6.0.2 číselník DocumentTypeType):
 *   1 = běžná faktura (invoice)
 *   2 = opravný daňový doklad / dobropis (credit_note, cancellation)
 *   4 = zálohová faktura — nedaňový zálohový list (proforma); je NEdaňový doklad
 *
 * PaymentMeansCode:
 *   42 = převod (bank transfer) — default pro CZK účty s bank_code
 *   31 = SEPA převod (pro EUR/IBAN)
 *   10 = hotovost
 */
final class IsdocExporter
{
    public const NS = 'http://isdoc.cz/namespace/2013';
    public const VERSION = '6.0.2';

    /**
     * Mapa nejčastějších CZ bankovních kódů → BIC (SWIFT). Používá se jako fallback
     * pro výpočet BIC z `bank_code`, pokud uživatel v `currencies` nemá BIC vyplněný.
     * Pro neznámé kódy se posílá prázdný string (schema dovolí).
     *
     * Zdroj: ČNB číselník platebních styků, stav 2026.
     */
    private const CZ_BANK_BIC = [
        '0100' => 'KOMBCZPP',  // Komerční banka
        '0300' => 'CEKOCZPP',  // ČSOB
        '0600' => 'AGBACZPP',  // MONETA Money Bank
        '0710' => 'CNBACZPP',  // ČNB
        '0800' => 'GIBACZPX',  // Česká spořitelna
        '2010' => 'FIOBCZPP',  // Fio banka
        '2060' => 'CITFCZPP',  // Citfin
        '2070' => 'MPUBCZPP',  // TRINITY BANK
        '2100' => 'HVBCCZPP',  // Hypoteční banka (historické, dnes pod ČSOB)
        '2250' => 'CTASCZ22',  // Banka CREDITAS
        '2600' => 'CITICZPX',  // Citibank
        '2700' => 'BACXCZPP',  // UniCredit Bank
        '3030' => 'AIRACZPP',  // Air Bank
        '3050' => 'BPPFCZP1',  // BNP Paribas Personal Finance
        '3500' => 'INGBCZPP',  // ING Bank
        '4000' => 'EXPNCZPP',  // Expobank
        '5500' => 'RZBCCZPP',  // Raiffeisenbank
        '5800' => 'JTBPCZPP',  // J&T Banka
        '6000' => 'PMBPCZPP',  // PPF banka
        '6200' => 'COBACZPX',  // Commerzbank
        '6210' => 'BREXCZPP',  // mBank
        '6300' => 'GEBACZPP',  // Société Générale (historicky, dnes mBank)
        '6800' => 'VBOECZ2X',  // Sberbank (zaniklá 2022, kódy stále v oběhu)
        '7910' => 'DEUTCZPX',  // Deutsche Bank
        '7940' => 'SPWTCZ21',  // Wüstenrot stavební spořitelna
        '7950' => 'MPCZCZ22',  // Modrá pyramida
        '7960' => 'PPMOCZPP',  // ČSOB Stavební spořitelna
        '7990' => 'MPCZCZP1',  // Wüstenrot
        '8030' => 'GENOCZ21',  // Volksbank (historické)
        '8040' => 'OBKLCZ2X',  // Oberbank
        '8060' => 'PRTKCZP1',  // Stavební spořitelna ČS
        '8090' => 'CZEECZPP',  // Česká exportní banka
        '8150' => 'MIDLCZPP',  // HSBC
        '8200' => 'PRIBCZPP',  // PRIVAT BANK
        '8220' => 'PAYUCZP1',  // PayU
        '8230' => 'EERSCZP1',  // Erste Bank Group
        '8250' => 'BKCHCZPP',  // Bank of China
        '8255' => 'COMMCZPP',  // BNP Paribas SA
        '8265' => 'ICBKCZPP',  // ICBC
    ];

    /**
     * Kurz a foreign-flag aktuálně generované faktury. Nastaveno na začátku
     * buildXml(); čte elAmount()/elAmountCurr() při převodu částek do lokální měny.
     * Stateful, ale buildXml() je synchronní (i v ZIP smyčce 1 faktura po druhé).
     */
    private float $exportRate = 1.0;
    private bool $exportForeign = false;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
    ) {}

    /**
     * @param int[] $invoiceIds
     * @return array{filename:string, content:string, mime:string}
     */
    public function export(array $invoiceIds, string $monthLabel = ''): array
    {
        $invoices = [];
        foreach ($invoiceIds as $id) {
            $inv = $this->repo->find((int) $id);
            if ($inv !== null) $invoices[] = $inv;
        }

        if (empty($invoices)) {
            throw new \RuntimeException('Žádné faktury k exportu.');
        }

        if (count($invoices) === 1) {
            $inv = $invoices[0];
            $vs = $inv['varsymbol'] ?? ('draft-' . $inv['id']);
            return [
                'filename' => "Faktura-{$vs}.isdoc",
                'content'  => $this->buildXml($inv),
                'mime'     => 'application/x-isdoc',
            ];
        }

        // Multi → ZIP
        $tmpZip = tempnam(sys_get_temp_dir(), 'isdoc-') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Nelze vytvořit ZIP.');
        }
        foreach ($invoices as $inv) {
            $vs = $inv['varsymbol'] ?? ('draft-' . $inv['id']);
            $type = match ($inv['invoice_type']) {
                'proforma'    => 'Proforma',
                'credit_note' => 'Dobropis',
                default       => 'Faktura',
            };
            $zip->addFromString("$type-{$vs}.isdoc", $this->buildXml($inv));
        }
        $zip->close();
        $content = (string) file_get_contents($tmpZip);
        @unlink($tmpZip);

        $base = 'isdoc-' . ($monthLabel !== '' ? $monthLabel : date('Y-m-d'));
        return [
            'filename' => "$base.zip",
            'content'  => $content,
            'mime'     => 'application/zip',
        ];
    }

    public function buildXml(array $invoice): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS, 'Invoice');
        $root->setAttribute('version', self::VERSION);
        $dom->appendChild($root);

        $currencyCode = (string) ($invoice['currency'] ?? 'CZK');
        $localCurrency = 'CZK';   // účetní měna českého dodavatele — fixní pro ISDOC export
        $isForeign = $currencyCode !== $localCurrency;
        // Kurz: pro CZK fakturu vždy 1; pro cizí měnu z invoices.exchange_rate (CZK / 1 jednotka).
        // Když cizí měna nemá zafixovaný kurz (legacy data), padá na 1 — accounting soft to vezme jako 1:1
        // a uživatel si musí kurz doplnit. Backfill se snažíme udělat dřív, viz ExchangeRateApplier::ensureRate().
        $rate = $isForeign ? (float) ($invoice['exchange_rate'] ?? 1.0) : 1.0;

        // Částky v $invoice jsou v měně faktury. ISDOC drží base elementy v lokální
        // měně (CZK) a cizoměnové hodnoty v sourozeneckých <…Curr>. elAmount* proto
        // násobí kurzem (CZK = hodnota × kurz) a Curr generuje jen u cizí měny.
        $this->exportRate = $rate;
        $this->exportForeign = $isForeign;

        // ─── ROOT SEQUENCE (přesné pořadí dle isdoc-invoice-6.0.2.xsd) ───
        $docType = match ($invoice['invoice_type']) {
            'proforma'     => 4,  // zálohová faktura = nedaňový zálohový list
            'credit_note'  => 2,  // opravný daňový doklad (dobropis)
            'cancellation' => 2,  // storno řešíme jako dobropis
            default        => 1,  // faktura — daňový doklad
        };
        $this->el($dom, $root, 'DocumentType', (string) $docType);
        $this->el($dom, $root, 'ID', (string) ($invoice['varsymbol'] ?? ('DRAFT-' . $invoice['id'])));
        $this->el($dom, $root, 'UUID', $this->makeUuid($invoice));
        // IssuingSystem patří v root sekvenci před IssueDate (po EgovClassifiers).
        // Identifikuje generátor faktury — užitečné pro debugging accounting SW.
        $this->el($dom, $root, 'IssuingSystem', 'MyInvoice.cz');
        $this->el($dom, $root, 'IssueDate', (string) $invoice['issue_date']);
        if (!empty($invoice['tax_date'])) {
            $this->el($dom, $root, 'TaxPointDate', (string) $invoice['tax_date']);
        }
        // VATApplicable = je doklad předmětem DPH? NEodvozovat z reverse_charge —
        // reverse charge je plátcovský režim a značí se <LocalReverseChargeFlag> v TaxCategory
        // (viz níže). Doklad je nedaňový (false), pokud je dodavatel neplátce DPH NEBO jde
        // o zálohovou fakturu (DocumentType 4 = nedaňový zálohový list — DPH se přiznává až
        // na konečném daňovém dokladu). Jinak plátce (vč. reverse charge) → true.
        $supplier = $this->resolveSupplier($invoice);
        $isVatPayer = !isset($supplier['is_vat_payer']) || !empty($supplier['is_vat_payer']);
        $isProforma = ($invoice['invoice_type'] ?? '') === 'proforma';
        $isTaxDocument = $isVatPayer && !$isProforma;
        $this->el($dom, $root, 'VATApplicable', $isTaxDocument ? 'true' : 'false');
        // ElectronicPossibilityAgreementReference je povinný (minOccurs=1) — pokud
        // dodavatel nemá explicitní souhlasový dokument, posíláme prázdný string.
        $this->el($dom, $root, 'ElectronicPossibilityAgreementReference', '');
        $this->el($dom, $root, 'LocalCurrencyCode', $localCurrency);
        if ($isForeign) {
            // Schema používá <ForeignCurrencyCode> (ne <CurrencyCode>) pro měnu faktury,
            // pokud se liší od LocalCurrencyCode.
            $this->el($dom, $root, 'ForeignCurrencyCode', $currencyCode);
        }
        // CurrRate = počet jednotek místní měny za 1 jednotku faktur. měny (CZK/EUR ≈ 24.36)
        $this->el($dom, $root, 'CurrRate', number_format($rate, 6, '.', ''));
        $this->el($dom, $root, 'RefCurrRate', '1');

        // Supplier (snapshot first, then live) — $supplier už vyřešen výše u VATApplicable
        $supParty = $dom->createElementNS(self::NS, 'AccountingSupplierParty');
        $supParty->appendChild($this->buildParty($dom, $supplier));
        $root->appendChild($supParty);

        // Customer
        $client = $this->resolveClient($invoice);
        $cusParty = $dom->createElementNS(self::NS, 'AccountingCustomerParty');
        $cusParty->appendChild($this->buildParty($dom, $client));
        $root->appendChild($cusParty);

        // OrderReferences/ContractReferences — schema vyžaduje kolekce wrappers
        // s elementy nesoucími @id atribut a vnitřní SalesOrderID resp. ID+IssueDate.
        if (!empty($invoice['project_number'])) {
            $orderRefs = $dom->createElementNS(self::NS, 'OrderReferences');
            $orderRef = $dom->createElementNS(self::NS, 'OrderReference');
            $orderRef->setAttribute('id', 'O1');
            $this->el($dom, $orderRef, 'SalesOrderID', (string) $invoice['project_number']);
            $orderRefs->appendChild($orderRef);
            $root->appendChild($orderRefs);
        }
        if (!empty($invoice['contract_number'])) {
            $contractRefs = $dom->createElementNS(self::NS, 'ContractReferences');
            $contractRef = $dom->createElementNS(self::NS, 'ContractReference');
            $contractRef->setAttribute('id', 'C1');
            $this->el($dom, $contractRef, 'ID', (string) $invoice['contract_number']);
            // IssueDate smlouvy je v ISDOC povinný — nemáme contract_date pole, takže
            // posíláme issue_date faktury jako pragmatic fallback (přijatelné v praxi,
            // účetní SW si pak může uživatel datum opravit).
            $this->el($dom, $contractRef, 'IssueDate', (string) $invoice['issue_date']);
            $contractRefs->appendChild($contractRef);
            $root->appendChild($contractRefs);
        }

        // Invoice lines
        $lines = $dom->createElementNS(self::NS, 'InvoiceLines');
        $items = $invoice['items'] ?? [];
        foreach ($items as $i => $item) {
            $line = $dom->createElementNS(self::NS, 'InvoiceLine');
            $this->el($dom, $line, 'ID', (string) ($i + 1));
            $qty = $this->el($dom, $line, 'InvoicedQuantity', $this->fmt($item['quantity']));
            $qty->setAttribute('unitCode', (string) ($item['unit'] ?? 'ks'));
            $base = (float) ($item['total_without_vat'] ?? 0);
            $vat  = (float) ($item['total_vat'] ?? 0);
            $tot  = (float) ($item['total_with_vat'] ?? 0);
            // InvoiceLine řadí <…Curr> PŘED base sourozence (viz XSD sekvence).
            // UnitPrice/UnitPriceTaxInclusive/LineExtensionTaxAmount Curr variantu
            // nemají — dle standardu jsou vždy v lokální měně.
            // UnitPrice je BEZ DPH. V režimu „ceny s DPH" nese unit_price_without_vat brutto,
            // proto jednotkové ceny dopočítáme z řádkových totálů (netto z base, s DPH z tot).
            $qtyItem = (float) $item['quantity'];
            $pricesInclVat = !empty($invoice['prices_include_vat']);
            $unitPrice = ($pricesInclVat && $qtyItem != 0.0)
                ? round($base / $qtyItem, 2)
                : (float) $item['unit_price_without_vat'];
            // Jednotkovou cenu s DPH odvozujeme z řádkového total_with_vat (ne dopočtem
            // nominální sazbou). Řádkový total už zohledňuje reverse charge i osvobození
            // (daň = 0), takže UnitPriceTaxInclusive sedí s LineExtensionAmountTaxInclusive.
            // Dopočet unitPrice*(1+sazba/100) by u RC dal falešné brutto (sazba 21 %, ale
            // daň se nepřenáší → 0) a řádek by si protiřečil.
            $unitPriceInclVat = $qtyItem != 0.0
                ? round($tot / $qtyItem, 2)
                : $unitPrice;
            $this->elAmountCurr($dom, $line, 'LineExtensionAmount', $base, true);
            $this->elAmountCurr($dom, $line, 'LineExtensionAmountTaxInclusive', $tot, true);
            $this->elAmount($dom, $line, 'LineExtensionTaxAmount', $vat);
            $this->elAmount($dom, $line, 'UnitPrice', $unitPrice);
            $this->elAmount($dom, $line, 'UnitPriceTaxInclusive', $unitPriceInclVat);

            // Na úrovni řádky je správný název <ClassifiedTaxCategory>
            // (na úrovni TaxSubTotal se používá <TaxCategory> — pozor na rozdíl).
            $cat = $dom->createElementNS(self::NS, 'ClassifiedTaxCategory');
            $this->el($dom, $cat, 'Percent', $this->fmt((float) ($item['vat_rate_snapshot'] ?? 0)));
            $this->el($dom, $cat, 'VATCalculationMethod', '0');
            // ISDOC 4.1.5: je-li doklad nedaňový (VATApplicable=false na úrovni dokladu),
            // musejí být nedaňové i všechny řádky → VATApplicable=false uvnitř
            // ClassifiedTaxCategory (XSD sekvence: Percent, VATCalculationMethod, VATApplicable).
            if (!$isTaxDocument) {
                $this->el($dom, $cat, 'VATApplicable', 'false');
            }
            $line->appendChild($cat);

            $itemEl = $dom->createElementNS(self::NS, 'Item');
            $this->el($dom, $itemEl, 'Description', (string) ($item['description'] ?? ''));
            $line->appendChild($itemEl);

            $lines->appendChild($line);
        }
        $root->appendChild($lines);

        // VAT breakdown
        $taxTotal = $dom->createElementNS(self::NS, 'TaxTotal');
        $vatBreakdown = $invoice['vat_breakdown'] ?? [];
        $totalVat = 0.0;
        foreach ($vatBreakdown as $row) {
            $rate = (float) $row['rate'];
            $base = (float) $row['base'];
            $vat  = (float) $row['vat'];
            $totalVat += $vat;

            // TaxSubTotal řadí <…Curr> PŘED base sourozence (viz XSD sekvence).
            $sub = $dom->createElementNS(self::NS, 'TaxSubTotal');
            $this->elAmountCurr($dom, $sub, 'TaxableAmount', $base, true);
            $this->elAmountCurr($dom, $sub, 'TaxAmount', $vat, true);
            $this->elAmountCurr($dom, $sub, 'TaxInclusiveAmount', $base + $vat, true);
            // Required by ISDOC schema (zálohové odpočty — pro běžnou fakturu = 0)
            $this->elAmountCurr($dom, $sub, 'AlreadyClaimedTaxableAmount', 0.0, true);
            $this->elAmountCurr($dom, $sub, 'AlreadyClaimedTaxAmount', 0.0, true);
            $this->elAmountCurr($dom, $sub, 'AlreadyClaimedTaxInclusiveAmount', 0.0, true);
            $this->elAmountCurr($dom, $sub, 'DifferenceTaxableAmount', $base, true);
            $this->elAmountCurr($dom, $sub, 'DifferenceTaxAmount', $vat, true);
            $this->elAmountCurr($dom, $sub, 'DifferenceTaxInclusiveAmount', $base + $vat, true);

            // V TaxSubTotal se používá <TaxCategory> (ne <ClassifiedTaxCategory>!) —
            // sekvence: Percent + TaxScheme? + VATApplicable? + LocalReverseChargeFlag?
            $cat = $dom->createElementNS(self::NS, 'TaxCategory');
            $this->el($dom, $cat, 'Percent', $this->fmt($rate));
            $this->el($dom, $cat, 'TaxScheme', 'VAT');
            // Tuzemský reverse charge — sekvence dle XSD: Percent, TaxScheme?, VATApplicable?,
            // LocalReverseChargeFlag. (VATApplicable na úrovni TaxCategory vynecháváme.)
            if (!empty($invoice['reverse_charge'])) {
                $this->el($dom, $cat, 'LocalReverseChargeFlag', 'true');
            }
            $sub->appendChild($cat);
            $taxTotal->appendChild($sub);
        }
        // TaxTotal: TaxAmountCurr PŘED TaxAmount (viz XSD sekvence).
        $this->elAmountCurr($dom, $taxTotal, 'TaxAmount', $totalVat, true);
        $root->appendChild($taxTotal);

        // Monetary total
        $totals = $invoice['totals'] ?? [];
        $base = (float) ($totals['without_vat'] ?? 0);
        $tot  = (float) ($totals['with_vat'] ?? 0);
        $advance = (float) ($invoice['advance_paid_amount'] ?? 0);
        $payable = (float) ($invoice['amount_to_pay'] ?? $tot);
        $rounding = (float) ($totals['rounding'] ?? 0);

        // LegalMonetaryTotal řadí <…Curr> AŽ ZA base sourozence (opačně než
        // InvoiceLine / TaxTotal — viz XSD sekvence).
        $mon = $dom->createElementNS(self::NS, 'LegalMonetaryTotal');
        $this->elAmountCurr($dom, $mon, 'TaxExclusiveAmount', $base, false);
        $this->elAmountCurr($dom, $mon, 'TaxInclusiveAmount', $tot, false);
        // Záloha je NEDAŇOVÁ proforma — DPH se přiznává až na tomto konečném dokladu,
        // takže `AlreadyClaimed*` (= již daňově zúčtováno z daňových záloh) jsou 0 a
        // `Difference*` = plná hodnota dokladu. Odečtení uhrazené zálohy se komunikuje
        // POUZE přes `PaidDepositsAmount` (snižuje `PayableAmount`). Dřív se sem dávala
        // záloha do AlreadyClaimedTaxInclusive, což bylo vnitřně rozporné (základ 0,
        // ale částka s DPH = celá záloha → nesmyslná implikovaná DPH).
        $this->elAmountCurr($dom, $mon, 'AlreadyClaimedTaxExclusiveAmount', 0.0, false);
        $this->elAmountCurr($dom, $mon, 'AlreadyClaimedTaxInclusiveAmount', 0.0, false);
        $this->elAmountCurr($dom, $mon, 'DifferenceTaxExclusiveAmount', $base, false);
        $this->elAmountCurr($dom, $mon, 'DifferenceTaxInclusiveAmount', $tot, false);
        $this->elAmountCurr($dom, $mon, 'PayableRoundingAmount', $rounding, false);
        $this->elAmountCurr($dom, $mon, 'PaidDepositsAmount', $advance, false);
        $this->elAmountCurr($dom, $mon, 'PayableAmount', $payable, false);
        $root->appendChild($mon);

        // Payment means (bank transfer) — Details má xs:choice mezi Cash a Money transfer
        // větví; my generujeme Money transfer: PaymentDueDate, BankAccount group inline
        // (ID, BankCode, Name, IBAN, BIC — všech 5 elementů REQUIRED v schema, posíláme
        // prázdné jako fallback), pak volitelně VariableSymbol/ConstantSymbol/SpecificSymbol.
        $bank = $this->resolveBank($invoice);
        if ($bank !== null && $payable > 0) {
            $pm = $dom->createElementNS(self::NS, 'PaymentMeans');
            $payment = $dom->createElementNS(self::NS, 'Payment');
            // PaidAmount nemá v ISDOC Curr variantu → lokální měna (CZK).
            $this->elAmount($dom, $payment, 'PaidAmount', $payable);
            $this->el($dom, $payment, 'PaymentMeansCode', $currencyCode === 'CZK' ? '42' : '31');

            $details = $dom->createElementNS(self::NS, 'Details');
            $this->el($dom, $details, 'PaymentDueDate', (string) $invoice['due_date']);
            // BankAccount group — INLINE elementy (žádný <BankAccount> wrapper!).
            // Všech 5 elementů (ID, BankCode, Name, IBAN, BIC) je v schema povinných.
            // Pro CZK účty (account_number + bank_code) dopočítáme IBAN přes
            // CzechIbanAdapter a BIC dotáhneme z hardcoded mapy nejčastějších CZ bank.
            // Když uživatel má IBAN/BIC explicitně vyplněný v `currencies`, má přednost.
            $accountNumber = (string) ($bank['account_number'] ?? '');
            $bankCode = (string) ($bank['bank_code'] ?? '');
            $iban = trim((string) ($bank['iban'] ?? ''));
            $bic  = trim((string) ($bank['bic'] ?? ''));
            if ($iban === '' && $accountNumber !== '' && $bankCode !== '') {
                $iban = $this->computeCzechIban($accountNumber, $bankCode);
            }
            if ($bic === '' && $bankCode !== '') {
                $bic = self::CZ_BANK_BIC[$bankCode] ?? '';
            }
            $this->el($dom, $details, 'ID', $accountNumber);
            $this->el($dom, $details, 'BankCode', $bankCode);
            $this->el($dom, $details, 'Name', (string) ($bank['bank_name'] ?? ''));
            $this->el($dom, $details, 'IBAN', $iban);
            $this->el($dom, $details, 'BIC', $bic);
            if (!empty($invoice['varsymbol'])) {
                $this->el($dom, $details, 'VariableSymbol', (string) $invoice['varsymbol']);
            }
            $this->el($dom, $details, 'ConstantSymbol', '0308');

            $payment->appendChild($details);
            $pm->appendChild($payment);
            $root->appendChild($pm);
        }

        return (string) $dom->saveXML();
    }

    private function buildParty(\DOMDocument $dom, array $party): \DOMElement
    {
        $partyEl = $dom->createElementNS(self::NS, 'Party');

        $idEl = $dom->createElementNS(self::NS, 'PartyIdentification');
        // PartyIdentification i jeho <ID> (IČ) jsou v XSD povinné, ale IDType je
        // neomezený xs:string → prázdný <ID></ID> je validní. Když subjekt IČO nemá
        // (typicky B2C odběratel — fyzická osoba), posíláme prázdno, NE fiktivní "0".
        $this->el($dom, $idEl, 'ID', trim((string) ($party['ic'] ?? '')));
        $partyEl->appendChild($idEl);

        $nameEl = $dom->createElementNS(self::NS, 'PartyName');
        $this->el($dom, $nameEl, 'Name', (string) ($party['company_name'] ?? ''));
        $partyEl->appendChild($nameEl);

        $addr = $dom->createElementNS(self::NS, 'PostalAddress');
        // Schema má StreetName + BuildingNumber jako samostatné povinné elementy.
        // V naší DB je street single string ("Kardinála Berana 1104/36") — extrahujeme
        // trailing číselný/lomítkový token jako BuildingNumber, zbytek jako StreetName.
        [$streetName, $buildingNumber] = $this->splitStreet((string) ($party['street'] ?? ''));
        $this->el($dom, $addr, 'StreetName', $streetName);
        $this->el($dom, $addr, 'BuildingNumber', $buildingNumber);
        $this->el($dom, $addr, 'CityName', (string) ($party['city'] ?? ''));
        $this->el($dom, $addr, 'PostalZone', (string) ($party['zip'] ?? ''));
        $country = $dom->createElementNS(self::NS, 'Country');
        $this->el($dom, $country, 'IdentificationCode', (string) ($party['country_iso2'] ?? 'CZ'));
        $this->el($dom, $country, 'Name', (string) ($party['country_name_cs'] ?? 'Česká republika'));
        $addr->appendChild($country);
        $partyEl->appendChild($addr);

        if (!empty($party['dic'])) {
            $tax = $dom->createElementNS(self::NS, 'PartyTaxScheme');
            $this->el($dom, $tax, 'CompanyID', (string) $party['dic']);
            $this->el($dom, $tax, 'TaxScheme', 'VAT');
            $partyEl->appendChild($tax);
        }

        // Zápis v obchodním rejstříku — supplier-level pole `commercial_register`
        // (např. "Krajský soud v Plzni, vl. č. C 38864"). Schema RegisterIdentification
        // má dvě choice varianty: strukturovanou (RegisterKeptAt + RegisterFileRef
        // + RegisterDate) nebo `<Preformatted>` — používáme druhou, protože uživatel
        // zadává údaj jako jeden freeform řetězec.
        if (!empty($party['commercial_register'])) {
            $reg = $dom->createElementNS(self::NS, 'RegisterIdentification');
            $this->el($dom, $reg, 'Preformatted', (string) $party['commercial_register']);
            $partyEl->appendChild($reg);
        }

        if (!empty($party['email']) || !empty($party['phone']) || !empty($party['main_email'])) {
            $contact = $dom->createElementNS(self::NS, 'Contact');
            if (!empty($party['phone'])) {
                $this->el($dom, $contact, 'Telephone', (string) $party['phone']);
            }
            $email = $party['email'] ?? $party['main_email'] ?? '';
            if ($email !== '') {
                $this->el($dom, $contact, 'ElectronicMail', (string) $email);
            }
            $partyEl->appendChild($contact);
        }

        return $partyEl;
    }

    private function resolveSupplier(array $invoice): array
    {
        // Live data ze supplier tabulky (defenzivní base — pro legacy faktury s prázdným
        // snapshotem a pro chybějící klíče v starších snapshotech). Snapshot vyhrává nad
        // live (zachovává historický stav vystavené faktury).
        $live = $this->loadLiveSupplier((int) ($invoice['supplier_id'] ?? 0));
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot']) ? json_decode($invoice['supplier_snapshot'], true) : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                return array_merge($live, $snap);
            }
        }
        return $live;
    }

    private function resolveClient(array $invoice): array
    {
        $live = $this->loadLiveClient((int) ($invoice['client_id'] ?? 0));
        if (!empty($invoice['client_snapshot'])) {
            $snap = is_string($invoice['client_snapshot']) ? json_decode($invoice['client_snapshot'], true) : $invoice['client_snapshot'];
            if (is_array($snap)) {
                return array_merge($live, $snap);
            }
        }
        // Final fallback: invoice repo joinuje basic client fields (legacy data bez clients
        // záznamu — např. smazaný klient).
        if (empty($live)) {
            return [
                'company_name' => $invoice['client_company_name'] ?? '',
                'ic' => $invoice['client_ic'] ?? '',
                'dic' => $invoice['client_dic'] ?? '',
                'main_email' => $invoice['client_main_email'] ?? '',
                'country_iso2' => 'CZ',
            ];
        }
        return $live;
    }

    private function loadLiveSupplier(int $supplierId): array
    {
        if ($supplierId <= 0) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM supplier s JOIN countries co ON co.id = s.country_id WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function loadLiveClient(int $clientId): array
    {
        if ($clientId <= 0) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM clients c JOIN countries co ON co.id = c.country_id WHERE c.id = ?'
        );
        $stmt->execute([$clientId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function resolveBank(array $invoice): ?array
    {
        if (!empty($invoice['bank_snapshot'])) {
            $snap = is_string($invoice['bank_snapshot']) ? json_decode($invoice['bank_snapshot'], true) : $invoice['bank_snapshot'];
            if (is_array($snap)) return $snap;
        }
        if (!empty($invoice['bank_account_number']) || !empty($invoice['bank_iban'])) {
            return [
                'account_number' => $invoice['bank_account_number'] ?? null,
                'bank_code'      => $invoice['bank_code'] ?? null,
                'bank_name'      => $invoice['bank_name'] ?? null,
                'iban'           => $invoice['bank_iban'] ?? null,
                'bic'            => $invoice['bank_bic'] ?? null,
            ];
        }
        return null;
    }

    private function el(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): \DOMElement
    {
        $el = $dom->createElementNS(self::NS, $name);
        $el->appendChild($dom->createTextNode($value));
        $parent->appendChild($el);
        return $el;
    }

    /**
     * Částka bez cizoměnového sourozence (UnitPrice, LineExtensionTaxAmount,
     * PaidAmount …). AmountType je dle ISDOC vždy v lokální měně, takže $value
     * (v měně faktury) převedeme kurzem: CZK = $value × kurz. Pro CZK fakturu je
     * kurz 1, výstup tedy beze změny.
     */
    private function elAmount(\DOMDocument $dom, \DOMElement $parent, string $name, float $value): void
    {
        $this->el($dom, $parent, $name, $this->fmt($value * $this->exportRate));
    }

    /**
     * Částka s cizoměnovým sourozencem <name>Curr. Base <name> je v lokální měně
     * (CZK = $value × kurz), <name>Curr nese $value v měně faktury a generuje se
     * jen u cizoměnových dokladů (jinak by XSD pravidlo zakazovalo *Curr elementy).
     *
     * $currFirst řídí pořadí v sekvenci: ISDOC řadí <…Curr> PŘED base v InvoiceLine
     * a TaxTotal/TaxSubTotal, ale ZA base v LegalMonetaryTotal.
     */
    private function elAmountCurr(\DOMDocument $dom, \DOMElement $parent, string $name, float $value, bool $currFirst): void
    {
        if ($this->exportForeign && $currFirst) {
            $this->el($dom, $parent, $name . 'Curr', $this->fmt($value));
        }
        $this->el($dom, $parent, $name, $this->fmt($value * $this->exportRate));
        if ($this->exportForeign && !$currFirst) {
            $this->el($dom, $parent, $name . 'Curr', $this->fmt($value));
        }
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * Spočte český IBAN z account_number a bank_code (mod-97 check digits).
     * Account může mít prefix-suffix formát (np. "123-4567890123"), CzechIbanAdapter
     * to zvládá. Při chybě (invalid input, neplatná banka) vrací prázdný string.
     */
    private function computeCzechIban(string $accountNumber, string $bankCode): string
    {
        try {
            return (new CzechIbanAdapter($accountNumber, $bankCode))->asString();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Rozdělí "Kardinála Berana 1104/36" na ["Kardinála Berana", "1104/36"].
     * Pokud street neobsahuje trailing číslo, vrací [original, ''].
     */
    private function splitStreet(string $street): array
    {
        $s = trim($street);
        if ($s === '') return ['', ''];
        if (preg_match('/^(.*?)\s+(\d[\d\/\w-]*)$/u', $s, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return [$s, ''];
    }

    private function makeUuid(array $invoice): string
    {
        // Deterministický UUID v5-style based na invoice ID + supplier (žádný náhodný)
        $ns = sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            (int) ($invoice['supplier_id'] ?? 0),
            0x4d59, // "MY"
            0x4956, // "IV"
            0x0000,
            (int) $invoice['id'],
        );
        return $ns;
    }
}

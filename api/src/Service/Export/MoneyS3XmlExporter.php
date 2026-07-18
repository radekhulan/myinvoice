<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use DOMDocument;
use DOMElement;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;

/**
 * Money S3 (Seyfor) XML export for issued invoices — the "Faktury vydané"
 * prepared-list format (SeznamFaktVyd/FaktVyd).
 *
 * There is no official public XSD for this format; the field layout here was
 * reverse-engineered from a real Money S3 export sample and cross-checked
 * against the Money S3 user manual (ČÁST XIII, "XML přenosy" — money.cz/navod/
 * s3xmlde/). The manual documents the transfer *mechanism* (what entities are
 * importable, mirroring rules for received-vs-issued documents, which fields
 * are updatable on re-import) but not a literal tag-by-tag schema, so the
 * sample XML is the primary source of truth for element names below.
 *
 * Confirmed quirk from the sample: the VAT-bucket field names in <SouhrnDPH>
 * (Zaklad0/Zaklad5/Zaklad22, DPH5/DPH22) and on <Polozka><SouhrnDPH> are
 * legacy — they date back to when Czech VAT had 5%/22% rates and Money S3 has
 * never renamed them. They mean "0% bucket / reduced-rate bucket / standard-
 * rate bucket" regardless of the literal rates in force; the two non-zero
 * rates are named by the document header via <SazbaDPH1>/<SazbaDPH2>. Because
 * those rates are per-document, the exporter derives them from the invoice's
 * own line items (see {@see self::vatBuckets()}) so historical periods export
 * with their real rates — 15%/21% (2013–2023), 14%/20% (2012), 5%/22%
 * (1995–2003) — not just the current 12%/21%. Money S3 has only two non-zero
 * buckets per document, so a third distinct non-zero rate (e.g. the 2015–2023
 * 10/15/21 mix on one doc) is a hard error rather than a silently dropped line.
 *
 * Manual-confirmed scope limits (money.cz/navod/s3xmlde/, "Konkrétní logika
 * importu a exportu entit" → "Faktury přijaté a vydané"): storno (cancelled)
 * invoices are explicitly NOT supported for export/import, so this exporter
 * only ever receives invoices already filtered to issued/sent/paid statuses
 * by the caller (ExportAction::findInvoiceIds).
 */
final class MoneyS3XmlExporter
{
    private readonly InvoiceExportDataResolver $dataResolver;

    public function __construct(
        private readonly InvoiceRepository $repo,
        Connection $db,
        ?InvoiceExportDataResolver $dataResolver = null,
    ) {
        $this->dataResolver = $dataResolver ?? new InvoiceExportDataResolver($db);
    }

    /**
     * @param int[] $invoiceIds
     * @return array{filename:string, content:string, mime:string}
     */
    public function export(array $invoiceIds, string $periodLabel = ''): array
    {
        $invoices = [];
        foreach ($invoiceIds as $id) {
            $invoice = $this->repo->find((int) $id);
            if ($invoice !== null && !empty($invoice['items']) && is_array($invoice['items'])) {
                $invoices[] = $invoice;
            }
        }

        if ($invoices === []) {
            throw new \RuntimeException('Žádné faktury s položkami k exportu do Money S3 XML.');
        }

        $base = 'money-s3-' . ($periodLabel !== '' ? $periodLabel : date('Y-m-d'));
        return [
            'filename' => "$base.xml",
            'content' => $this->buildXml($invoices),
            'mime' => 'application/xml',
        ];
    }

    /**
     * @param list<array<string,mixed>> $invoices
     */
    public function buildXml(array $invoices): string
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->preserveWhiteSpace = false;
        $xml->formatOutput = true;

        $supplier = $this->dataResolver->supplier($invoices[0]);

        $root = $xml->appendChild($xml->createElement('MoneyData'));
        $root->setAttribute('ICAgendy', (string) ($supplier['ic'] ?? ''));
        $root->setAttribute('description', 'faktury vydané');
        $root->setAttribute('ExpZkratka', '_FV');
        $root->setAttribute('ExpDate', date('Y-m-d'));
        $root->setAttribute('ExpTime', date('H:i:s'));
        $root->setAttribute('JazykVerze', 'CZ');
        $root->setAttribute('VyberZaznamu', '0');
        $root->setAttribute('GUID', $this->guid());

        $list = $root->appendChild($xml->createElement('SeznamFaktVyd'));
        foreach ($invoices as $invoice) {
            $list->appendChild($this->invoiceNode($xml, $invoice));
        }
        // Money S3's prepared "_FP+FV" list always emits the advance-invoice
        // (DPP — daňový doklad k přijaté platbě) sibling list, empty when the
        // export contains no proforma advance documents. Confirmed present
        // (self-closed) even when empty in the real sample.
        $root->appendChild($xml->createElement('SeznamFaktVyd_DPP'));

        return $xml->saveXML() ?: '';
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function invoiceNode(DOMDocument $xml, array $invoice): DOMElement
    {
        $node = $xml->createElement('FaktVyd');

        $doklad = (string) ($invoice['varsymbol'] ?? $invoice['id'] ?? '');
        $this->el($xml, $node, 'Doklad', $doklad);
        $this->el($xml, $node, 'GUID', $this->guid());
        [$rada, $cisRada] = $this->series($invoice);
        $this->el($xml, $node, 'Rada', $rada);
        $this->el($xml, $node, 'CisRada', $cisRada);
        $this->el($xml, $node, 'Popis', $this->description($invoice));
        $this->el($xml, $node, 'Vystaveno', $this->date((string) ($invoice['issue_date'] ?? '')));
        $this->el($xml, $node, 'DatUcPr', $this->date((string) ($invoice['issue_date'] ?? '')));
        $this->el($xml, $node, 'PlnenoDPH', $this->date((string) ($invoice['tax_date'] ?? $invoice['issue_date'] ?? '')));
        $this->el($xml, $node, 'Splatno', $this->date((string) ($invoice['due_date'] ?? $invoice['issue_date'] ?? '')));
        $this->el($xml, $node, 'DatSkPoh', $this->date((string) ($invoice['issue_date'] ?? '')));
        $this->el($xml, $node, 'KonstSym', (string) ($invoice['constant_symbol'] ?? ''));
        $this->el($xml, $node, 'ZjednD', '0');
        $this->el($xml, $node, 'VarSymbol', (string) ($invoice['varsymbol'] ?? ''));
        $this->el($xml, $node, 'Ucet', 'BAN');
        $this->el($xml, $node, 'Druh', 'N');
        $this->el($xml, $node, 'Dobropis', ($invoice['invoice_type'] ?? '') === 'credit_note' ? '1' : '0');
        $this->el($xml, $node, 'Uhrada', $this->paymentMethodLabel((string) ($invoice['payment_method'] ?? '')));
        $this->el($xml, $node, 'ZpVypDPH', '1');

        $items = array_values(array_filter((array) ($invoice['items'] ?? []), 'is_array'));
        $buckets = $this->vatBuckets($invoice, $items);

        $this->el($xml, $node, 'SazbaDPH1', $this->fmt($buckets['reducedRate']));
        $this->el($xml, $node, 'SazbaDPH2', $this->fmt($buckets['standardRate']));

        $advance = (float) ($invoice['advance_paid_amount'] ?? 0);
        $total = (float) ($invoice['total_with_vat'] ?? 0);
        $amountToPay = array_key_exists('amount_to_pay', $invoice) && $invoice['amount_to_pay'] !== null
            ? (float) $invoice['amount_to_pay']
            : $total - $advance;

        $this->el($xml, $node, 'Proplatit', $this->fmt($amountToPay));
        $this->el($xml, $node, 'Vyuctovano', '0');

        $souhrn = $node->appendChild($xml->createElement('SouhrnDPH'));
        $this->el($xml, $souhrn, 'Zaklad0', $this->fmt($buckets['zeroBase']));
        $this->el($xml, $souhrn, 'Zaklad5', $this->fmt($buckets['reducedBase']));
        $this->el($xml, $souhrn, 'Zaklad22', $this->fmt($buckets['standardBase']));
        $this->el($xml, $souhrn, 'DPH5', $this->fmt($buckets['reducedVat']));
        $this->el($xml, $souhrn, 'DPH22', $this->fmt($buckets['standardVat']));

        $this->el($xml, $node, 'Celkem', $this->fmt($total));
        $this->el($xml, $node, 'Typ', 'SLUŽBA');
        $this->el($xml, $node, 'Vystavil', $this->dataResolver->issuePerson($invoice));
        $this->el($xml, $node, 'PriUhrZbyv', '0');
        $this->el($xml, $node, 'ValutyProp', $this->currencyIso($invoice) === 'CZK' ? '0' : '1');
        $this->el($xml, $node, 'SumZaloha', $this->fmt($advance));
        $this->el($xml, $node, 'SumZalohaC', $this->fmt($advance));

        $client = $this->dataResolver->client($invoice);
        $node->appendChild($this->partyNode($xml, 'DodOdb', $client, true));
        $node->appendChild($this->partyNode($xml, 'KonecPrij', $client, false));

        $this->el($xml, $node, 'DopravTuz', '0');
        $this->el($xml, $node, 'DopravZahr', '0');
        $this->el($xml, $node, 'Sleva', '0');

        $polozky = $node->appendChild($xml->createElement('SeznamPolozek'));
        foreach ($items as $index => $item) {
            $polozky->appendChild($this->itemNode($xml, $item, $index + 1));
        }

        $node->appendChild($this->supplierNode($xml, $invoice));

        return $node;
    }

    /**
     * DodOdb ("full") carries the buyer's full billing identity; KonecPrij
     * ("final recipient") is the same party in the sample but with a reduced
     * field set — we don't model a separate delivery address, so both nodes
     * describe the same client.
     *
     * @param array<string,mixed> $data
     */
    private function partyNode(DOMDocument $xml, string $name, array $data, bool $full): DOMElement
    {
        $node = $xml->createElement($name);
        $address = $this->address($data);
        $companyName = (string) ($data['company_name'] ?? '');

        if ($full) {
            $this->el($xml, $node, 'ObchNazev', $companyName);
            $this->appendAddress($xml, $node, 'ObchAdresa', $address);
            $this->el($xml, $node, 'FaktNazev', $companyName);
            $this->appendAddress($xml, $node, 'FaktAdresa', $address);
        }

        $this->el($xml, $node, 'Nazev', $companyName);
        $this->appendAddress($xml, $node, 'Adresa', $address);
        $this->el($xml, $node, 'GUID', $this->guid());

        if (!empty($data['ic'])) {
            $this->el($xml, $node, 'ICO', (string) $data['ic']);
        }
        if (!empty($data['dic'])) {
            $this->el($xml, $node, 'DIC', (string) $data['dic']);
        }

        $this->el($xml, $node, 'PlatceDPH', !empty($data['dic']) ? '1' : '0');
        $this->el($xml, $node, 'FyzOsoba', '0');

        return $node;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function itemNode(DOMDocument $xml, array $item, int $order): DOMElement
    {
        $node = $xml->createElement('Polozka');

        $quantity = (float) ($item['quantity'] ?? 1);
        $base = (float) ($item['total_without_vat'] ?? 0);
        $vat = (float) ($item['total_vat'] ?? 0);

        $this->el($xml, $node, 'Popis', (string) ($item['description'] ?? ''));
        $this->el($xml, $node, 'PocetMJ', $this->fmt($quantity));
        $this->el($xml, $node, 'SazbaDPH', $this->fmt((float) ($item['vat_rate_snapshot'] ?? 0)));
        $this->el($xml, $node, 'Cena', $this->fmt($base));

        $souhrn = $node->appendChild($xml->createElement('SouhrnDPH'));
        $this->el($xml, $souhrn, 'Zaklad_MJ', $this->fmt($quantity != 0.0 ? $base / $quantity : $base));
        $this->el($xml, $souhrn, 'DPH_MJ', $this->fmt($quantity != 0.0 ? $vat / $quantity : $vat));
        $this->el($xml, $souhrn, 'Zaklad', $this->fmt($base));
        $this->el($xml, $souhrn, 'DPH', $this->fmt($vat));

        $this->el($xml, $node, 'CenaTyp', '0');
        $this->el($xml, $node, 'Sleva', '0');
        $this->el($xml, $node, 'Poradi', (string) $order);
        $this->el($xml, $node, 'Valuty', '0');

        $neskl = $node->appendChild($xml->createElement('NesklPolozka'));
        $this->el($xml, $neskl, 'Zaloha', '0');
        $this->el($xml, $neskl, 'TypZarDoby', 'N');
        $this->el($xml, $neskl, 'ZarDoba', '0');
        $this->el($xml, $neskl, 'Protizapis', '0');
        $this->el($xml, $neskl, 'Hmotnost', '0');

        $this->el($xml, $node, 'CenaPoSleve', '1');

        return $node;
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function supplierNode(DOMDocument $xml, array $invoice): DOMElement
    {
        $supplier = $this->dataResolver->supplier($invoice);
        $currencyIso = $this->currencyIso($invoice);
        $node = $xml->createElement('MojeFirma');
        $address = $this->address($supplier);
        $name = (string) ($supplier['company_name'] ?? '');

        $this->el($xml, $node, 'Nazev', $name);
        $this->appendAddress($xml, $node, 'Adresa', $address);
        $this->el($xml, $node, 'ObchNazev', $name);
        $this->appendAddress($xml, $node, 'ObchAdresa', $address);
        $this->el($xml, $node, 'FaktNazev', $name);
        $this->appendAddress($xml, $node, 'FaktAdresa', $address);

        $tel = $node->appendChild($xml->createElement('Tel'));
        $this->el($xml, $tel, 'Pred', '');
        $this->el($xml, $tel, 'Cislo', (string) ($supplier['phone'] ?? ''));
        $this->el($xml, $tel, 'Klap', '');

        $this->el($xml, $node, 'EMail', (string) ($supplier['main_email'] ?? $supplier['email'] ?? ''));
        $this->el($xml, $node, 'WWW', (string) ($supplier['web'] ?? ''));

        if (!empty($supplier['ic'])) {
            $this->el($xml, $node, 'ICO', (string) $supplier['ic']);
        }
        if (!empty($supplier['dic'])) {
            $this->el($xml, $node, 'DIC', (string) $supplier['dic']);
        }

        $bank = $this->dataResolver->bank($invoice) ?? [];
        if (!empty($bank['bank_name'])) {
            $this->el($xml, $node, 'Banka', (string) $bank['bank_name']);
        }
        if (!empty($bank['account_number'])) {
            $this->el($xml, $node, 'Ucet', (string) $bank['account_number']);
        }
        if (!empty($bank['bank_code'])) {
            $this->el($xml, $node, 'KodBanky', (string) $bank['bank_code']);
        }

        $this->el($xml, $node, 'FyzOsoba', '0');
        $this->el($xml, $node, 'MenaSymb', $currencyIso === 'CZK' ? 'Kč' : $currencyIso);
        $this->el($xml, $node, 'MenaKod', $currencyIso);

        return $node;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{street:string,city:string,zip:string,country:string,countryIso:string}
     */
    private function address(array $data): array
    {
        return [
            'street' => (string) ($data['street'] ?? ''),
            'city' => (string) ($data['city'] ?? ''),
            'zip' => (string) ($data['zip'] ?? ''),
            'country' => (string) ($data['country_name_cs'] ?? 'Česká republika'),
            'countryIso' => strtoupper((string) ($data['country_iso2'] ?? 'CZ')),
        ];
    }

    /**
     * @param array{street:string,city:string,zip:string,country:string,countryIso:string} $address
     */
    private function appendAddress(DOMDocument $xml, DOMElement $parent, string $name, array $address): void
    {
        $node = $parent->appendChild($xml->createElement($name));
        $this->el($xml, $node, 'Ulice', $address['street']);
        $this->el($xml, $node, 'Misto', $address['city']);
        $this->el($xml, $node, 'PSC', $address['zip']);
        $this->el($xml, $node, 'Stat', $address['country']);
        $this->el($xml, $node, 'KodStatu', $address['countryIso']);
    }

    /**
     * Current Czech reduced/standard VAT rates — used only as the fallback for
     * an *unused* Money S3 rate slot (an all-zero document, or one that fills
     * just the other slot). The rates that actually drive bucketing are derived
     * per-document from the line items, see {@see self::vatBuckets()}.
     */
    private const DEFAULT_REDUCED_VAT_RATE = 12.0;
    private const DEFAULT_STANDARD_VAT_RATE = 21.0;

    /**
     * A lone non-zero rate below this threshold goes to the reduced slot, at or
     * above it to the standard slot. The value sits in the clean gap between
     * every historical Czech reduced rate (≤ 15%) and every standard rate
     * (≥ 19%), so a single 10/12/14/15 lands reduced and a single 19/20/21/22
     * lands standard — matching the real sample, where a lone 21% line fills
     * the standard bucket while SazbaDPH1 keeps the default reduced rate.
     */
    private const REDUCED_STANDARD_SPLIT = 17.0;

    /**
     * <SazbaDPH1>/<SazbaDPH2> name the document's two non-zero VAT rates, whose
     * bases/VAT land in the legacy positional buckets Zaklad5/DPH5 (reduced) and
     * Zaklad22/DPH22 (standard); Zaklad0 holds the 0% base. Because those rates
     * are per-document, they are derived from the invoice's own line items — the
     * lower non-zero rate becomes the reduced slot, the higher the standard slot
     * — so historical periods export with their real rates (15%/21% for
     * 2013–2023, 14%/20% for 2012, 5%/22% for 1995–2003) rather than being
     * forced onto the current 12%/21%. A slot with no matching line falls back
     * to the current default rate.
     *
     * Money S3 has only two non-zero buckets per document, so three or more
     * distinct non-zero rates on one doc (e.g. the 2015–2023 10/15/21 mix)
     * cannot be represented and is a hard error rather than a silently
     * mis-bucketed line — matching how StereoXmlExporter refuses irreconcilable
     * per-row VAT metadata.
     *
     * @param array<string,mixed> $invoice
     * @param list<array<string,mixed>> $items
     * @return array{zeroBase:float,reducedBase:float,reducedVat:float,reducedRate:float,standardBase:float,standardVat:float,standardRate:float}
     */
    private function vatBuckets(array $invoice, array $items): array
    {
        $nonZeroRates = [];
        foreach ($items as $item) {
            $rate = round((float) ($item['vat_rate_snapshot'] ?? 0), 2);
            if ($rate > 0.0 && !in_array($rate, $nonZeroRates, true)) {
                $nonZeroRates[] = $rate;
            }
        }
        sort($nonZeroRates);

        if (count($nonZeroRates) > 2) {
            throw new \RuntimeException(sprintf(
                'Money S3 XML umí na dokladu jen dvě nenulové sazby DPH (plus 0). Doklad %s má sazby: %s.',
                (string) ($invoice['varsymbol'] ?? $invoice['id'] ?? '?'),
                implode(', ', array_map(fn (float $r): string => $this->fmt($r), $nonZeroRates)),
            ));
        }

        // Assign each present rate to a slot: with two rates the lower is
        // reduced and the higher standard; a lone rate is split by
        // REDUCED_STANDARD_SPLIT so it keeps its natural reduced/standard sense.
        $reducedRate = self::DEFAULT_REDUCED_VAT_RATE;
        $standardRate = self::DEFAULT_STANDARD_VAT_RATE;
        $slotOf = [];
        if (count($nonZeroRates) === 2) {
            [$reducedRate, $standardRate] = $nonZeroRates;
            $slotOf[$this->fmt($nonZeroRates[0])] = 'reduced';
            $slotOf[$this->fmt($nonZeroRates[1])] = 'standard';
        } elseif (count($nonZeroRates) === 1) {
            $rate = $nonZeroRates[0];
            if ($rate >= self::REDUCED_STANDARD_SPLIT) {
                $standardRate = $rate;
                $slotOf[$this->fmt($rate)] = 'standard';
            } else {
                $reducedRate = $rate;
                $slotOf[$this->fmt($rate)] = 'reduced';
            }
        }

        $buckets = [
            'zeroBase' => 0.0,
            'reducedBase' => 0.0, 'reducedVat' => 0.0, 'reducedRate' => $reducedRate,
            'standardBase' => 0.0, 'standardVat' => 0.0, 'standardRate' => $standardRate,
        ];

        foreach ($items as $item) {
            $rate = round((float) ($item['vat_rate_snapshot'] ?? 0), 2);
            $base = (float) ($item['total_without_vat'] ?? 0);
            $vat = (float) ($item['total_vat'] ?? 0);

            if ($rate <= 0.0) {
                $buckets['zeroBase'] += $base;
            } elseif (($slotOf[$this->fmt($rate)] ?? '') === 'reduced') {
                $buckets['reducedBase'] += $base;
                $buckets['reducedVat'] += $vat;
            } else {
                $buckets['standardBase'] += $base;
                $buckets['standardVat'] += $vat;
            }
        }

        return $buckets;
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array{0:string,1:string}
     */
    private function series(array $invoice): array
    {
        $rada = match ((string) ($invoice['invoice_type'] ?? 'invoice')) {
            'proforma' => 'ZFV',
            'credit_note' => 'DFV',
            default => 'FV',
        };

        return [$rada, '1'];
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function description(array $invoice): string
    {
        $items = (array) ($invoice['items'] ?? []);
        if (count($items) === 1 && is_array($items[0] ?? null)) {
            return (string) ($items[0]['description'] ?? 'prodej zboží a služeb');
        }

        return 'prodej zboží a služeb';
    }

    private function paymentMethodLabel(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'cash' => 'v hotovosti',
            'card' => 'kartou',
            default => 'převodem',
        };
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function currencyIso(array $invoice): string
    {
        $code = strtoupper((string) ($invoice['currency'] ?? 'CZK'));
        return preg_match('/^[A-Z]{3}$/', $code) ? $code : 'CZK';
    }

    private function guid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return '{' . strtoupper(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        )) . '}';
    }

    private function date(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    }

    private function fmt(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function el(DOMDocument $xml, DOMElement $parent, string $name, string $value): DOMElement
    {
        $element = $xml->createElement($name);
        $element->appendChild($xml->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }
}

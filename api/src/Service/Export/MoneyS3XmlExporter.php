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
 * never renamed them. Today they mean "0% bucket / reduced-rate bucket /
 * standard-rate bucket" regardless of the *current* literal rates (12%/21%),
 * which the document header declares separately via <SazbaDPH1>/<SazbaDPH2>.
 * This was proven by the sample: SazbaDPH1=12/SazbaDPH2=21 with a 21%-taxed
 * line landing its base/VAT in Zaklad22/DPH22, not a bucket literally named
 * "21". Money S3's own UI only supports two non-zero rates per document, so a
 * third distinct rate is a hard error here rather than silently dropped.
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

    /** Current Czech reduced/standard VAT rates (2024+). */
    private const REDUCED_VAT_RATE = 12.0;
    private const STANDARD_VAT_RATE = 21.0;

    /**
     * <SazbaDPH1>/<SazbaDPH2> are the Money S3 *agenda's* configured reduced/
     * standard VAT rates — fixed constants, not "whichever rates this document
     * happens to use". Confirmed by the real sample: it declares SazbaDPH1=12
     * even though its single line item is taxed at 21%, i.e. only the
     * standard-rate bucket. Line items bucket into the three legacy fields
     * (0% / reduced / standard — see class docblock) by matching their own
     * rate against these two constants; any other rate can't be represented
     * in Money S3's two-non-zero-bucket model and is a hard error, matching
     * how StereoXmlExporter refuses irreconcilable per-row VAT metadata.
     *
     * @param array<string,mixed> $invoice
     * @param list<array<string,mixed>> $items
     * @return array{zeroBase:float,reducedBase:float,reducedVat:float,reducedRate:float,standardBase:float,standardVat:float,standardRate:float}
     */
    private function vatBuckets(array $invoice, array $items): array
    {
        $buckets = [
            'zeroBase' => 0.0,
            'reducedBase' => 0.0, 'reducedVat' => 0.0, 'reducedRate' => self::REDUCED_VAT_RATE,
            'standardBase' => 0.0, 'standardVat' => 0.0, 'standardRate' => self::STANDARD_VAT_RATE,
        ];

        foreach ($items as $item) {
            $rate = round((float) ($item['vat_rate_snapshot'] ?? 0), 2);
            $base = (float) ($item['total_without_vat'] ?? 0);
            $vat = (float) ($item['total_vat'] ?? 0);

            if ($rate <= 0.0) {
                $buckets['zeroBase'] += $base;
            } elseif ($rate === self::REDUCED_VAT_RATE) {
                $buckets['reducedBase'] += $base;
                $buckets['reducedVat'] += $vat;
            } elseif ($rate === self::STANDARD_VAT_RATE) {
                $buckets['standardBase'] += $base;
                $buckets['standardVat'] += $vat;
            } else {
                throw new \RuntimeException(sprintf(
                    'Money S3 XML podporuje jen sazby DPH %s a %s (a 0) na dokladu %s. Nalezena sazba: %s.',
                    $this->fmt(self::REDUCED_VAT_RATE),
                    $this->fmt(self::STANDARD_VAT_RATE),
                    (string) ($invoice['varsymbol'] ?? $invoice['id'] ?? '?'),
                    $this->fmt($rate),
                ));
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

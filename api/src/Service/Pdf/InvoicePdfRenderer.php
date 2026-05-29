<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Branding\AccentColor;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renderuje fakturu jako PDF.
 *
 *   1. Načte fakturu (full + items + snapshots)
 *   2. Vyrendrované Twig šablonu invoice.twig
 *   3. Vygeneruje QR (pokud má varsymbol + amount + bank)
 *   4. mPDF z HTML
 *   5. Cache do storage/invoices/YYYY-MM/Faktura-YY-MM-NNN.pdf
 */
final class InvoicePdfRenderer
{
    use SignsPdf;

    private ?Environment $twig = null;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly Config $config,
        private readonly QrPaymentGenerator $qr,
        private readonly WorkReportRepository $workReports,
        private readonly SnapshotBuilder $snapshots,
        private readonly PdfArchiveService $archive,
        private readonly IsdocExporter $isdoc,
        private readonly PdfSigner $signer,
        private readonly \MyInvoice\Service\ActivityLogger $activity,
    ) {}

    /**
     * Vyrendrované PDF do souboru a vrátí cestu.
     *
     * @return string  absolutní cesta k vygenerovanému PDF
     */
    public function render(int $invoiceId, bool $forceRegenerate = false): string
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Faktura #{$invoiceId} nenalezena");
        }

        $cachedPath = $this->cachePath($invoice);

        // Cache je validní jen když je novější než šablona, CSS a kód renderu
        $tplMtime = max(
            @filemtime(Bootstrap::rootDir() . '/styles/invoice.css') ?: 0,
            @filemtime(Bootstrap::rootDir() . '/api/templates/invoice/invoice.twig') ?: 0,
            @filemtime(__FILE__) ?: 0,
        );
        $isFresh = static fn (string $p): bool =>
            is_file($p) && (@filemtime($p) ?: 0) >= $tplMtime;

        if (!$forceRegenerate && $invoice['pdf_path'] && $isFresh($invoice['pdf_path'])) {
            return $invoice['pdf_path'];
        }
        // cachePath fallback je orphan-recovery (pdf_path je null, ale soubor leží na
        // deterministické cestě). MUSÍ vyžadovat pdf_generated_at NOT NULL — jinak
        // by invalidate() s uzamčeným souborem (Windows: PDF otevřené v prohlížeči →
        // rename a unlink selžou) skončila s pdf_path=NULL ale původní soubor zůstal
        // na disku, a tahle větev by ho zde znovu pickla → stale PDF.
        if (!$forceRegenerate && !empty($invoice['pdf_generated_at']) && $isFresh($cachedPath)) {
            $this->updatePdfPath($invoiceId, $cachedPath);
            return $cachedPath;
        }

        // Force regenerate = také obnov supplier/client/bank snapshoty z live dat.
        // (Snapshoty jsou primární zdroj pro issued+ faktury — bez tohoto by se
        // změny v supplier/client tabulkách neprojevily ani po regenerate.)
        if ($forceRegenerate) {
            $invoice = $this->refreshSnapshots($invoice);
        }

        $rootDir = Bootstrap::rootDir();
        $tmpDir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        // ISDOC attachment: vyrobíme XML v paměti a předáme do SetAssociatedFiles
        // jako 'content' (žádný tmp soubor — mPDF API umí XML zpracovat in-memory).
        // Twigu předáme jen boolean flag, ať může vykreslit vizuální badge.
        // Gating: jen pokud supplier má embed_isdoc=1 a faktura je v CZK (ISDOC
        // je CZ standard, EUR/USD doklady by accounting SW jen zmátly).
        $isdocXml = null;
        if ($this->shouldEmbedIsdoc($invoice)) {
            try {
                $isdocXml = $this->isdoc->buildXml($invoice);
            } catch (\Throwable) {
                // ISDOC build selhal (chybný snapshot, neexistující data) —
                // PDF renderujeme bez přílohy, nezdržujeme uživatele.
                $isdocXml = null;
            }
        }

        $rendered = $this->renderHtmlAndCss($invoice, $isdocXml !== null);

        $mpdf = new Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'margin_top'        => 15,
            'margin_bottom'     => 18,
            'margin_left'       => 15,
            'margin_right'      => 15,
            'tempDir'           => $tmpDir,
            'default_font'      => 'dejavusans',
            'autoPageBreak'     => true,
        ]);
        // PDF metadata — bez Title/Author, aby Chrome viewer nezobrazoval text nad PDF.
        $mpdf->SetTitle('');
        $mpdf->SetAuthor('');
        $mpdf->SetCreator('MyInvoice.cz');

        // PDF/A-3 style associated file: zapsáno do /Names /EmbeddedFiles + /AF
        // catalog entry. Accounting SW (Pohoda, Money S3, …) skenuje tuhle cestu.
        if ($isdocXml !== null) {
            $mpdf->SetAssociatedFiles([[
                'content'        => $isdocXml,
                'name'           => 'invoice.isdoc',
                'mime'           => 'application/x-isdoc',
                'description'    => 'ISDOC ' . IsdocExporter::VERSION . ' invoice data',
                'AFRelationship' => 'Source',
            ]]);
        }

        // CSS separately (mPDF handluje líp než inline <style> tag)
        if ($rendered['css'] !== '') {
            $mpdf->WriteHTML($rendered['css'], \Mpdf\HTMLParserMode::HEADER_CSS);
        }
        $mpdf->WriteHTML($rendered['body'], \Mpdf\HTMLParserMode::HTML_BODY);

        if (!is_dir(dirname($cachedPath))) {
            @mkdir(dirname($cachedPath), 0755, true);
        }
        // Write to .new sibling first, pak atomický rename — obchází Windows file lock
        // (když je starý PDF otevřený v Chrome PDF viewer, přepis přímo by selhal).
        $tmpPath = $cachedPath . '.new';
        $mpdf->Output($tmpPath, \Mpdf\Output\Destination::FILE);

        // Podpis PDF (PAdES) — má-li dodavatel zapnuto; měkký fallback při chybě.
        $tmpPath = $this->signPdfIfEnabled(
            $tmpPath,
            $this->getSupplierData((int) ($invoice['supplier_id'] ?? 0)),
            $this->signer,
            $this->activity,
            'invoice',
            $invoiceId,
        );

        if (is_file($cachedPath)) {
            @unlink($cachedPath); // pokud locked, fail silently
        }
        if (!@rename($tmpPath, $cachedPath)) {
            // Rename selhal (target locked) — nech temp, vrať tmpPath přímo
            $cachedPath = $tmpPath;
        }

        $this->updatePdfPath($invoiceId, $cachedPath);

        return $cachedPath;
    }

    /**
     * ISDOC se přiloží jen pro CZK faktury dodavatele s embed_isdoc=1.
     * Drafty bez varsymbolu skipujeme — buildXml() by vyrobil placeholder
     * "DRAFT-{id}" jako ID, což účetní SW odmítne.
     */
    private function shouldEmbedIsdoc(array $invoice): bool
    {
        $currency = strtoupper((string) ($invoice['currency'] ?? ''));
        if ($currency !== 'CZK') return false;
        if (empty($invoice['varsymbol'])) return false;
        $supplier = $this->getSupplierData((int) ($invoice['supplier_id'] ?? 0));
        return (bool) ($supplier['embed_isdoc'] ?? true);
    }

    /**
     * @return array{body:string, css:string}
     */
    public function renderHtmlAndCss(array $invoice, bool $hasIsdocAttachment = false): array
    {
        $cssPath = Bootstrap::rootDir() . '/styles/invoice.css';
        $css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
        // Per-supplier branding barva — přebarví fialové akcenty na zvolený odstín.
        $css .= $this->brandAccentCss($this->resolveSupplier($invoice));
        // Renderuj template BEZ inline <style> bloku — CSS pošleme do mPDF zvlášť
        $body = $this->renderHtml($invoice, includeCss: false, hasIsdocAttachment: $hasIsdocAttachment);
        return ['body' => $body, 'css' => $css];
    }

    public function renderHtml(array $invoice, bool $includeCss = true, bool $hasIsdocAttachment = false): string
    {
        // Použij snapshots pokud jsou (issued+), jinak živá data
        $supplierData = $this->resolveSupplier($invoice);
        $clientData   = $this->resolveClient($invoice);
        $bankData     = $this->resolveBank($invoice);

        // QR generování:
        //   CZK SPAYD vyžaduje VS jako mandatory pole → bez varsymbolu skip
        //   SEPA EPC (EUR i další) VS nepoužívá, jen volitelný remittance text
        //     → drafty bez VS dostanou QR taky (preview pro klienta), remittance fallback
        //   Skip pro zaplacené faktury a pro non-bank-transfer payment_method
        $qrUri = null;
        $hasAmount = (float) $invoice['amount_to_pay'] > 0;
        $isCzk = ((string) $invoice['currency']) === 'CZK';
        $hasVs = !empty($invoice['varsymbol']);
        $isPaid = ($invoice['status'] ?? '') === 'paid';
        $paymentMethod = (string) ($invoice['payment_method'] ?? 'bank_transfer');
        $isBankTransfer = $paymentMethod === 'bank_transfer';
        if ($hasAmount && $bankData !== null && (!$isCzk || $hasVs) && !$isPaid && $isBankTransfer) {
            $qrUri = $this->qr->generate(
                (string) $invoice['currency'],
                (float) $invoice['amount_to_pay'],
                (string) ($invoice['varsymbol'] ?? ''),
                $bankData,
                (string) ($supplierData['display_name'] ?? $supplierData['company_name'] ?? 'MyInvoice'),
            );
        }

        $locale = $invoice['language'] ?? 'cs';
        $cssPath = Bootstrap::rootDir() . '/styles/invoice.css';
        $css = $includeCss && is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
        if ($includeCss && $css !== '') {
            $css .= $this->brandAccentCss($supplierData);
        }

        $twig = $this->twig();

        // Translation helper
        $twig->addFunction(new \Twig\TwigFunction('t', static function (string $cs, string $en) use ($locale) {
            return $locale === 'en' ? $en : $cs;
        }));

        $logoPath = $this->resolveLogoPath($supplierData, (int) ($invoice['supplier_id'] ?? 0));

        return $twig->render('invoice.twig', [
            'invoice'           => $invoice,
            'supplier'          => $supplierData,
            'client'            => $clientData,
            'bank'              => $bankData,
            'qr_data_uri'       => $qrUri,
            'is_paid'           => $isPaid,
            'payment_method'    => $paymentMethod,
            'locale'            => $locale,
            'doc_type_label'    => $this->docTypeLabel($invoice, $locale, $supplierData),
            'doc_title'         => $this->docTitle($invoice),
            'parent_varsymbol'  => $this->parentVarsymbol($invoice),
            'work_report'       => $this->workReports->findByInvoice((int) $invoice['id']),
            'date_format'       => $locale === 'en' ? 'M j, Y' : 'j. n. Y',
            'decimal_sep'       => $locale === 'en' ? '.' : ',',
            'thousand_sep'      => $locale === 'en' ? ',' : ' ',
            'css'               => $css,
            'logo_path'         => $logoPath,
            // Opt-in: vedle loga vykreslit i název firmy (migrace 0058). Jen když logo
            // reálně je — bez loga se název ukazuje vždy (textový brand-name fallback).
            'logo_show_name'    => $logoPath !== null && !empty($supplierData['pdf_logo_show_name']),
            'isdoc_attachment'  => $hasIsdocAttachment, // bool — badge gate
        ]);
    }

    /**
     * Per-supplier branding accent — přebarví fialové akcenty PDF (#3B2D83 + sekundární
     * labely #6753AE) na barvu zvolenou dodavatelem (`email_accent_color`). Vrací override
     * CSS blok připojovaný ZA base invoice.css (vyšší priorita díky pořadí + stejná
     * specificita).
     *
     * Gating stejný jako logo: jen když má dodavatel zapnutý branding toggle
     * (`email_branding_enabled`) a nedefaultní hex barvu — pro #3B2D83 negenerujeme nic,
     * ten je už v base CSS.
     *
     * Sémantické barvy (dobropis červená .head.credit-note, storno šedá .cancellation,
     * RC amber, UHRAZENO zelená) NEpřebarvujeme — credit-note/cancellation selektory mají
     * vyšší specificitu (2 třídy), takže tenhle 1-třídový override je nepřebije.
     *
     * Kromě popředí (texty/hlavičky) přebarvujeme i světlé plochy a tenké linky, které
     * jsou v base napevno odvozené od defaultní fialové — světlé varianty akcentu počítá
     * AccentColor::tint() (mix s bílou). Šedá paleta CZK rekapitulace (bg #F2F2F2/…) je
     * záměrně neutrální, tu necháváme být — barvíme jen její fialové linky/text.
     */
    private function brandAccentCss(array $supplier): string
    {
        // Sdíleno s výkazem víceprací — viz PdfBranding::accentCss.
        return PdfBranding::accentCss($supplier);
    }

    /**
     * Invaliduje cached PDF všech draftů dodavatele — volá se po změně brandingu
     * (barva/logo/toggle), protože ty se v PDF renderují živě (nejsou ve snapshotu),
     * ale mtime-based cache je sama od sebe neobnoví. Drafty mažeme bez archive entry
     * (archive:false) — jsou to jen preview, ne odeslané doklady. Vystavené faktury
     * regenerují při příští změně šablony/CSS/kódu nebo přes ?regenerate=1.
     *
     * Vrací počet invalidovaných draftů.
     */
    public function invalidateDraftsBySupplier(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices WHERE supplier_id = ? AND status = "draft"'
        );
        $stmt->execute([$supplierId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($ids as $id) $this->invalidate($id, 'invalidate_branding', archive: false);
        return count($ids);
    }

    private function twig(): Environment
    {
        // Vždy nový Environment — addFunction() lze volat jen před init,
        // a po jednom render() se environment zamkne. Cache=false stejně.
        $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/invoice');
        return new Environment($loader, [
            'autoescape' => 'html',
            'cache' => false,
            'strict_variables' => false,
        ]);
    }

    private function resolveSupplier(array $invoice): array
    {
        $live = $this->getSupplierData((int) ($invoice['supplier_id'] ?? 0));
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot']) ? json_decode($invoice['supplier_snapshot'], true) : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                // Defensive merge: snapshot je primární (zachovává historické údaje), ale chybějící
                // klíče (př. legacy snapshoty bez street/is_vat_payer) doplníme z live supplier dat.
                // Zabrání tomu, aby se vystavená faktura ze stubu vykreslila jen s názvem firmy.
                return array_merge($live, $snap);
            }
        }
        return $live;
    }

    private function resolveClient(array $invoice): array
    {
        // Defensive merge: snapshot je primární (historický stav), live data
        // doplní chybějící klíče. Bez merge by legacy/cizí snapshoty (import
        // z ISDOC/Pohody, ruční insert) vykreslily fakturu s prázdnou adresou.
        $live = [];
        if (!empty($invoice['client_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT c.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
                   FROM clients c JOIN countries co ON co.id = c.country_id
                  WHERE c.id = ?'
            );
            $stmt->execute([$invoice['client_id']]);
            $live = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        }
        if (!empty($invoice['client_snapshot'])) {
            $snap = is_string($invoice['client_snapshot']) ? json_decode($invoice['client_snapshot'], true) : $invoice['client_snapshot'];
            if (is_array($snap)) {
                return array_merge($live, $snap);
            }
        }
        return $live;
    }

    private function resolveBank(array $invoice): ?array
    {
        // Live data z currencies (account/bank/IBAN/BIC podle currency_id).
        // Stejný defensive-merge pattern jako u supplier/client — snapshot vyhrává,
        // live doplní chybějící IBAN/BIC/bank_name v legacy snapshotech.
        $live = [];
        if (!empty($invoice['currency_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT account_number, bank_code, bank_name, iban, bic FROM currencies WHERE id = ?'
            );
            $stmt->execute([(int) $invoice['currency_id']]);
            $live = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        }
        $row = $live;
        if (!empty($invoice['bank_snapshot'])) {
            $snap = is_string($invoice['bank_snapshot']) ? json_decode($invoice['bank_snapshot'], true) : $invoice['bank_snapshot'];
            if (is_array($snap)) {
                $row = array_merge($live, $snap);
            }
        }
        if (empty($row)) return null;
        $hasCzk = !empty($row['account_number']) && !empty($row['bank_code']);
        $hasIban = !empty($row['iban']);
        return ($hasCzk || $hasIban) ? $row : null;
    }

    private function getSupplierData(int $supplierId): array
    {
        if ($supplierId <= 0) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM supplier s JOIN countries co ON co.id = s.country_id WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function docTypeLabel(array $invoice, string $locale, array $supplier = []): string
    {
        $isVatPayer = (bool) ($supplier['is_vat_payer'] ?? true);
        $labels = [
            'cs' => [
                'invoice'      => $isVatPayer ? 'Faktura — daňový doklad' : 'Faktura',
                'proforma'     => 'Zálohová faktura',
                'credit_note'  => $isVatPayer ? 'Opravný daňový doklad' : 'Opravná faktura',
                'cancellation' => 'Storno (interní)',
            ],
            'en' => [
                'invoice'      => $isVatPayer ? 'Invoice — Tax document' : 'Invoice',
                'proforma'     => 'Proforma invoice',
                'credit_note'  => $isVatPayer ? 'Credit note — Tax adjustment' : 'Credit note',
                'cancellation' => 'Cancellation (internal)',
            ],
        ];
        return $labels[$locale][$invoice['invoice_type']] ?? $labels['cs'][$invoice['invoice_type']] ?? '';
    }

    private function docTitle(array $invoice): string
    {
        $vs = $invoice['varsymbol'] ?? ('DRAFT-' . $invoice['id']);
        $t = match ($invoice['invoice_type']) {
            'proforma'     => 'Zálohová faktura',
            'credit_note'  => 'Dobropis',
            'cancellation' => 'Storno',
            default        => 'Faktura',
        };
        return "$t $vs";
    }

    private function parentVarsymbol(array $invoice): ?string
    {
        if (!$invoice['parent_invoice_id']) return null;
        $stmt = $this->db->pdo()->prepare('SELECT varsymbol FROM invoices WHERE id = ?');
        $stmt->execute([$invoice['parent_invoice_id']]);
        return $stmt->fetchColumn() ?: null;
    }

    private function resolveLogoPath(array $supplier, int $supplierIdFallback = 0): ?string
    {
        // Logo se v PDF zobrazí jen když má dodavatel zapnutý branding
        // (`email_branding_enabled` = 1). Pokud je toggle vypnutý, vykreslí se
        // textový brand-name fallback. Stejný toggle gatuje branding emailů,
        // takže UX je konzistentní napříč PDF i emaily.
        if (empty($supplier['email_branding_enabled'])) return null;

        $logoPath = $supplier['logo_path'] ?? null;
        if (!$logoPath) return null;

        // SafeLogoPath: defense-in-depth proti podstrčenému logo_path (security
        // report @andrejtomci #2). Mass-assign už je zavřený, ale tohle je 2.
        // vrstva pro případ legacy snapshotu s bad-value nebo jiné cesty vstupu.
        // Pokud snapshot postrádá `id`, použijeme fallback z invoice.supplier_id.
        $supplierId = (int) ($supplier['id'] ?? $supplierIdFallback);
        $abs = \MyInvoice\Service\Mail\SafeLogoPath::resolve((string) $logoPath, $supplierId);
        if ($abs === null) return null;

        // V PDF preferujeme SVG sidecar (vektor = crisp v libovolném zoomu/tisku);
        // PNG je fallback pokud SVG chybí nebo obsahuje mPDF-nekompatibilní prvky.
        //
        // mPDF SVG renderer má známé limity: `<clipPath>`, `<use xlink:href>`,
        // `<mask>`, `<linearGradient>`, `<radialGradient>`, `<pattern>` a `<filter>`
        // se často vykreslí černým fillem nebo posunutě. Adobe Illustrator export
        // tohle používá běžně. U takových SVG fallneme na PNG (rasterizovaný
        // SupplierLogoConverterem s alfa kanálem = transparentní pozadí).
        // Email vždy používá PNG (Outlook/Gmail SVG nepodporují) — to řeší
        // Mailer + InvoiceEmailVarsBuilder, ne tahle metoda.
        $svgSibling = preg_replace('/\.png$/i', '.svg', (string) $logoPath);
        if (is_string($svgSibling) && $svgSibling !== $logoPath) {
            $svgAbs = \MyInvoice\Service\Mail\SafeLogoPath::resolve($svgSibling, $supplierId);
            if ($svgAbs !== null && $this->svgIsMpdfCompatible($svgAbs)) {
                return $svgAbs;
            }
        }
        return $abs;
    }

    /**
     * Detekce SVG features, které mPDF neumí korektně vykreslit.
     * Pokud SVG obsahuje něco z {clipPath, use, mask, gradient, pattern, filter},
     * vrátíme false → caller fallne na PNG variantu.
     */
    private function svgIsMpdfCompatible(string $svgPath): bool
    {
        $svg = (string) @file_get_contents($svgPath);
        if ($svg === '') return false;
        // Známé problematické features v mPDF SVG rendereru
        $bad = '/<(?:clipPath|use|mask|linearGradient|radialGradient|pattern|filter)\b/i';
        return !preg_match($bad, $svg);
    }


    /**
     * Resnapshot supplier/client/bank z live dat a uloží do invoices. Volá se při
     * forceRegenerate, aby `regenerate=1` propsalo i změny v supplier/client/banku.
     * Drafty (bez existujících snapshotů) přeskoč — ty stejně renderují z live.
     *
     * Defensive guard: pro non-draft fakturu (issued/sent/reminded/paid) NIKDY
     * nepřepisuj snapshot, i když si někdo vynutí ?regenerate=1. Snapshoty u
     * vystavených faktur jsou immutable audit trail toho, co bylo na dokladu.
     *
     * @return array  invoice array s aktualizovanými snapshoty (in-memory)
     */
    private function refreshSnapshots(array $invoice): array
    {
        $status = (string) ($invoice['status'] ?? 'draft');
        if ($status !== 'draft') {
            return $invoice;
        }
        $hasAny = !empty($invoice['supplier_snapshot'])
            || !empty($invoice['client_snapshot'])
            || !empty($invoice['bank_snapshot']);
        if (!$hasAny) return $invoice;

        try {
            $built = $this->snapshots->build(
                (int) $invoice['client_id'],
                (int) $invoice['currency_id'],
                (int) ($invoice['supplier_id'] ?? 0),
            );
        } catch (\Throwable) {
            // Pokud klient/dodavatel neexistuje (smazaný), zachovej původní snapshot.
            return $invoice;
        }

        $supplierJson = json_encode($built['supplier'], JSON_UNESCAPED_UNICODE);
        $clientJson   = json_encode($built['client'], JSON_UNESCAPED_UNICODE);
        $bankJson     = $built['bank'] !== null ? json_encode($built['bank'], JSON_UNESCAPED_UNICODE) : null;

        $this->db->pdo()->prepare(
            'UPDATE invoices SET supplier_snapshot = ?, client_snapshot = ?, bank_snapshot = ? WHERE id = ?'
        )->execute([$supplierJson, $clientJson, $bankJson, (int) $invoice['id']]);

        $invoice['supplier_snapshot'] = $supplierJson;
        $invoice['client_snapshot']   = $clientJson;
        $invoice['bank_snapshot']     = $bankJson;
        return $invoice;
    }

    /**
     * Archivuje cached PDF (pokud existuje) a vynuluje invoices.pdf_path.
     * Volá se po změnách, které ovlivní obsah PDF nad rámec items
     * (např. work_report, edit faktury, vystavení).
     *
     * Reason je uložen do invoice_pdfs.reason a pomáhá v UI rozlišit,
     * proč se historická verze archivovala (edit / issue / workreport / ...).
     */
    public function invalidate(int $invoiceId, string $reason = 'invalidate_manual', bool $archive = true): void
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) return;

        $paths = array_unique(array_filter([
            $invoice['pdf_path'] ?? null,
            $this->cachePath($invoice),
        ]));
        foreach ($paths as $p) {
            if (!is_file($p)) continue;
            if ($archive) {
                // Archivuj místo unlink — zachová verzi pro audit a UI historii.
                // archive() přesune soubor do _archive/ (atomic rename, fallback copy+unlink).
                $this->archive->archive($invoiceId, $p, $reason);
            } else {
                // Pro draft cache (např. před alokací VS): jen smaž, bez archive entry.
                @unlink($p);
            }
        }
        $this->db->pdo()->prepare('UPDATE invoices SET pdf_path = NULL, pdf_generated_at = NULL WHERE id = ?')
            ->execute([$invoiceId]);
    }

    /**
     * Bulk invalidate — pro všechny faktury v dané měně, které renderují bank info live
     * (drafts + faktury bez snapshotu). Issued/sent/paid s bank_snapshot mají immutable kopii
     * bank údajů a invalidace by zbytečně regenerovala stejný PDF.
     *
     * Vrací počet invalidovaných faktur.
     */
    public function invalidateByCurrency(int $currencyId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices WHERE currency_id = ? AND (status = "draft" OR bank_snapshot IS NULL)'
        );
        $stmt->execute([$currencyId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($ids as $id) $this->invalidate($id, 'invalidate_currency');
        return count($ids);
    }

    private function cachePath(array $invoice): string
    {
        $rootDir = Bootstrap::rootDir();
        $issueDate = new \DateTimeImmutable($invoice['issue_date']);
        // Multi-supplier: supplier subfolder zabraňuje kolizi varsymbolu mezi suppliery
        $supplierId = (int) ($invoice['supplier_id'] ?? 1);
        $dir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices') . '/sup-' . $supplierId . '/' . $issueDate->format('Y-m');

        $vs = $invoice['varsymbol'] ?? ('draft-' . $invoice['id']);
        // Sanitize varsymbol pro filesystem — defense-in-depth proti path traversal
        // přes importovaný varsymbol (security report @andrejtomci #3 — `varsymbol`
        // se sice už validuje na vstupu ImportService::processOne, ale tady je to
        // belt-and-braces pro případ legacy řádků v DB nebo jiných cest vstupu).
        $vs = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $vs);
        $type = match ($invoice['invoice_type']) {
            'proforma'     => 'Proforma',
            'credit_note'  => 'Dobropis',
            'cancellation' => 'Storno',
            default        => 'Faktura',
        };
        return "$dir/$type-$vs.pdf";
    }

    private function updatePdfPath(int $invoiceId, string $path): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices SET pdf_path = ?, pdf_generated_at = NOW() WHERE id = ?'
        )->execute([$path, $invoiceId]);
    }
}

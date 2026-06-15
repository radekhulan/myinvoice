<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

/**
 * Sdílená konfigurace fontů pro všechny mPDF rendery (faktury, přijaté faktury,
 * Kniha DPH, výkaz práce, kniha jízd).
 *
 * Primární písmo je Montserrat (SIL OFL) — geometrický bezpatkový font s výraznými
 * tučnými řezy a brandovým charakterem, plná česká diakritika, řezy R/B/I/BI.
 * Monospace (částky, varsymboly, IBANy, datumy) je JetBrains Mono (SIL OFL) —
 * tabulkové číslice → zarovnání číselných sloupců; stejný mono brand-font jako appka.
 *
 * DejaVu zůstává jako `backupSubsFont` pro glyfy, které Montserrat nemá
 * (✓ ✗ ◆ ⚠ …) a `dejavusansmono` pro monospace pasáže.
 *
 * Fonty jsou v api/resources/fonts/ — mimo vendor/mpdf/mpdf/ttfonts/, takže je
 * cleanup-mpdf-fonts.php (čistí jen vendor ttfonts) nesmaže.
 *
 * ⚠️ POZOR: tělo dokladu bere font z CSS (`styles/invoice.css`, `dph_book.twig`,
 * inline CSS logbook služeb), které PŘEBÍJÍ `default_font`. Při změně fontu se
 * MUSÍ změnit i `font-family` v těch CSS (viz memory project_pdf_fonts).
 */
final class MpdfFontConfig
{
    public const DEFAULT_FONT = 'montserrat';

    /** Adresář s vlastními TTF fonty. */
    public static function fontDir(): string
    {
        return \dirname(__DIR__, 3) . '/resources/fonts';
    }

    /**
     * Font-related klíče pro Mpdf konstruktor — slij přes array spread / merge
     * do configu daného renderu (přepíše případný 'default_font').
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        $defCfg   = (new ConfigVariables())->getDefaults();
        $defFonts = (new FontVariables())->getDefaults();

        $fontData = $defFonts['fontdata'];
        $fontData['montserrat'] = [
            'R'  => 'Montserrat-Regular.ttf',
            'B'  => 'Montserrat-Bold.ttf',
            'I'  => 'Montserrat-Italic.ttf',
            'BI' => 'Montserrat-BoldItalic.ttf',
        ];
        // Monospace pro číselné pasáže (CSS na ně cílí přes font-family:'jetbrainsmono').
        // Kurzíva se v mono nepoužívá → I/BI mapujeme na R/B.
        $fontData['jetbrainsmono'] = [
            'R'  => 'JetBrainsMono-Regular.ttf',
            'B'  => 'JetBrainsMono-Bold.ttf',
            'I'  => 'JetBrainsMono-Regular.ttf',
            'BI' => 'JetBrainsMono-Bold.ttf',
        ];

        return [
            // PDF/A-3b (ISO 19005-3) — archivní formát pro VŠECHNY PDF výstupy.
            // Tuto sdílenou konfiguraci spreadují všechny renderery (Invoice,
            // PurchaseInvoice, WorkReport, DphBook, 3× Logbook), takže PDF/A se
            // zapne jedním místem. mPDF doplní sRGB OutputIntent + XMP pdfaid;
            // CMYK obrázky PDFAauto převede do sRGB (jeden OutputIntent).
            'PDFA'             => true,
            'PDFAversion'      => '3-B',
            'PDFAauto'         => true,
            'fontDir'          => array_merge($defCfg['fontDir'], [self::fontDir()]),
            'fontdata'         => $fontData,
            'default_font'     => self::DEFAULT_FONT,
            'useSubstitutions' => true,
            'backupSubsFont'   => ['dejavusans', 'dejavusansmono'],
        ];
    }
}

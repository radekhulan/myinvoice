<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Auth\SecretEncryption;

/**
 * Elektronický podpis PDF — PAdES-B (volitelně PAdES-T s RFC 3161 časovým razítkem),
 * čistě v PHP (openssl), bez externí knihovny.
 *
 * Metoda: PDF incremental update (PDF 32000-1 §7.5.6, §12.8). Původní bajty PDF se
 * NEMĚNÍ — připojí se signature dictionary + signature field (AcroForm) + nový
 * cross-reference oddíl. Podpis je detached CMS/PKCS#7 (`openssl_pkcs7_sign`) přes
 * `/ByteRange` (celý soubor kromě hex placeholderu `/Contents`).
 *
 * Předpoklad: vstupní PDF má klasický xref table (mPDF 8.x, PDF 1.4) — NE
 * cross-reference stream. Pokud vstup xref stream nemá, {@see assertClassicXref}
 * vyhodí výjimku a volající provede měkký fallback (nepodepsané PDF).
 */
final class PdfSigner
{
    /** Vyhrazený hex prostor pro /Contents (DER PKCS#7 + případně TSA token). 2× = 16 KiB binárně. */
    private const CONTENTS_HEX_LEN = 16384;

    /** Pevná šířka každého čísla v /ByteRange placeholderu (zarovnání mezerami). */
    private const BR_WIDTH = 10;

    public function __construct(private readonly SecretEncryption $secrets) {}

    /** Podepíše PDF soubor; výsledek zapíše do `<path>.signed` a vrátí jeho cestu. */
    public function signFile(string $pdfPath, SigningConfig $cfg): string
    {
        $pdf = @file_get_contents($pdfPath);
        if ($pdf === false) {
            throw new \RuntimeException("Nelze číst PDF: $pdfPath");
        }
        $out = $pdfPath . '.signed';
        if (@file_put_contents($out, $this->sign($pdf, $cfg)) === false) {
            throw new \RuntimeException("Nelze zapsat podepsané PDF: $out");
        }
        return $out;
    }

    /**
     * Vrátí podepsané PDF jako string. Při jakékoli chybě vyhodí výjimku — volající
     * (renderer) ji zachytí a provede fallback na nepodepsané PDF.
     */
    public function sign(string $pdf, SigningConfig $cfg): string
    {
        $this->assertClassicXref($pdf);

        // 1) Načti cert + privátní klíč z P12 (heslo dešifruj až tady).
        $password = $this->secrets->decrypt($cfg->passwordEnc);
        $p12 = @file_get_contents($cfg->certPath);
        if ($p12 === false) {
            throw new \RuntimeException('Certifikát nelze načíst: ' . $cfg->certPath);
        }
        $certs = [];
        if (!openssl_pkcs12_read($p12, $certs, $password)) {
            throw new \RuntimeException('P12 nelze otevřít (špatné heslo nebo poškozený soubor).');
        }

        // 2) Sestav incremental update s placeholdery (/ByteRange, /Contents).
        $withPlaceholder = $this->buildIncrementalUpdate($pdf, $cfg);

        // 3) Spočítej /ByteRange (offsety hex placeholderu) a přepiš ho (zachová délku).
        [$pdfReady, $byteRange] = $this->fillByteRange($withPlaceholder);

        // 4) Data k podpisu = vše mimo hex obsah /Contents.
        $dataToSign = substr($pdfReady, 0, $byteRange[1])
                    . substr($pdfReady, $byteRange[2], $byteRange[3]);

        // 5) Detached CMS/PKCS#7 (+ volitelně TSA timestamp v Task 6).
        $cms = $this->pkcs7Sign($dataToSign, $certs, $cfg);

        // 6) Vlož hex(CMS) do /Contents placeholderu (zachová délku).
        $hex = bin2hex($cms);
        if (strlen($hex) > self::CONTENTS_HEX_LEN) {
            throw new \RuntimeException('Podpis (' . strlen($hex) . ') je větší než placeholder ' . self::CONTENTS_HEX_LEN . '.');
        }
        $hex = str_pad($hex, self::CONTENTS_HEX_LEN, '0');
        $pos = strpos($pdfReady, '/Contents <') + strlen('/Contents <');
        return substr_replace($pdfReady, $hex, $pos, self::CONTENTS_HEX_LEN);
    }

    /** Vstup musí mít klasický xref table (ne xref stream). */
    private function assertClassicXref(string $pdf): void
    {
        $sx = strrpos($pdf, 'startxref');
        if ($sx === false) {
            throw new \RuntimeException('PDF nemá startxref.');
        }
        $off = (int) trim(substr($pdf, $sx + 9, 40));
        $at = substr($pdf, $off, 4);
        if ($at !== 'xref') {
            throw new \RuntimeException('PDF nemá klasický xref table (pravděpodobně xref stream) — podpis nepodporován.');
        }
    }

    /** Sestaví incremental update: sig dict + widget + override catalog/page + xref + trailer. */
    private function buildIncrementalUpdate(string $pdf, SigningConfig $cfg): string
    {
        $trailer   = $this->lastTrailer($pdf);
        $size      = (int) $this->matchOne('/\/Size\s+(\d+)/', $trailer, 'trailer /Size');
        $rootNum   = (int) $this->matchOne('/\/Root\s+(\d+)\s+\d+\s+R/', $trailer, 'trailer /Root');
        $prevXref  = $this->lastStartxref($pdf);

        $catalogBody = $this->objectBody($pdf, $rootNum);
        $pagesNum    = (int) $this->matchOne('/\/Pages\s+(\d+)\s+\d+\s+R/', $catalogBody, 'catalog /Pages');
        $pagesBody   = $this->objectBody($pdf, $pagesNum);
        // První stránka z /Kids [ N 0 R ... ]
        $pageNum     = (int) $this->matchOne('/\/Kids\s*\[\s*(\d+)\s+\d+\s+R/', $pagesBody, 'pages /Kids');
        $pageBody    = $this->objectBody($pdf, $pageNum);

        $sigNum    = $size;
        $widgetNum = $size + 1;

        // Tělo PDF musí končit newlinem před appendem.
        $base = $pdf;
        if (substr($base, -1) !== "\n") {
            $base .= "\n";
        }

        $brPlaceholder = '[0 ' . str_repeat(' ', self::BR_WIDTH) . ' '
                       . str_repeat(' ', self::BR_WIDTH) . ' '
                       . str_repeat(' ', self::BR_WIDTH) . ']';
        $contentsPlaceholder = '<' . str_repeat('0', self::CONTENTS_HEX_LEN) . '>';
        $date = $this->pdfDate();

        // Nové + přepsané objekty. Offsety zaznamenáme pro xref.
        $offsets = [];
        $body = '';
        $append = function (int $num, string $obj) use (&$body, &$offsets, $base): void {
            $offsets[$num] = strlen($base) + strlen($body);
            $body .= "$num 0 obj\n$obj\nendobj\n";
        };

        // (a) signature dictionary
        $reason = $this->pdfString($cfg->reason);
        $append($sigNum,
            "<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached "
            . "/ByteRange $brPlaceholder /Contents $contentsPlaceholder "
            . "/M ($date) /Reason $reason >>");

        // (b) signature field / widget annotation (neviditelný — Rect 0)
        $append($widgetNum,
            "<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature1) "
            . "/V $sigNum 0 R /Rect [0 0 0 0] /F 132 /P $pageNum 0 R >>");

        // (c) override catalogu: původní klíče + /AcroForm
        $catalogInner = $this->innerDict($catalogBody);
        $acroform = "/AcroForm << /Fields [$widgetNum 0 R] /SigFlags 3 >>";
        $append($rootNum, "<< $catalogInner $acroform >>");

        // (d) override první stránky: původní klíče + /Annots
        $pageInner = $this->innerDict($pageBody);
        $pageInner = $this->mergeAnnots($pageInner, "$widgetNum 0 R");
        $append($pageNum, "<< $pageInner >>");

        // xref oddíl (subsekce seřazené dle čísla objektu)
        $newSize = $size + 2;
        $xrefOffset = strlen($base) + strlen($body);
        $xref = $this->buildXref($offsets);
        $trailerOut = "trailer\n<< /Size $newSize /Root $rootNum 0 R /Prev $prevXref >>\n"
                    . "startxref\n$xrefOffset\n%%EOF\n";

        return $base . $body . $xref . $trailerOut;
    }

    /** Sestaví klasický xref oddíl ze seznamu offsetů (číslo objektu => offset). */
    private function buildXref(array $offsets): string
    {
        ksort($offsets);
        $xref = "xref\n";
        // seskup do souvislých subsekcí
        $nums = array_keys($offsets);
        $i = 0;
        $n = count($nums);
        while ($i < $n) {
            $start = $nums[$i];
            $group = [$nums[$i]];
            $j = $i + 1;
            while ($j < $n && $nums[$j] === $nums[$j - 1] + 1) {
                $group[] = $nums[$j];
                $j++;
            }
            $xref .= $start . ' ' . count($group) . "\n";
            foreach ($group as $num) {
                $xref .= sprintf("%010d 00000 n \n", $offsets[$num]);
            }
            $i = $j;
        }
        return $xref;
    }

    /** Spočítá /ByteRange a přepíše placeholder (zachová délku). @return array{0:string,1:array{0:int,1:int,2:int,3:int}} */
    private function fillByteRange(string $pdf): array
    {
        $lt = strpos($pdf, '/Contents <');
        if ($lt === false) {
            throw new \RuntimeException('Placeholder /Contents nenalezen.');
        }
        $contentStart = $lt + strlen('/Contents <');          // první hex bajt
        $gt = strpos($pdf, '>', $contentStart);                // uzavírací >
        $x1 = $contentStart;                                   // [0, x1) = vše po '<' včetně
        $x2 = $gt;                                             // [x2, ..) = od '>' včetně
        $x3 = strlen($pdf) - $x2;
        $byteRange = [0, $x1, $x2, $x3];

        $real = sprintf(
            '[0 %s %s %s]',
            str_pad((string) $x1, self::BR_WIDTH, ' ', STR_PAD_LEFT),
            str_pad((string) $x2, self::BR_WIDTH, ' ', STR_PAD_LEFT),
            str_pad((string) $x3, self::BR_WIDTH, ' ', STR_PAD_LEFT),
        );
        $placeholder = '[0 ' . str_repeat(' ', self::BR_WIDTH) . ' '
                     . str_repeat(' ', self::BR_WIDTH) . ' '
                     . str_repeat(' ', self::BR_WIDTH) . ']';
        $brPos = strpos($pdf, '/ByteRange ') + strlen('/ByteRange ');
        if (strlen($real) !== strlen($placeholder)) {
            throw new \RuntimeException('ByteRange délka neodpovídá placeholderu.');
        }
        $pdf = substr_replace($pdf, $real, $brPos, strlen($placeholder));
        return [$pdf, $byteRange];
    }

    /** Detached CMS/PKCS#7 (DER). TSA timestamp se doplní v Task 6. */
    private function pkcs7Sign(string $data, array $certs, SigningConfig $cfg): string
    {
        $in  = tempnam(sys_get_temp_dir(), 'sig-in-');
        $out = tempnam(sys_get_temp_dir(), 'sig-out-');
        file_put_contents($in, $data);
        $ok = openssl_pkcs7_sign(
            $in, $out, $certs['cert'], $certs['pkey'], [],
            PKCS7_BINARY | PKCS7_DETACHED | PKCS7_NOATTR
        );
        @unlink($in);
        if (!$ok) {
            @unlink($out);
            throw new \RuntimeException('openssl_pkcs7_sign selhal: ' . openssl_error_string());
        }
        $smime = (string) file_get_contents($out);
        @unlink($out);
        return $this->derFromSmime($smime);
    }

    /**
     * Vytáhne binární DER PKCS#7 z S/MIME výstupu openssl_pkcs7_sign.
     *
     * Výstup je multipart/signed; podpis je v poslední části
     * `application/x-pkcs7-signature` (base64) mezi jejími hlavičkami a MIME boundary.
     */
    private function derFromSmime(string $smime): string
    {
        // POSLEDNÍ výskyt — první je v hlavičce protocol="application/x-pkcs7-signature",
        // ten pravý (sekce s base64 podpisem) je až dole.
        $sigPos = strrpos($smime, 'application/x-pkcs7-signature');
        if ($sigPos === false) {
            throw new \RuntimeException('S/MIME neobsahuje x-pkcs7-signature sekci.');
        }
        // konec hlaviček té sekce = první prázdný řádek za sigPos
        if (!preg_match('/\r?\n\r?\n/', $smime, $m, PREG_OFFSET_CAPTURE, $sigPos)) {
            throw new \RuntimeException('S/MIME: chybí tělo signature sekce.');
        }
        $bodyStart = $m[0][1] + strlen($m[0][0]);
        // base64 tělo až po boundary (řádek začínající "------")
        $rest = substr($smime, $bodyStart);
        $end = preg_match('/\r?\n------/', $rest, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : strlen($rest);
        $b64 = preg_replace('/\s+/', '', substr($rest, 0, $end));
        $der = base64_decode((string) $b64, true);
        if ($der === false || $der === '') {
            throw new \RuntimeException('Nelze extrahovat PKCS#7 DER z S/MIME.');
        }
        return $der;
    }

    // ---- PDF parsing helpery ----

    private function lastTrailer(string $pdf): string
    {
        $pos = strrpos($pdf, 'trailer');
        if ($pos === false) {
            throw new \RuntimeException('PDF nemá trailer.');
        }
        return substr($pdf, $pos, 600);
    }

    private function lastStartxref(string $pdf): int
    {
        $pos = strrpos($pdf, 'startxref');
        if ($pos === false) {
            throw new \RuntimeException('PDF nemá startxref.');
        }
        return (int) trim(substr($pdf, $pos + 9, 40));
    }

    /** Vrátí celé tělo objektu "N 0 obj << ... >>" (mezi obj a endobj). */
    private function objectBody(string $pdf, int $num): string
    {
        if (!preg_match('/\b' . $num . '\s+0\s+obj\b/', $pdf, $m, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException("Objekt $num 0 obj nenalezen.");
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $end = strpos($pdf, 'endobj', $start);
        if ($end === false) {
            throw new \RuntimeException("Objekt $num: chybí endobj.");
        }
        return trim(substr($pdf, $start, $end - $start));
    }

    /** Z "<< ... >>" vrátí vnitřek (bez vnějších << >>). */
    private function innerDict(string $body): string
    {
        $s = strpos($body, '<<');
        $e = strrpos($body, '>>');
        if ($s === false || $e === false) {
            throw new \RuntimeException('Objekt není slovník << >>.');
        }
        return trim(substr($body, $s + 2, $e - $s - 2));
    }

    /** Přidá widget do /Annots (vytvoří nebo rozšíří existující pole). */
    private function mergeAnnots(string $inner, string $widgetRef): string
    {
        if (preg_match('/\/Annots\s*\[([^\]]*)\]/', $inner, $m)) {
            $merged = '/Annots [' . trim($m[1]) . " $widgetRef]";
            return str_replace($m[0], $merged, $inner);
        }
        return $inner . " /Annots [$widgetRef]";
    }

    private function matchOne(string $re, string $subject, string $what): string
    {
        if (!preg_match($re, $subject, $m)) {
            throw new \RuntimeException("Nenalezeno: $what");
        }
        return $m[1];
    }

    private function pdfDate(): string
    {
        // D:YYYYMMDDHHmmSS+TZ — bez závislosti na Date (CLI) přes gmdate je OK i ve workflow.
        return 'D:' . date('YmdHis') . "+00'00'";
    }

    private function pdfString(string $s): string
    {
        return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s) . ')';
    }
}

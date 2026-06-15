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
    /**
     * Vyhrazený hex prostor pro /Contents = 2× binární DER podpisu.
     * 64 KiB hex = 32 KiB binárně — pokryje CMS + celý cert řetězec + RFC 3161
     * TSA token (i kvalifikovaný, který bývá 6–8 KB). Bez TSA stačí ~14 KB hex,
     * ale s chainem + timestamp narůstá přes 24 KB → rezerva nutná.
     */
    private const CONTENTS_HEX_LEN = 65536;

    /** Pevná šířka každého čísla v /ByteRange placeholderu (zarovnání mezerami). */
    private const BR_WIDTH = 10;

    /** Byl na poslední podpis skutečně aplikován TSA timestamp (PAdES-T)? Jinak PAdES-B. */
    private bool $lastTimestamped = false;
    private ?string $lastCertificateCommonName = null;

    public function __construct(private readonly SecretEncryption $secrets) {}

    /** True, pokud poslední {@see sign()} reálně doplnil RFC 3161 timestamp (PAdES-T). */
    public function lastTimestamped(): bool
    {
        return $this->lastTimestamped;
    }

    public function lastCertificateCommonName(): ?string
    {
        return $this->lastCertificateCommonName;
    }

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
        $this->lastTimestamped = false;
        $this->lastCertificateCommonName = null;
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
        $this->lastCertificateCommonName = $this->certificateCommonName((string) ($certs['cert'] ?? ''));

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

    private function certificateCommonName(string $certPem): ?string
    {
        if ($certPem === '') {
            return null;
        }
        $info = openssl_x509_parse($certPem);
        if (!is_array($info)) {
            return null;
        }
        $subject = $info['subject'] ?? [];
        if (!is_array($subject) || empty($subject['CN']) || !is_scalar($subject['CN'])) {
            return null;
        }

        $cn = trim((string) $subject['CN']);
        return $cn !== '' ? $cn : null;
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
            "<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached "
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
        // PDF/A vyžaduje /ID v traileru (ISO 19005-3 clause 6.1.3). Inkrementální
        // update musí původní /ID zachovat, jinak podepsané PDF přestane být PDF/A
        // (ověřeno veraPDF). Přeneseme /ID z posledního traileru vstupního PDF.
        $id = preg_match('~/ID\s*\[\s*<[^>]*>\s*<[^>]*>\s*\]~', $trailer, $m) ? ' ' . $m[0] : '';
        $trailerOut = "trailer\n<< /Size $newSize /Root $rootNum 0 R /Prev $prevXref$id >>\n"
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
        // STANDARDNÍ konvence (Adobe/pyHanko): ByteRange vynechává /Contents hex
        // VČETNĚ obklopujících závorek < >. Tj. x1 = pozice '<', x2 = pozice za '>'.
        // Podepsaná data = [0,x1) + [x2,konec) — bez závorek i hexu.
        $ltBracket = $lt + strlen('/Contents ');               // pozice '<'
        $gt = strpos($pdf, '>', $ltBracket + 1);               // uzavírací '>'
        $x1 = $ltBracket;                                      // NA '<' (gap zahrne '<')
        $x2 = $gt + 1;                                         // ZA '>' (gap zahrne '>')
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

    /** Detached CMS (RFC 5652) v DER. Volitelně PAdES-T timestamp. */
    private function pkcs7Sign(string $data, array $certs, SigningConfig $cfg): string
    {
        $in  = tempnam(sys_get_temp_dir(), 'sig-in-');
        $out = tempnam(sys_get_temp_dir(), 'sig-out-');
        file_put_contents($in, $data);

        // Řetězec certifikátů (intermediate CA) z P12 → vloží se do podpisu, aby
        // čtečka uměla postavit cestu k důvěryhodné kořenové i bez AATL/EUTL.
        $chainFile = null;
        if (!empty($certs['extracerts']) && is_array($certs['extracerts'])) {
            $chainFile = tempnam(sys_get_temp_dir(), 'chain-');
            file_put_contents($chainFile, implode("\n", $certs['extracerts']));
        }

        // openssl_cms_sign + OPENSSL_ENCODING_DER → moderní CMS (RFC 5652) PŘÍMO v DER
        // (žádné S/MIME parsování). To je struktura, kterou PDF čtečky (Adobe, PDF-XChange)
        // očekávají pro /SubFilter adbe.pkcs7.detached. Legacy openssl_pkcs7_sign produkoval
        // strukturu, kterou Adobe odmítal jako „SigDict /Contents illegal data".
        // DETACHED + BINARY, signed attributes ponechány (bez NOATTR).
        $flags = OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY;
        $ok = openssl_cms_sign(
            $in, $out, $certs['cert'], $certs['pkey'], [], $flags,
            OPENSSL_ENCODING_DER, $chainFile,
        );
        @unlink($in);
        if ($chainFile !== null) { @unlink($chainFile); }
        if (!$ok) {
            @unlink($out);
            throw new \RuntimeException('openssl_cms_sign selhal: ' . openssl_error_string());
        }
        $der = (string) file_get_contents($out);   // přímo DER, bez S/MIME extrakce
        @unlink($out);

        // PAdES-T: přidej RFC 3161 timestamp token jako unsigned attribute do SignerInfo.
        // Při jakékoli chybě TSA tiše degraduj na PAdES-B (timestamp je opt-in).
        if ($cfg->tsaUrl !== null && $cfg->tsaUrl !== '') {
            try {
                $der = $this->addTimestamp($der, $cfg);
                $this->lastTimestamped = true;
            } catch (\Throwable) {
                // ponech $der (PAdES-B) — výpadek TSA nesmí shodit podpis (timestamp je opt-in)
            }
        }
        return $der;
    }

    /** Přidá do CMS signature-timestamp (id-aa-timeStampToken) nad hodnotou podpisu. */
    private function addTimestamp(string $cmsDer, SigningConfig $cfg): string
    {
        $sigValue = $this->signatureValue($cmsDer);
        $token = $this->requestTimestamp($sigValue, (string) $cfg->tsaUrl, $cfg->tsaUsername, $cfg->tsaPasswordEnc);
        return $this->insertTimestampToken($cmsDer, $token);
    }

    /** Vytáhne hodnotu podpisu (SignerInfo.signature OCTET STRING) z CMS. */
    private function signatureValue(string $cmsDer): string
    {
        $si = $this->signerInfo($cmsDer);
        // signature = poslední OCTET STRING (tag 0x04) v SignerInfo (bez signed/unsigned attrs)
        $sig = null;
        foreach ($si['children'] as $c) {
            if ($c['tag'] === 0x04) {
                $sig = $c['raw'];
            }
        }
        if ($sig === null) {
            throw new \RuntimeException('CMS: nelze najít hodnotu podpisu.');
        }
        return $sig;
    }

    /**
     * Pošle hash na TSA (RFC 3161), vrátí timeStampToken (ContentInfo DER). Timeout 5 s.
     * Volitelná HTTP Basic auth (jméno + zašifrované heslo) pro produkční/kvalifikované TSA.
     */
    private function requestTimestamp(string $data, string $tsaUrl, ?string $username = null, string $passwordEnc = ''): string
    {
        $hash = hash('sha256', $data, true);
        // TimeStampReq: SEQ { version 1, messageImprint SEQ { alg SEQ{sha256,NULL}, OCTET hash }, certReq TRUE }
        $alg = Asn1::tlv(0x30, Asn1::tlv(0x06, Asn1::oid('2.16.840.1.101.3.4.2.1')) . Asn1::tlv(0x05, ''));
        $msgImprint = Asn1::tlv(0x30, $alg . Asn1::tlv(0x04, $hash));
        $tsq = Asn1::tlv(0x30, Asn1::tlv(0x02, chr(1)) . $msgImprint . Asn1::tlv(0x01, chr(0xFF)));

        $ch = curl_init($tsaUrl);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/timestamp-query'],
            CURLOPT_POSTFIELDS => $tsq,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ];
        // HTTP Basic auth k TSA (jméno+heslo) — heslo dešifruj až tady
        if ($username !== null && $username !== '') {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD] = $username . ':' . ($passwordEnc !== '' ? $this->secrets->decrypt($passwordEnc) : '');
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $resp === '') {
            throw new \RuntimeException('TSA nedostupná: ' . $err);
        }
        return $this->tokenFromTsr((string) $resp);
    }

    /** Z TimeStampResp vytáhne timeStampToken (ContentInfo). Ověří PKIStatus granted. */
    private function tokenFromTsr(string $tsr): string
    {
        $off = 0;
        $nodes = Asn1::decode($tsr, $off, strlen($tsr));
        $resp = $nodes[0] ?? null; // TimeStampResp SEQ { PKIStatusInfo, timeStampToken? }
        if ($resp === null || empty($resp['children'])) {
            throw new \RuntimeException('TSA: neplatná odpověď.');
        }
        // PKIStatusInfo SEQ { status INTEGER } — 0 granted, 1 grantedWithMods
        $statusInfo = $resp['children'][0];
        $status = isset($statusInfo['children'][0]) ? ord($statusInfo['children'][0]['raw'][0] ?? "\xFF") : 0xFF;
        if ($status !== 0 && $status !== 1) {
            throw new \RuntimeException('TSA status != granted (' . $status . ').');
        }
        $token = $resp['children'][1] ?? null; // ContentInfo (timeStampToken)
        if ($token === null) {
            throw new \RuntimeException('TSA: chybí timeStampToken.');
        }
        return Asn1::encode([$token]);
    }

    /** Vloží timeStampToken jako unsigned attribute (id-aa-timeStampToken) do SignerInfo. */
    private function insertTimestampToken(string $cmsDer, string $tokenDer): string
    {
        $off = 0;
        $tree = Asn1::decode($cmsDer, $off, strlen($cmsDer));
        // ContentInfo SEQ → [1]=[0] EXPLICIT → [0]=SignedData SEQ
        $sd = $tree[0]['children'][1]['children'][0];
        // signerInfos = poslední child se SET (tag 0x31)
        $siSetIdx = null;
        foreach ($sd['children'] as $i => $c) {
            if ($c['tag'] === 0x31) { $siSetIdx = $i; }
        }
        if ($siSetIdx === null) {
            throw new \RuntimeException('CMS: nenalezen SignerInfos SET.');
        }
        // unsignedAttrs: [1] IMPLICIT SET OF Attribute { OID timeStampToken, SET { token } }
        $attr = Asn1::tlv(0x30,
            Asn1::tlv(0x06, Asn1::oid('1.2.840.113549.1.9.16.2.14')) . Asn1::tlv(0x31, $tokenDer));
        $unsignedAttrs = Asn1::tlv(0xA1, $attr);
        $o2 = 0;
        $uaNode = Asn1::decode($unsignedAttrs, $o2, strlen($unsignedAttrs))[0];

        // přidej unsignedAttrs do prvního SignerInfo (in-place přes index chain)
        $tree[0]['children'][1]['children'][0]['children'][$siSetIdx]['children'][0]['children'][] = $uaNode;
        return Asn1::encode($tree);
    }

    /** Vrátí uzel prvního SignerInfo z CMS DER. */
    private function signerInfo(string $cmsDer): array
    {
        $off = 0;
        $tree = Asn1::decode($cmsDer, $off, strlen($cmsDer));
        $sd = $tree[0]['children'][1]['children'][0];
        $siSetIdx = null;
        foreach ($sd['children'] as $i => $c) {
            if ($c['tag'] === 0x31) { $siSetIdx = $i; }
        }
        if ($siSetIdx === null || empty($sd['children'][$siSetIdx]['children'][0])) {
            throw new \RuntimeException('CMS: nenalezen SignerInfo.');
        }
        return $sd['children'][$siSetIdx]['children'][0];
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
        // D:YYYYMMDDHHmmSS+00'00' — MUSÍ být UTC (gmdate), ne lokální date(): označení
        // +00'00' deklaruje UTC, takže s date() (Europe/Prague = UTC+2) by Adobe viděl
        // podpis 2h v budoucnosti → „podepsán v budoucnosti / SigDict invalid".
        return 'D:' . gmdate('YmdHis') . "+00'00'";
    }

    private function pdfString(string $s): string
    {
        return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s) . ')';
    }
}

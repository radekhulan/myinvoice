<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\PurchaseInvoiceRepository;

/**
 * Jednotné uložení čitelného PDF přijaté faktury do tenant archivu + zápis metadat
 * (pdf_path / pdf_hash / pdf_size / original_name). Sdílené VŠEMI importními cestami,
 * aby `.isdocx` i embedded ISDOC archivovaly PDF stejně bez ohledu na vstupní bránu:
 *   - {@see AiPdfExtractor}              — dropzone / AI upload (vč. ISDOCX a embedded ISDOC),
 *   - {@see InvoiceImportService}        — dávkový import (/admin/import?tab=purchase),
 *   - {@see PurchaseInvoiceInboxScanner} — cron scan inbox adresáře.
 *
 * Resoluce archive rootu ZRCADLÍ read-side ({@see \MyInvoice\Service\Logbook\PurchaseInvoicePdfReader}
 * a DownloadPurchaseInvoicePdfAction): `archive_storage` → `dirname(uploads_dir)/purchase-invoices`
 * → {@see RuntimePaths::storage()}. Jméno souboru na disku = prvních 16 znaků SHA-256 obsahu
 * (dedup uvnitř tenanta); sloupec `pdf_hash` nese `hashKey` (default = hash bajtů, ISDOCX inbox
 * scan předává hash celého `.isdocx` kvůli scanner dedupu).
 *
 * Layout na disku: `supplier-{id}/{2 znaky hashe}/{16 znaků hashe}.pdf` — hash-shard adresář
 * drží počet souborů v jednom adresáři rozumný (≤256 shardů) a zachovává content-addressed
 * dedup (stejný obsah = stejná cesta). Read-side čte uloženou relativní cestu z DB, takže
 * STARÉ ploché soubory (`supplier-{id}/{hash}.pdf`) dál fungují beze změny. Statické helpery
 * {@see shardedRelPath()}/{@see ensureShardPath()} sdílí všichni zapisovatelé do tohoto úložiště
 * (manuální upload, iDoklad/Fakturoid import) i paralelní `invoices-imported` store.
 *
 * Silent-fail: úspěšný import faktury je důležitější než archivace PDF (PDF lze nahrát ručně).
 */
final class PurchaseInvoicePdfArchiver
{
    public function __construct(
        private readonly Config $config,
        private readonly PurchaseInvoiceRepository $repo,
    ) {}

    /**
     * Relativní cesta souboru na disku (vůči archive rootu) pro daný hash — hash-shard
     * `supplier-{id}/{2}/{16}.pdf`. Jediný zdroj pravdy pro layout; používají ji VŠECHNY
     * cesty zapisující do purchase-invoices / invoices-imported úložiště.
     */
    public static function shardedRelPath(int $supplierId, string $hash, string $ext = 'pdf'): string
    {
        return 'supplier-' . $supplierId . '/' . substr($hash, 0, 2) . '/' . substr($hash, 0, 16) . '.' . ltrim($ext, '.');
    }

    /**
     * Sestaví absolutní cílovou cestu pod `$archiveRoot`, vytvoří shard adresář (best-effort)
     * a vrátí cestu k souboru. Caller pak zapíše obsah (a obvyklé chybové ošetření zápisu řeší sám).
     */
    public static function ensureShardPath(string $archiveRoot, int $supplierId, string $hash): string
    {
        $abs = rtrim($archiveRoot, '/\\') . DIRECTORY_SEPARATOR
             . str_replace('/', DIRECTORY_SEPARATOR, self::shardedRelPath($supplierId, $hash));
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $abs;
    }

    /**
     * Uloží PDF z bajtů v paměti (ISDOCX vnitřní PDF, AI/embedded PDF, dávkový import).
     * Nic neukládá, když to nejsou validní PDF bajty (`%PDF`).
     *
     * @param string      $pdfBytes     Čitelné PDF (musí začínat `%PDF`).
     * @param string|null $originalName Původní název souboru (fallback 'imported.pdf').
     * @param string|null $hashKey      Hodnota sloupce `pdf_hash` (default = hash($pdfBytes)).
     */
    public function archiveBytes(int $invoiceId, int $supplierId, string $pdfBytes, ?string $originalName, ?string $hashKey = null): void
    {
        if ($pdfBytes === '' || !str_starts_with($pdfBytes, '%PDF')) {
            return;
        }
        try {
            $contentSha = hash('sha256', $pdfBytes);
            $finalPath  = self::ensureShardPath($this->archiveRoot(), $supplierId, $contentSha);
            if (!is_file($finalPath)) {
                @file_put_contents($finalPath, $pdfBytes);
            }
            $this->repo->setPdfMetadata(
                $invoiceId,
                $supplierId,
                self::shardedRelPath($supplierId, $contentSha),
                $hashKey ?? $contentSha,
                (int) @filesize($finalPath),
                $originalName ?: 'imported.pdf',
            );
        } catch (\Throwable) {
            // Silent — úspěšný import faktury je důležitější než archivace PDF.
        }
    }

    /**
     * Zkopíruje PDF ze zdrojové cesty (inbox scan). Jméno souboru na disku i `pdf_hash`
     * vychází z hashe SOUBORU (`$fileSha`); volitelný `$hashKey` přebije `pdf_hash`
     * (např. .isdocx, kde se na disk zapisuje vnitřní PDF přes {@see archiveBytes()}).
     */
    public function archiveFile(int $invoiceId, int $supplierId, string $sourcePath, ?string $originalName, string $fileSha, ?int $size = null): void
    {
        try {
            $finalPath = self::ensureShardPath($this->archiveRoot(), $supplierId, $fileSha);
            if (!is_file($finalPath)) {
                @copy($sourcePath, $finalPath);
            }
            $this->repo->setPdfMetadata(
                $invoiceId,
                $supplierId,
                self::shardedRelPath($supplierId, $fileSha),
                $fileSha,
                $size ?? (int) @filesize($finalPath),
                $originalName ?: basename($sourcePath),
            );
        } catch (\Throwable) {
            // Silent.
        }
    }

    /** Přípona souboru na disku pro daný source_format (strojový originál). */
    private const SOURCE_EXT = [
        'isdoc'          => 'isdoc',
        'isdocx'         => 'isdocx',
        'pdf'            => 'pdf',
        'pohoda_xml'     => 'xml',
        'idoklad_json'   => 'json',
        'fakturoid_json' => 'json',
    ];

    /**
     * Uloží ZDROJOVÝ artefakt (strojově čitelný originál — ISDOC/ISDOCX/Pohoda XML/…)
     * přijaté faktury do odděleného `sources/` podstromu archivu + zapíše `source_*`
     * metadata (write-once přes {@see PurchaseInvoiceRepository::setSourceMetadata}).
     *
     * Bajty se ukládají AS-IS — u `.isdocx` NEROZBALENÉ (zachová podpis ZIP obálky),
     * u embedded ISDOC v PDF/A-3 vytažený XML. Magic-byte sanity check dle formátu,
     * ať se neuloží podvržený obsah. Silent-fail jako PDF archivace (import přednější).
     *
     * @param string $format Hodnota ENUM `source_format` (isdoc/isdocx/pdf/pohoda_xml/…).
     */
    public function archiveSourceBytes(int $invoiceId, int $supplierId, string $bytes, ?string $originalName, string $format): void
    {
        $ext = self::SOURCE_EXT[$format] ?? null;
        if ($bytes === '' || $ext === null || !self::sourceMagicOk($format, $bytes)) {
            return;
        }
        try {
            $contentSha = hash('sha256', $bytes);
            $rel = 'sources/' . self::shardedRelPath($supplierId, $contentSha, $ext);
            $abs = rtrim($this->archiveRoot(), '/\\') . DIRECTORY_SEPARATOR
                 . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $dir = dirname($abs);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_file($abs)) {
                @file_put_contents($abs, $bytes);
            }
            $this->repo->setSourceMetadata(
                $invoiceId,
                $supplierId,
                $rel,
                $contentSha,
                (int) @filesize($abs),
                $originalName ?: ('source.' . $ext),
                $format,
            );
        } catch (\Throwable) {
            // Silent — úspěšný import faktury je důležitější než archivace zdroje.
        }
    }

    /** Magic-byte sanity check podle formátu (ať se neuloží podvržený obsah). */
    private static function sourceMagicOk(string $format, string $bytes): bool
    {
        $head = substr($bytes, 0, 64);
        return match ($format) {
            'isdoc', 'pohoda_xml'            => str_contains($head, '<'),
            'isdocx'                         => str_starts_with($bytes, "PK\x03\x04"),
            'pdf'                            => str_starts_with($bytes, '%PDF'),
            'idoklad_json', 'fakturoid_json' => str_contains($head, '{') || str_contains($head, '['),
            default                          => false,
        };
    }

    /** Adresář archivu pro tenanta (vytvoří ho, pokud chybí). */
    public function tenantDir(int $supplierId): string
    {
        $tenantDir = $this->archiveRoot() . DIRECTORY_SEPARATOR . 'supplier-' . $supplierId;
        if (!is_dir($tenantDir)) {
            @mkdir($tenantDir, 0755, true);
        }
        return $tenantDir;
    }

    private function archiveRoot(): string
    {
        $dir = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($dir !== '') {
            return $dir;
        }
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') {
            return dirname($uploads) . '/purchase-invoices';
        }
        return RuntimePaths::storage('purchase-invoices');
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/invoices/{id}/imported-pdf
 *
 * Stáhne archivovaný ZDROJOVÝ PDF vydané faktury tak, jak dorazil z importu
 * (iDoklad/Fakturoid) — uložený v `imported_pdf_*` columnech, MIMO náš vlastní
 * rendered PDF (`pdf_path`). Parita s {@see \MyInvoice\Action\PurchaseInvoice\DownloadPurchaseInvoicePdfAction}
 * pro přijaté faktury: stream přes PHP, soubor je mimo webroot, path traversal je
 * defenzivně zabaleno přes realpath() check vůči import-archive rootu.
 *
 * Autorizace:
 *   1. SupplierGuard::owns (faktura patří current supplier scope)
 *   2. path-traversal guard (výsledná cesta nesmí utéct z archive rootu)
 *
 * Pokud faktura nemá `imported_pdf_path` → 404.
 */
final class DownloadImportedPdfAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $relativePath = (string) ($invoice['imported_pdf_path'] ?? '');
        if ($relativePath === '') {
            return Json::error($response, 'no_pdf', 'K této faktuře není archivovaný zdrojový PDF dokument.', 404);
        }

        $archiveRoot = $this->resolveArchiveRoot();
        $archiveRootReal = realpath($archiveRoot);
        if ($archiveRootReal === false) {
            return Json::error($response, 'storage_unavailable', 'Archiv nelze nalézt.', 500);
        }

        // Sestavit absolutní cestu a ověřit, že NEUTÍKÁ z archiveRoot (path traversal guard).
        $fullPath = $archiveRootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $fullPathReal = realpath($fullPath);
        if ($fullPathReal === false || !is_file($fullPathReal)) {
            return Json::error($response, 'file_missing', 'Archivovaný soubor nebyl na disku nalezen.', 404);
        }
        // Windows je case-insensitive FS — realpath() může vrátit nekonzistentní casing
        // mezi archiveRootReal a fullPathReal. Na Linuxu zachováme striktní compare.
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $haystack = ($isWindows ? strtolower($fullPathReal) : $fullPathReal);
        $needle   = ($isWindows ? strtolower($archiveRootReal) : $archiveRootReal) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($haystack, $needle)) {
            return Json::error($response, 'forbidden', 'Cesta mimo archive root.', 403);
        }

        $size = filesize($fullPathReal);
        if ($size === false) {
            return Json::error($response, 'read_failed', 'Nelze přečíst velikost souboru.', 500);
        }

        $downloadName = (string) ($invoice['imported_pdf_original_name']
            ?? ('faktura-' . ($invoice['varsymbol'] ?? $id) . '.pdf'));
        $downloadName = preg_replace('/[\x00-\x1F"<>|*?:\\\\\/]/', '_', $downloadName) ?: 'invoice.pdf';
        // Obsah je VŽDY PDF (Content-Type níže). Vynuť .pdf příponu, ať prohlížeč
        // neuloží soubor pod jinou/žádnou příponou.
        if (!preg_match('/\.pdf$/i', $downloadName)) {
            $downloadName = preg_replace('/\.[^.]+$/', '', $downloadName) . '.pdf';
        }

        // Stream — pro velké soubory nestavíme do paměti.
        $stream = fopen($fullPathReal, 'rb');
        if ($stream === false) {
            return Json::error($response, 'read_failed', 'Nepodařilo se otevřít soubor.', 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.imported_pdf_downloaded', $user['id'] ?? null, 'invoice', $id,
            ['size_bytes' => $size], $ip, $request->getHeaderLine('User-Agent'));

        $body = $response->getBody();
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $body->write($chunk);
        }
        fclose($stream);

        // `?inline=1` → Content-Disposition: inline (povolí browser-native PDF viewer v iframe).
        // Bez parametru: attachment (download). Detail page má iframe preview přes ?inline=1.
        $inline = !empty($request->getQueryParams()['inline']);
        $disposition = ($inline ? 'inline' : 'attachment') . '; filename="' . $downloadName . '"';

        $resp = $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        // CSP override pro inline mode — globální .htaccess/web.config nastavuje
        // `frame-ancestors 'none'` (anti-clickjacking). To by zakázalo embed PDF do
        // iframe v naší vlastní SPA. Override per-response na `'self'` umožňuje
        // embed jen z naší origin. X-Frame-Options jako fallback pro starší browsery.
        if ($inline) {
            $resp = $resp
                ->withHeader('Content-Security-Policy', "frame-ancestors 'self'")
                ->withHeader('X-Frame-Options', 'SAMEORIGIN');
        }

        return $resp;
    }

    /**
     * Import-archive root pro vydané faktury. MUSÍ být shodný s tím, kam zapisuje
     * import (IdokladImportService/FakturoidImportService::archiveIssuedPdf), tzn.
     * config klíč `invoice.import_archive_storage` s fallbackem na `invoices-imported`.
     */
    private function resolveArchiveRoot(): string
    {
        $dir = (string) $this->config->get('invoice.import_archive_storage', '');
        if ($dir !== '') {
            return $dir;
        }
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') {
            return dirname($uploads) . '/invoices-imported';
        }
        return RuntimePaths::storage('invoices-imported');
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices/{id}/source
 *
 * Stáhne archivovaný ZDROJOVÝ artefakt (strojově čitelný originál — ISDOC/ISDOCX/Pohoda
 * XML/…), pokud existuje. Bajty se vrací AS-IS (u .isdocx nerozbalené). Stream přes PHP,
 * soubor je MIMO webroot; path traversal je zabaleno přes realpath() check vůči archive
 * rootu — shodně s {@see DownloadPurchaseInvoicePdfAction}.
 *
 * Pokud faktura nemá source_path → 404.
 */
final class DownloadPurchaseInvoiceSourceAction
{
    /** source_format → Content-Type pro stažení. */
    private const FORMAT_MIME = [
        'isdoc'          => 'application/xml',
        'isdocx'         => 'application/zip',
        'pdf'            => 'application/pdf',
        'pohoda_xml'     => 'application/xml',
        'idoklad_json'   => 'application/json',
        'fakturoid_json' => 'application/json',
    ];

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
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

        $supplierId = SupplierGuard::currentId($request);
        $invoice = $this->repo->find($id, $supplierId);
        if ($invoice === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $relativePath = (string) ($invoice['source_path'] ?? '');
        if ($relativePath === '') {
            return Json::error($response, 'no_source', 'K této faktuře není archivovaný zdrojový doklad.', 404);
        }

        $archiveRoot = $this->resolveArchiveRoot();
        $archiveRootReal = realpath($archiveRoot);
        if ($archiveRootReal === false) {
            return Json::error($response, 'storage_unavailable', 'Archiv nelze nalézt.', 500);
        }

        // Path traversal guard (shodně s PDF download action).
        $fullPath = $archiveRootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $fullPathReal = realpath($fullPath);
        if ($fullPathReal === false || !is_file($fullPathReal)) {
            return Json::error($response, 'file_missing', 'Archivovaný zdroj nebyl na disku nalezen.', 404);
        }
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

        $format = (string) ($invoice['source_format'] ?? '');
        $mime   = self::FORMAT_MIME[$format] ?? 'application/octet-stream';
        $downloadName = (string) ($invoice['source_original_name']
            ?? ('zdroj-' . ($invoice['vendor_invoice_number'] ?? $id)));
        $downloadName = preg_replace('/[\x00-\x1F"<>|*?:\\\\\/]/', '_', $downloadName) ?: 'source';

        $stream = fopen($fullPathReal, 'rb');
        if ($stream === false) {
            return Json::error($response, 'read_failed', 'Nepodařilo se otevřít soubor.', 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.source_downloaded', $user['id'] ?? null, 'purchase_invoice', $id,
            ['size_bytes' => $size, 'format' => $format], $ip, $request->getHeaderLine('User-Agent'));

        $body = $response->getBody();
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) break;
            $body->write($chunk);
        }
        fclose($stream);

        // Zdroj je strojový artefakt — vždy attachment (na rozdíl od PDF náhledu).
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $downloadName . '"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function resolveArchiveRoot(): string
    {
        $dir = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($dir !== '') return $dir;
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') return dirname($uploads) . '/purchase-invoices';
        return RuntimePaths::storage('purchase-invoices');
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class PdfAction
{
    public function __construct(
        private readonly InvoicePdfRenderer $renderer,
        private readonly InvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ExchangeRateApplier $rateApplier,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // Vypni HTML error output (deprecation warnings z 3rd party libs by jinak
        // skončily v PDF binary streamu).
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        // Backfill kurzu (cache → ČNB → last known) pro EUR / cizí měnu bez kurzu —
        // PDF musí přepočet vždy obsahovat, pokud je kurz dostupný.
        if (
            (string) ($invoice['currency'] ?? 'CZK') !== 'CZK'
            && empty($invoice['exchange_rate'])
        ) {
            $this->rateApplier->ensureRate($id);
        }

        $q = $request->getQueryParams();
        $regenerate = !empty($q['regenerate']);
        $download   = !empty($q['download']);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;

        // Zachyť případné echo/warning z 3rd party libs během renderu.
        ob_start();
        try {
            $path = $this->renderer->render($id, $regenerate, $userId);
        } catch (\Throwable $e) {
            ob_end_clean();
            return Json::error($response, 'pdf_failed', $e->getMessage(), 500);
        }
        ob_end_clean();

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('pdf.generated', $user['id'] ?? null, 'invoice', $id, [
            'regenerate' => $regenerate,
            'path' => basename($path),
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = basename($path);
        $disposition = $download ? "attachment; filename=\"{$filename}\"" : "inline; filename=\"{$filename}\"";

        $stream = new Stream(fopen($path, 'rb'));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($stream);
    }
}

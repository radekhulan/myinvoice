<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\ExportPeriod;
use MyInvoice\Service\Export\ExportPeriodResolver;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Export\PohodaXmlExporter;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use ZipArchive;

/**
 * Generický export faktur za měsíc nebo čtvrtletí do různých formátů:
 *
 *   GET /api/admin/export?format=pdf-zip|isdoc|pohoda&month=YYYY-MM[&type=invoice][&date_by=issue|tax]
 *   GET /api/admin/export?format=pdf-zip|isdoc|pohoda&period=quarterly&year=YYYY&quarter=1..4
 *
 * Sdílený filter: period + type + date_by + supplier_id (z X-Supplier-Id middleware).
 * Per-format: výstup MIME a filename.
 *
 * Přístup: admin nebo accountant.
 */
final class ExportAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $repo,
        private readonly InvoicePdfRenderer $pdf,
        private readonly IsdocExporter $isdoc,
        private readonly PohodaXmlExporter $pohoda,
        private readonly ExportPeriodResolver $periodResolver,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        // readonly smí exportovat data (čtení), jen nesmí nic měnit
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        $q = $request->getQueryParams();
        $format = (string) ($q['format'] ?? 'pdf-zip');
        try {
            $period = $this->periodResolver->resolve($q);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $dateBy = (string) ($q['date_by'] ?? 'issue');
        $type   = (string) ($q['type'] ?? '');
        $sid    = SupplierGuard::currentId($request);

        // Najdi faktury za období + supplier scope.
        try {
            $ids = $this->findInvoiceIds($sid, $period, $dateBy, $type);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        if (empty($ids)) {
            return Json::error($response, 'no_invoices', "Za období {$period->label} nejsou žádné vystavené faktury.", 404);
        }

        try {
            $userId = isset($user['id']) ? (int) $user['id'] : null;
            [$filename, $content, $mime] = match ($format) {
                'pdf-zip' => $this->buildPdfZip($ids, $period, $type, $userId),
                'isdoc'   => $this->buildIsdoc($ids, $period),
                'pohoda'  => $this->buildPohoda($ids, $sid, $period),
                default   => throw new \InvalidArgumentException("Neznámý formát: $format"),
            };
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return Json::error($response, 'export_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoices.exported', $user['id'] ?? null, null, null, [
            'format' => $format,
            'period' => $period->label,
            'period_type' => $period->type,
            'month' => $period->month,
            'quarter' => $period->quarter,
            'date_from' => $period->dateFrom,
            'date_to_exclusive' => $period->dateToExclusive,
            'type' => $type ?: null,
            'count' => count($ids),
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Stream content out
        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($content));
    }

    /** @return int[] */
    private function findInvoiceIds(int $sid, ExportPeriod $period, string $dateBy, string $type): array
    {
        if (!in_array($dateBy, ['issue', 'tax'], true)) {
            throw new \InvalidArgumentException('Parametr date_by musí být issue nebo tax.');
        }
        $dateExpr = $dateBy === 'tax' ? 'COALESCE(tax_date, issue_date)' : 'issue_date';
        $params = [$sid, $period->dateFrom, $period->dateToExclusive];
        $typeFilter = '';
        if ($type !== '' && in_array($type, ['invoice', 'proforma', 'credit_note', 'cancellation'], true)) {
            $typeFilter = ' AND invoice_type = ?';
            $params[] = $type;
        }
        $sql = "SELECT id FROM invoices
                 WHERE supplier_id = ?
                   AND $dateExpr >= ?
                   AND $dateExpr < ?
                   AND status IN ('issued','sent','reminded','paid')
                   $typeFilter
              ORDER BY $dateExpr, id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param int[] $ids
     * @return array{0:string,1:string,2:string} [filename, content, mime]
     */
    private function buildPdfZip(array $ids, ExportPeriod $period, string $type, ?int $userId): array
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'inv-zip-') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Nelze vytvořit ZIP.');
        }
        foreach ($ids as $id) {
            try {
                $path = $this->pdf->render($id, false, $userId);
                if (!is_file($path)) continue;
                $inv = $this->repo->find($id);
                $typeLabel = match ($inv['invoice_type'] ?? 'invoice') {
                    'proforma'     => 'Proforma',
                    'credit_note'  => 'Dobropis',
                    'cancellation' => 'Storno',
                    default        => 'Faktura',
                };
                $vs = $inv['varsymbol'] ?? ('draft-' . $id);
                // Sanitize ZIP entry name — defense-in-depth proti zip-slip přes
                // importovaný varsymbol (security report @andrejtomci #3 DiD).
                $vs = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $vs);
                $zip->addFile($path, "$typeLabel-$vs.pdf");
            } catch (\Throwable) { /* skip failing ones */ }
        }
        $zip->close();

        $content = (string) file_get_contents($tmpZip);
        @unlink($tmpZip);
        $base = "myinvoice-{$period->label}" . ($type ? "-$type" : '');
        return ["$base.zip", $content, 'application/zip'];
    }

    /**
     * @param int[] $ids
     * @return array{0:string,1:string,2:string}
     */
    private function buildIsdoc(array $ids, ExportPeriod $period): array
    {
        $r = $this->isdoc->export($ids, $period->label);
        return [$r['filename'], $r['content'], $r['mime']];
    }

    /**
     * @param int[] $ids
     * @return array{0:string,1:string,2:string}
     */
    private function buildPohoda(array $ids, int $sid, ExportPeriod $period): array
    {
        $r = $this->pohoda->export($ids, $sid, $period->label);
        return [$r['filename'], $r['content'], $r['mime']];
    }
}

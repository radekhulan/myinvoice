<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use ZipArchive;

/**
 * GET /api/admin/invoices-zip?month=YYYY-MM[&type=invoice]
 *
 * Stáhne ZIP se všemi PDF fakturami za zadaný měsíc.
 *  - month je povinný (YYYY-MM)
 *  - type volitelný filter (invoice|proforma|credit_note)
 *  - default zahrnuje všechny vystavené (issued/sent/paid) typy invoice + credit_note
 *
 * Před archivací každé PDF zaregeneruje (pokud cache stale).
 */
final class InvoicesZipAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $repo,
        private readonly InvoicePdfRenderer $pdf,
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
        $month = (string) ($q['month'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return Json::error($response, 'validation_failed', 'Parametr month musí být YYYY-MM.', 400);
        }
        // date_by: 'issue' = podle issue_date (data vystavení) | 'tax' = podle tax_date (DUZP, fallback issue)
        $dateBy = (string) ($q['date_by'] ?? 'issue');
        $dateExpr = $dateBy === 'tax'
            ? "COALESCE(tax_date, issue_date)"   // proformy nemají DUZP → fallback na vystaveno
            : "issue_date";
        $sid = SupplierGuard::currentId($request);
        $type = (string) ($q['type'] ?? '');
        $typeFilter = '';
        $params = [$sid, $month];
        if ($type !== '' && in_array($type, ['invoice', 'proforma', 'credit_note', 'cancellation'], true)) {
            $typeFilter = ' AND invoice_type = ?';
            $params[] = $type;
        }

        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.issue_date, i.tax_date, i.total_with_vat,
                       cur.code AS currency
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND DATE_FORMAT(i.$dateExpr, '%Y-%m') = ?
                   AND i.status IN ('issued','sent','reminded','paid')
                   " . ($typeFilter !== '' ? ' AND i.invoice_type = ?' : '') . "
              ORDER BY i.$dateExpr, i.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($invoices)) {
            return Json::error($response, 'no_invoices', "Za měsíc $month nejsou žádné vystavené faktury.", 404);
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'inv-zip-') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return Json::error($response, 'zip_failed', 'Nelze vytvořit ZIP.', 500);
        }

        $errors = 0;
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        foreach ($invoices as $inv) {
            try {
                $path = $this->pdf->render((int) $inv['id'], false, $userId);
                if (!is_file($path)) {
                    $errors++;
                    continue;
                }
                $type = match ($inv['invoice_type']) {
                    'proforma'     => 'Proforma',
                    'credit_note'  => 'Dobropis',
                    'cancellation' => 'Storno',
                    default        => 'Faktura',
                };
                // Sanitize ZIP entry name (zip-slip DiD, security report @andrejtomci #3)
                $vs = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $inv['varsymbol']);
                $entryName = "$type-$vs.pdf";
                $zip->addFile($path, $entryName);
            } catch (\Throwable $e) {
                $errors++;
            }
        }
        $zip->close();

        $count = count($invoices) - $errors;
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoices.zip_exported', $user['id'] ?? null, null, null, [
            'month' => $month, 'type' => $type ?: null, 'date_by' => $dateBy,
            'count' => $count, 'errors' => $errors,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $size = filesize($tmpZip);
        $filename = "myinvoice-$month" . ($type ? "-$type" : '') . ".zip";

        $fp = fopen($tmpZip, 'rb');
        $stream = new Stream($fp);

        // Cleanup hook — when stream is consumed, remove temp file. Slim closes it after response.
        register_shutdown_function(static function () use ($tmpZip): void {
            if (is_file($tmpZip)) @unlink($tmpZip);
        });

        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) $size)
            ->withBody($stream);
    }
}

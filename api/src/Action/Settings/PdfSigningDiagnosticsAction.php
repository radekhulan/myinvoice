<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/settings/pdf-signing/diagnostics
 *
 * Bezpečná diagnostika nativního PDF signing backendu pro aktuální supplier.
 */
final class PdfSigningDiagnosticsAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly PdfSigningService $signing,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }

        $stmt = $this->db->pdo()->prepare('SELECT * FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($supplier === false) {
            return Json::error($response, 'not_found', 'Dodavatel nenalezen.', 404);
        }

        return Json::ok($response, $this->signing->diagnosticsForSupplier($supplier));
    }

    private function isAdmin(Request $request): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['role']) && $user['role'] === 'admin';
    }
}

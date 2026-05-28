<?php

declare(strict_types=1);

namespace MyInvoice\Action\Dashboard;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\TaxThresholdService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/dashboard/tax-thresholds
 *
 * Vrátí aktuální stav obratových prahů pro daňový režim aktuálního dodavatele:
 *   - DPH plátcovství: rolling 12M + kalendářní rok obrat z vystavených faktur
 *   - Paušální daň (pokud má supplier flat_tax_band != none): YTD obrat
 *     ze zaplacených faktur, limit dle pásma, měsíční záloha pro info
 *
 * Volá widget na Přehled (Dashboard) i Finance → CRM, takže se vykresluje
 * konzistentní progress bar / barva (ok/notice/warning/danger) na obou místech.
 */
final class TaxThresholdsAction
{
    public function __construct(private readonly TaxThresholdService $service) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Není zvolen dodavatel.', 400);
        }
        return Json::ok($response, $this->service->compute($supplierId));
    }
}

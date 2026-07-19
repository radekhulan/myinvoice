<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/invoices/{id}/payments — evidované platby faktury, přímo spárované
 * bankovní operace a souhrn (paid_total, zbývá k úhradě, payment_status).
 */
final class ListPaymentsAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoicePaymentService $payments,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        return Json::ok($response, [
            'payments'          => $this->payments->listFor($id),
            'bank_transactions' => $this->payments->listRelatedBankTransactions($id),
            'paid_total'        => (float) ($invoice['paid_total'] ?? 0),
            'amount_to_pay'     => (float) ($invoice['amount_to_pay'] ?? 0),
            'remaining'         => round((float) ($invoice['amount_to_pay'] ?? 0) - (float) ($invoice['paid_total'] ?? 0), 2),
            'payment_status' => InvoicePaymentService::paymentStatus($invoice),
        ]);
    }
}

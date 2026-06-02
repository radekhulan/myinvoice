<?php

declare(strict_types=1);

namespace MyInvoice\Action\Approval;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\ApprovalEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\WorkReportPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/request-approval-test
 *
 * Test odeslání schvalovacího emailu — na email aktuálního supplier
 * (fallback cfg.smtp.from_email). Nemění invoice.approval_status, negeneruje
 * skutečný token (v URL je 'TEST-NO-TOKEN' placeholder, link funguje jako náhled).
 *
 * Funguje i pokud projekt aktuálně nevyžaduje schválení — admin si chce udělat náhled.
 * Nicméně faktura musí mít linked work_report (jinak není co schvalovat).
 */
final class RequestApprovalTestAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly Config $config,
        private readonly WorkReportPdfRenderer $renderer,
        private readonly Mailer $mailer,
        private readonly ApprovalEmailVarsBuilder $varsBuilder,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        // Test recipient = supplier.email (fallback cfg.smtp.from_email)
        $stmt = $this->db->pdo()->prepare('SELECT email FROM supplier WHERE id = ?');
        $stmt->execute([(int) $invoice['supplier_id']]);
        $testRecipient = trim((string) $stmt->fetchColumn());
        if ($testRecipient === '' || !filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
            $testRecipient = (string) $this->config->get('smtp.from_email', '');
        }
        if ($testRecipient === '') {
            return Json::error($response, 'no_test_recipient', 'Supplier nemá email a cfg.smtp.from_email není nastaveno.', 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;

        try {
            $pdfPath = $this->renderer->render($id, $userId);
        } catch (\Throwable $e) {
            return Json::error($response, 'pdf_failed', 'Nepodařilo se vygenerovat PDF výkazu: ' . $e->getMessage(), 500);
        }

        $locale = (string) ($invoice['language'] ?? 'cs');
        // Test mode → dummy token; URL bude vidět ale neotevře schvalovací stránku reálné faktury
        $vars = $this->varsBuilder->build($invoice, 'TEST-NO-TOKEN', true, $locale);

        try {
            $this->mailer->sendTemplate(
                'invoice_approval',
                $locale,
                [$testRecipient],
                $vars,
                null,
                [],
                [],
                [['path' => $pdfPath, 'name' => basename($pdfPath), 'contentType' => 'application/pdf']],
                $userId,
            );
        } catch (\Throwable $e) {
            return Json::error($response, 'send_failed', 'Email se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.approval_request_test', $user['id'] ?? null, 'invoice', $id, [
            'to' => $testRecipient,
            'pdf_path' => basename($pdfPath),
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'sent_to' => [$testRecipient],
            'sent_at' => date('Y-m-d H:i:s'),
            'is_test' => true,
        ]);
    }
}

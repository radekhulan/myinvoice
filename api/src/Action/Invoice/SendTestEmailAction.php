<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Test odeslání faktury — na email aktuálního supplier (fallback cfg.smtp.from_email).
 *
 * Funguje i pro draft (varsymbol může být null).
 * Neměnu invoice.status ani invoice.sent_at.
 * Activity log: email.sent_test
 */
final class SendTestEmailAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoicePdfRenderer $renderer,
        private readonly Mailer $mailer,
        private readonly InvoiceEmailVarsBuilder $varsBuilder,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
        private readonly InvoiceAttachmentRepository $attachments,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($invoice['invoice_type'] === 'cancellation') {
            return Json::error($response, 'cannot_send_cancellation', 'Interní storno se klientovi neposílá.', 409);
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
            $pdfPath = $this->renderer->render($id, false, $userId);
        } catch (\Throwable $e) {
            return Json::error($response, 'pdf_failed', 'Nepodařilo se vygenerovat PDF: ' . $e->getMessage(), 500);
        }

        $locale = (string) ($invoice['language'] ?? 'cs');
        $vars = $this->varsBuilder->build($invoice, true, $locale);

        // Test send — přibalíme i uživatelské přílohy, ať uživatel vidí, co reálně odejde.
        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        $emailAttachments = [
            ['path' => $pdfPath, 'name' => basename($pdfPath), 'contentType' => 'application/pdf'],
        ];
        foreach ($this->attachments->listForInvoice($id) as $att) {
            $path = $this->attachments->pathFor($supplierId, $id, (string) $att['filename']);
            if (!is_file($path)) continue;
            $emailAttachments[] = [
                'path'        => $path,
                'name'        => (string) $att['original_name'],
                'contentType' => (string) $att['mime_type'],
            ];
        }

        $smtpResponse = '';
        try {
            $smtpResponse = $this->mailer->sendTemplate(
                'invoice_send',
                $locale,
                [$testRecipient],
                $vars,
                null,
                [],
                [],
                $emailAttachments,
                $userId,
            );
        } catch (\Throwable $e) {
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('email.test_failed', $user['id'] ?? null, 'invoice', $id, [
                'to' => $testRecipient,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ], $ip, $request->getHeaderLine('User-Agent'));
            return Json::error($response, 'send_failed', 'Email se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('email.sent_test', $user['id'] ?? null, 'invoice', $id, [
            'to'            => $testRecipient,
            'pdf_path'      => basename($pdfPath),
            'smtp_response' => $smtpResponse,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'sent_to' => [$testRecipient],
            'sent_at' => date('Y-m-d H:i:s'),
            'is_test' => true,
        ]);
    }
}

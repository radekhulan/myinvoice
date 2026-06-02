<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Test odeslání upomínky — na email aktuálního supplier (fallback cfg.smtp.from_email).
 *
 * Funguje i pro draft (preview šablony před vystavením).
 * Neměnu invoice.status, last_reminder_at ani reminder_count.
 * Když faktura není po splatnosti, použije se preview hodnota 7 dní.
 * Activity log: email.sent_test_reminder
 */
final class SendTestReminderAction
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
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (!in_array($invoice['invoice_type'], ['invoice', 'proforma'], true)) {
            return Json::error($response, 'invalid_type', 'Upomínat lze jen běžnou fakturu nebo proformu.', 409);
        }
        if (!InvoiceAmountPolicy::hasPositiveAmountToPay($invoice)) {
            return Json::error($response, 'invalid_amount', InvoiceAmountPolicy::NON_POSITIVE_REMINDER_MESSAGE, 409);
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

        // Days overdue — když není po splatnosti, fallback 7 dní pro preview
        $daysOverdue = 7;
        if (!empty($invoice['due_date'])) {
            $today = new \DateTimeImmutable('today');
            $due   = new \DateTimeImmutable((string) $invoice['due_date']);
            if ($due < $today) {
                $daysOverdue = (int) $today->diff($due)->days;
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;

        try {
            $pdfPath = $this->renderer->render($id, false, $userId);
        } catch (\Throwable $e) {
            return Json::error($response, 'pdf_failed', 'Nepodařilo se vygenerovat PDF: ' . $e->getMessage(), 500);
        }

        $locale = (string) ($invoice['language'] ?? 'cs');
        $vars = $this->varsBuilder->buildReminder($invoice, $daysOverdue, $locale);
        // Označ jako TEST v subjectu (na rozdíl od reálné upomínky)
        $vars['subject'] = ($locale === 'en' ? '[TEST] ' : '[TEST] ') . $vars['subject'];

        $smtpResponse = '';
        try {
            $templateCode = $invoice['invoice_type'] === 'proforma' ? 'proforma_reminder' : 'invoice_reminder';
            $smtpResponse = $this->mailer->sendTemplate(
                $templateCode,
                $locale,
                [$testRecipient],
                $vars,
                null,
                [],
                [],
                [[
                    'path' => $pdfPath,
                    'name' => basename($pdfPath),
                    'contentType' => 'application/pdf',
                ]],
                $userId,
            );
        } catch (\Throwable $e) {
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('email.test_reminder_failed', $user['id'] ?? null, 'invoice', $id, [
                'to' => $testRecipient,
                'days_overdue' => $daysOverdue,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ], $ip, $request->getHeaderLine('User-Agent'));
            return Json::error($response, 'send_failed', 'Email se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('email.sent_test_reminder', $user['id'] ?? null, 'invoice', $id, [
            'to'            => $testRecipient,
            'days_overdue'  => $daysOverdue,
            'pdf_path'      => basename($pdfPath),
            'smtp_response' => $smtpResponse,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'sent_to'      => [$testRecipient],
            'sent_at'      => date('Y-m-d H:i:s'),
            'days_overdue' => $daysOverdue,
            'is_test'      => true,
        ]);
    }
}

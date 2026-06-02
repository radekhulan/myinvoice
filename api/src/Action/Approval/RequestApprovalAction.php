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
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\ApprovalEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\PdfArchiveService;
use MyInvoice\Service\Pdf\WorkReportPdfRenderer;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/request-approval
 *
 * Pre-conditions:
 *  - faktura existuje a patří aktuálnímu supplier
 *  - faktura je draft
 *  - faktura má linked work_report
 *  - projekt vyžaduje requires_work_report_approval
 *
 * Effects:
 *  - alokuje varsymbol a zafixuje supplier/client/bank snapshoty (status zůstává 'draft')
 *  - vygeneruje approval_token, approval_status='requested'
 *  - pošle email invoice_approval na project_billing_emails (fallback client_main_email)
 *  - jako příloha jen PDF výkazu (Vykaz-XYZ.pdf), ne celá faktura
 *  - audit: invoice.approval_requested
 */
final class RequestApprovalAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly WorkReportPdfRenderer $renderer,
        private readonly Mailer $mailer,
        private readonly ApprovalEmailVarsBuilder $varsBuilder,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Config $config,
        private readonly AutoIssueAndSendService $autoIssue,
        private readonly PdfArchiveService $pdfArchive,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($invoice['status'] !== 'draft') {
            return Json::error($response, 'invalid_state', 'Ke schválení lze poslat jen draft fakturu.', 409);
        }
        if (empty($invoice['project_id']) || !($invoice['project_requires_approval'] ?? false)) {
            return Json::error($response, 'not_required', 'Zakázka nevyžaduje schválení výkazu.', 409);
        }
        if (
            InvoiceAmountPolicy::requiresPositiveDraftAmountToPay(
                (string) ($invoice['invoice_type'] ?? 'invoice'),
                $invoice['parent_invoice_id'] ?? null,
            )
            && !InvoiceAmountPolicy::hasPositiveAmountToPay($invoice)
        ) {
            return Json::error($response, 'invalid_amount', InvoiceAmountPolicy::NON_POSITIVE_DRAFT_MESSAGE, 409);
        }

        // Příjemci: project_billing_emails (fallback client_main_email)
        $to = $this->resolveApprovalRecipients($invoice);
        if (empty($to)) {
            return Json::error($response, 'no_recipients', 'Zakázka nemá fakturační email a klient nemá hlavní email.', 400);
        }

        // Alokuj varsymbol + snapshoty PŘED renderem PDF, aby Vykaz-XYZ.pdf
        // obsahoval reálné číslo (ne "draft-NN") a aby snapshoty odpovídaly stavu
        // v okamžiku, kdy klient výkaz schvaluje.
        try {
            $invoice = $this->autoIssue->allocateVarsymbolAndSnapshots($id);
        } catch (\Throwable $e) {
            return Json::error($response, 'allocation_failed', 'Nepodařilo se alokovat číslo faktury: ' . $e->getMessage(), 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;

        // Render PDF výkazu (Vykaz-XYZ.pdf)
        try {
            $pdfPath = $this->renderer->render($id, $userId);
        } catch (\Throwable $e) {
            return Json::error($response, 'pdf_failed', 'Nepodařilo se vygenerovat PDF výkazu: ' . $e->getMessage(), 500);
        }

        // Vygeneruj nový token (přepíše dřívější requested/rejected). TTL z config.
        $ttlDays = (int) $this->config->get('approval.token_ttl_days', 30);
        $token = $this->repo->setApprovalRequested($id, $ttlDays);
        $invoice = $this->repo->find($id);

        $locale = (string) ($invoice['language'] ?? 'cs');
        $vars = $this->varsBuilder->build($invoice, $token, false, $locale);

        // BCC dodavateli pro audit — sdílený flag s upomínkou schválení (cron-send-approval-reminders).
        $bcc = [];
        if ((bool) $this->config->get('approval.cc_supplier_on_approval', true)) {
            $st = $this->db->pdo()->prepare('SELECT email FROM supplier WHERE id = ?');
            $st->execute([(int) $invoice['supplier_id']]);
            $supEmail = trim((string) $st->fetchColumn());
            if ($supEmail !== '' && filter_var($supEmail, FILTER_VALIDATE_EMAIL) && !in_array($supEmail, $to, true)) {
                $bcc[] = $supEmail;
            }
        }

        try {
            $this->mailer->sendTemplate(
                'invoice_approval',
                $locale,
                $to,
                $vars,
                null,
                [],
                $bcc,
                [['path' => $pdfPath, 'name' => basename($pdfPath), 'contentType' => 'application/pdf']],
                $userId,
            );
        } catch (\Throwable $e) {
            return Json::error($response, 'send_failed', 'Email se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        // Archivuj odeslaný výkaz do PDF historie faktury — důkaz toho, co klient dostal
        // ke schválení. wasSent=true + sent_to → v UI se zobrazí "Odesláno klientovi".
        $sentToAll = array_values(array_unique(array_merge($to, $bcc)));
        $archiveId = $this->pdfArchive->archiveCopy(
            $id,
            $pdfPath,
            'approval_request',
            wasSent: true,
            sentTo: $sentToAll,
        );

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.approval_requested', $user['id'] ?? null, 'invoice', $id, [
            'to' => $to,
            'pdf_path' => basename($pdfPath),
            'pdf_archive_id' => $archiveId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'sent_to' => $to,
            'sent_at' => date('Y-m-d H:i:s'),
            'invoice' => $this->repo->find($id),
        ]);
    }

    /**
     * Schvalovací email jde:
     *  - na project_billing_emails (pokud existují) — primárně
     *  - jinak na client_main_email
     * (NIKDY nesměšujeme — když jsou project emails, klient se nemusí dozvědět)
     */
    private function resolveApprovalRecipients(array $invoice): array
    {
        $emails = [];
        if (!empty($invoice['project_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT email FROM project_billing_emails WHERE project_id = ? ORDER BY position'
            );
            $stmt->execute([$invoice['project_id']]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $em) {
                $em = trim((string) $em);
                if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $em;
                }
            }
        }
        if (empty($emails) && !empty($invoice['client_main_email'])) {
            $main = trim((string) $invoice['client_main_email']);
            if (filter_var($main, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $main;
            }
        }
        return $emails;
    }
}

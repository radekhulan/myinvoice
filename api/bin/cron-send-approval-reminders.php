<?php

declare(strict_types=1);

/**
 * Cron — pošle upomínky zákazníkům, kteří neschválili výkaz víceprací.
 *
 * Použití:
 *   php api/bin/cron-send-approval-reminders.php             # default z cfg.approval.*
 *   php api/bin/cron-send-approval-reminders.php --days=7    # override reminder_after_days
 *   php api/bin/cron-send-approval-reminders.php --dry-run
 *
 * Logika:
 *   - faktura má approval_status='requested'
 *   - poslední aktivita (reminder_at, jinak requested_at) starší než N dní (cfg.approval.reminder_after_days, default 5)
 *   - reminder_count < cfg.approval.max_reminders (default 3)
 *   - token nesmí být expired (token_expires_at > NOW)
 *
 * Pošle stejnou šablonu invoice_approval s flagem is_reminder=true (jiný subject + úvodní nadpis)
 * jako příloha jen PDF výkazu (samostatný WorkReportPdfRenderer). Recipients = stejní
 * jako u původní žádosti (project_billing_emails fallback client_main_email).
 *
 * Volitelné BCC dodavateli (cfg.approval.cc_supplier_on_approval_reminder=true) pro audit.
 *
 * Audit log: invoice.approval_reminder_sent
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Mail\ApprovalEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\PdfArchiveService;
use MyInvoice\Service\Pdf\WorkReportPdfRenderer;

// Parse args
$daysOverride = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) { $daysOverride = (int) $m[1]; continue; }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(1);
}

$app = Bootstrap::buildApp();
$container = $app->getContainer();
if ($container === null) {
    fwrite(STDERR, "Container not available.\n");
    exit(1);
}

/** @var Config $config */
$config = $container->get(Config::class);
/** @var Connection $conn */
$conn = $container->get(Connection::class);
/** @var InvoiceRepository $repo */
$repo = $container->get(InvoiceRepository::class);
/** @var WorkReportPdfRenderer $renderer */
$renderer = $container->get(WorkReportPdfRenderer::class);
/** @var Mailer $mailer */
$mailer = $container->get(Mailer::class);
/** @var ApprovalEmailVarsBuilder $varsBuilder */
$varsBuilder = $container->get(ApprovalEmailVarsBuilder::class);
/** @var ActivityLogger $logger */
$logger = $container->get(ActivityLogger::class);
/** @var PdfArchiveService $pdfArchive */
$pdfArchive = $container->get(PdfArchiveService::class);

$run = CronRun::start($conn->pdo(), 'cron-send-approval-reminders');

$days = $daysOverride ?? (int) $config->get('approval.reminder_after_days', 5);
$maxReminders = (int) $config->get('approval.max_reminders', 3);
$ccSupplier = (bool) $config->get('approval.cc_supplier_on_approval_reminder', true);
$days = max(1, $days);

$startedAt = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] cron-send-approval-reminders --days={$days} --max={$maxReminders}"
    . ($dryRun ? ' --dry-run' : '') . "\n";

$candidates = $repo->listForApprovalInbox(
    supplierId: null,                   // všichni dodavatelé
    statusFilter: 'requested',
    minDaysSince: $days,
    maxReminders: $maxReminders,
);

// Filter expired tokens — neposílat upomínku na link, který už nefunguje
$candidates = array_filter($candidates, static function (array $inv): bool {
    if (empty($inv['approval_token'])) return false;
    if (empty($inv['approval_token_expires_at'])) return true;
    return strtotime((string) $inv['approval_token_expires_at']) > time();
});

echo "  found " . count($candidates) . " candidates\n";

$report = ['days' => $days, 'max' => $maxReminders, 'dry_run' => $dryRun, 'candidates' => count($candidates), 'sent' => 0, 'errors' => 0];

if (empty($candidates)) {
    $ms = (int) ((microtime(true) - $startedAt) * 1000);
    echo "  (nothing to do, {$ms} ms)\n";
    $logger->log('cron.approval_reminders', null, null, null, $report);
    $run->finish('ok', $report);
    exit(0);
}

$pdo = $conn->pdo();

foreach ($candidates as $inv) {
    $invId = (int) $inv['id'];
    $vs = $inv['varsymbol'] ?: ('draft-' . $invId);

    if ($dryRun) {
        printf(
            "  [DRY] #%d %s — %s — requested %s, reminders %d\n",
            $invId, $vs, (string) ($inv['client_company_name'] ?? '?'),
            (string) $inv['approval_requested_at'],
            (int) $inv['approval_reminder_count'],
        );
        continue;
    }

    // Recipients: stejná logika jako RequestApprovalAction — project_billing_emails fallback main_email
    $to = [];
    if (!empty($inv['project_id'])) {
        $st = $pdo->prepare('SELECT email FROM project_billing_emails WHERE project_id = ? ORDER BY position');
        $st->execute([$inv['project_id']]);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $em) {
            $em = trim((string) $em);
            if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $to[] = $em;
        }
    }
    if (empty($to) && !empty($inv['client_main_email'])) {
        $main = trim((string) $inv['client_main_email']);
        if (filter_var($main, FILTER_VALIDATE_EMAIL)) $to[] = $main;
    }
    if (empty($to)) {
        $report['errors']++;
        fprintf(STDERR, "  ✗ #%d %s — no recipients\n", $invId, $vs);
        continue;
    }

    // BCC dodavateli pro audit
    $bcc = [];
    if ($ccSupplier) {
        $st = $pdo->prepare('SELECT email FROM supplier WHERE id = ?');
        $st->execute([(int) $inv['supplier_id']]);
        $supEmail = trim((string) $st->fetchColumn());
        if ($supEmail !== '' && filter_var($supEmail, FILTER_VALIDATE_EMAIL) && !in_array($supEmail, $to, true)) {
            $bcc[] = $supEmail;
        }
    }

    try {
        $pdfPath = $renderer->render($invId);
        $locale = (string) ($inv['language'] ?? 'cs');
        $vars = $varsBuilder->build($inv, (string) $inv['approval_token'], false, $locale, isReminder: true);

        $mailer->sendTemplate(
            'invoice_approval',
            $locale,
            $to,
            $vars,
            null,
            [],
            $bcc,
            [['path' => $pdfPath, 'name' => basename($pdfPath), 'contentType' => 'application/pdf']],
        );

        // Archivuj odeslaný výkaz — viz RequestApprovalAction.
        $sentToAll = array_values(array_unique(array_merge($to, $bcc)));
        $archiveId = $pdfArchive->archiveCopy($invId, $pdfPath, 'approval_reminder', wasSent: true, sentTo: $sentToAll);

        $repo->markApprovalReminderSent($invId);
        $logger->log('invoice.approval_reminder_sent', null, 'invoice', $invId, [
            'to' => $to, 'bcc' => $bcc,
            'reminder_n' => ((int) $inv['approval_reminder_count']) + 1,
            'pdf_archive_id' => $archiveId,
        ]);

        $report['sent']++;
        printf("  ✓ #%d %s → %s (reminder #%d)\n",
            $invId, $vs, implode(', ', $to), ((int) $inv['approval_reminder_count']) + 1);
    } catch (\Throwable $e) {
        $report['errors']++;
        $logger->log('invoice.approval_reminder_failed', null, 'invoice', $invId, [
            'to' => $to, 'bcc' => $bcc,
            'reminder_n' => ((int) $inv['approval_reminder_count']) + 1,
            'error' => mb_substr($e->getMessage(), 0, 500),
        ]);
        fprintf(STDERR, "  ✗ #%d %s — %s\n", $invId, $vs, $e->getMessage());
    }
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "  done ({$ms} ms): sent={$report['sent']}, errors={$report['errors']}\n";

$logger->log('cron.approval_reminders', null, null, null, $report);

$run->finish($report['errors'] > 0 ? 'error' : 'ok', $report);

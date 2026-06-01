<?php

declare(strict_types=1);

/**
 * Cron — odešle automatické upomínky na faktury po splatnosti.
 *
 * Použití:
 *   php api/bin/cron-send-reminders.php                  # default --days=3 --cooldown=7
 *   php api/bin/cron-send-reminders.php --days=5
 *   php api/bin/cron-send-reminders.php --days=3 --cooldown=14
 *   php api/bin/cron-send-reminders.php --dry-run
 *
 * Filtry:
 *   --days=N      faktura musí být víc než N dní po splatnosti (default 3)
 *   --cooldown=N  od poslední upomínky musí uplynout aspoň N dní (default 7)
 *                 (NULL = nikdy upomenuto → vždy projde)
 *   --dry-run     jen vypíše, co by se odeslalo, nic nedělá
 *
 * Vybrané faktury: status IN ('issued','sent','reminded'),
 *                  invoice_type IN ('invoice','proforma'),
 *                  due_date < CURDATE() - INTERVAL N DAY,
 *                  (last_reminder_at IS NULL OR last_reminder_at < NOW() - INTERVAL cooldown DAY)
 *
 * Pro proformu se použije šablona `proforma_reminder` (jiný tón — "zaplaťte zálohu,
 * obratem zašleme finální fakturu"), pro běžnou fakturu `invoice_reminder`.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Invoice\ReminderService;

// Parse args
$daysOverride = null;  // null = per-dodavatel práh (supplier.reminder_days_after_due); --days ho přebije
$cooldown = 7;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')                     { $dryRun = true; continue; }
    if (preg_match('/^--days=(\d+)$/', $arg, $m))      { $daysOverride = max(1, (int) $m[1]); continue; }
    if (preg_match('/^--cooldown=(\d+)$/', $arg, $m))  { $cooldown = (int) $m[1]; continue; }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(1);
}

$cooldown = max(0, $cooldown);

$app = Bootstrap::buildApp();
$container = $app->getContainer();
if ($container === null) {
    fwrite(STDERR, "Container not available.\n");
    exit(1);
}

/** @var \MyInvoice\Infrastructure\Database\Connection $conn */
$conn = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);
$pdo = $conn->pdo();

$run = CronRun::start($pdo, 'cron-send-reminders');
$startedAt = microtime(true);

$sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.due_date, i.amount_to_pay, cur.code AS currency,
               c.company_name AS client_name, c.main_email,
               DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
               i.last_reminder_at, i.reminder_count
          FROM invoices i
          JOIN clients c ON c.id = i.client_id
          JOIN currencies cur ON cur.id = i.currency_id
          JOIN supplier s ON s.id = i.supplier_id
         WHERE i.status IN ('issued','sent','reminded')
           AND i.invoice_type IN ('invoice','proforma')
           AND i.amount_to_pay > 0
           AND i.payment_method = 'bank_transfer'
           AND s.auto_send_reminders = 1
           AND c.auto_send_reminders = 1
           AND i.auto_send_reminders = 1
           AND i.due_date < (CURDATE() - INTERVAL COALESCE(?, s.reminder_days_after_due) DAY)
           AND (i.last_reminder_at IS NULL OR i.last_reminder_at < (NOW() - INTERVAL ? DAY))
         ORDER BY i.due_date ASC, i.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$daysOverride, $cooldown]);
$candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$daysLabel = $daysOverride === null ? 'per-supplier' : (string) $daysOverride;
echo "[" . date('Y-m-d H:i:s') . "] cron-send-reminders --days={$daysLabel} --cooldown={$cooldown}"
    . ($dryRun ? ' --dry-run' : '') . " — found " . count($candidates) . " candidates\n";

$report = ['days' => $daysOverride, 'cooldown' => $cooldown, 'dry_run' => $dryRun, 'candidates' => count($candidates), 'sent' => 0, 'errors' => 0];

if (empty($candidates)) {
    $ms = (int) ((microtime(true) - $startedAt) * 1000);
    echo "  (nothing to do, {$ms} ms)\n";
    $pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.send_reminders', ?)")
        ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);
    $run->finish('ok', $report);
    exit(0);
}

if ($dryRun) {
    foreach ($candidates as $c) {
        printf(
            "  [DRY] #%d [%s] %s — %s — %d days overdue, %s %s, last reminder: %s, count: %d\n",
            (int) $c['id'],
            (string) $c['invoice_type'],
            (string) ($c['varsymbol'] ?? '(draft)'),
            (string) $c['client_name'],
            (int) $c['days_overdue'],
            number_format((float) $c['amount_to_pay'], 2, ',', ' '),
            (string) $c['currency'],
            $c['last_reminder_at'] ?? 'never',
            (int) $c['reminder_count'],
        );
    }
    $ms = (int) ((microtime(true) - $startedAt) * 1000);
    echo "  ({$ms} ms — DRY RUN, no emails sent)\n";
    $run->finish('ok', $report);
    exit(0);
}

/** @var ReminderService $reminders */
$reminders = $container->get(ReminderService::class);

foreach ($candidates as $c) {
    $invId = (int) $c['id'];
    try {
        $r = $reminders->send($invId, null, null, 'cron-send-reminders/1.0');
        $report['sent']++;
        printf(
            "  ✓ #%d %s → %s (%d days overdue)\n",
            $invId,
            (string) ($c['varsymbol'] ?? "draft#{$invId}"),
            implode(', ', $r['sent_to']),
            $r['days_overdue'],
        );
    } catch (\Throwable $e) {
        $report['errors']++;
        fprintf(STDERR, "  ✗ #%d %s — %s\n", $invId, (string) ($c['varsymbol'] ?? "draft#{$invId}"), $e->getMessage());
    }
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "  done ({$ms} ms): sent={$report['sent']}, errors={$report['errors']}\n";

$pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.send_reminders', ?)")
    ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);

$run->finish($report['errors'] > 0 ? 'error' : 'ok', $report);

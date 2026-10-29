<?php

declare(strict_types=1);

/**
 * Auto-scan bankovnich emailovych aviz pres IMAP.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeScanner;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);
$run     = CronRun::start($conn->pdo(), 'cron-bank-email-notices');

$container = Bootstrap::buildApp()->getContainer();
$scanner = $container->get(BankEmailNoticeScanner::class);

// Cron obsluhuje zapnuté schránky celé instalace. Výchozí dodavatel z konfigurace
// rozsahem úlohy není: schránka nastavená u jiného dodavatele se jinak skenovala
// jen ručně (#291). Opt-inem je `enabled` u samotné schránky.
$supplierIds = array_map('intval', $conn->pdo()->query('
    SELECT DISTINCT supplier_id FROM bank_email_imap_settings
     WHERE enabled = 1
     ORDER BY supplier_id
')->fetchAll(\PDO::FETCH_COLUMN));

$started = microtime(true);
$summary = [
    'suppliers' => 0,
    'processed' => 0,
    'matched' => 0,
    'known_skipped' => 0,
    'old_skipped' => 0,
    'errors' => 0,
    'details' => [],
];

foreach ($supplierIds as $sid) {
    if ($sid <= 0) continue;
    fwrite(STDOUT, '[' . date('H:i:s') . "] supplier {$sid} — bank email notices scan\n");
    try {
        $result = $scanner->scanSupplier($sid);
        $summary['suppliers']++;
        foreach (['processed', 'matched', 'known_skipped', 'old_skipped', 'errors'] as $key) {
            $summary[$key] += (int) ($result[$key] ?? 0);
        }
        if (!empty($result['error'])) {
            $summary['details'][] = ['supplier_id' => $sid, 'error' => $result['error']];
        }
    } catch (\Throwable $e) {
        $summary['errors']++;
        $summary['details'][] = ['supplier_id' => $sid, 'error' => $e->getMessage()];
        fwrite(STDERR, "[bank-email-notices] supplier {$sid}: " . $e->getMessage() . "\n");
    }
}

$summary['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
echo '[' . date('Y-m-d H:i:s') . '] bank-email-notices: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";

$conn->pdo()->prepare(
    "INSERT INTO activity_log (action, payload) VALUES ('cron.bank_email_notices', ?)"
)->execute([json_encode($summary, JSON_UNESCAPED_UNICODE)]);

$run->finish($summary['errors'] > 0 ? 'error' : 'ok', $summary, $summary['errors'] > 0 ? 'Některé skeny selhaly.' : null);

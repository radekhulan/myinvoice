<?php

declare(strict_types=1);

/**
 * Fix statusu importovaných faktur z Fakturoidu.
 *
 * FakturoidImportService::createIssued() volá InvoiceRepository::createDraft(),
 * takže každá importovaná faktura skončí ve stavu `draft` bez ohledu na to,
 * jestli ve Fakturoidu byla zaplacená/odeslaná/stornovaná.
 *
 * Tento skript projde všechny invoices kde `fakturoid_id IS NOT NULL`, pro
 * každou stáhne aktuální stav z Fakturoid API a doplní:
 *
 *   Fakturoid status  → MyInvoice status        + timestamp
 *   ─────────────────────────────────────────────────────────────────
 *   open              → issued
 *   sent              → sent                    + sent_at
 *   overdue           → sent                    + sent_at
 *   paid              → paid                    + paid_at = paid_on
 *   cancelled         → cancelled               + cancelled_at = updated_at
 *   uncollectible     → cancelled               + cancelled_at = updated_at
 *
 * Updatuje jen faktury, které jsou aktuálně `draft` (aby se nepřepsaly stavy
 * měněné v MyInvoice po importu). Pro ne-draft je vidět v dry-run logu.
 *
 * Použití:
 *   php api/bin/fix-fakturoid-imported-statuses.php --supplier-id=1            # dry-run
 *   php api/bin/fix-fakturoid-imported-statuses.php --supplier-id=1 --apply    # zápis
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\FakturoidClient;
use Psr\Log\NullLogger;

$apply = in_array('--apply', $argv, true);
$supplierId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--supplier-id=(\d+)$/', $arg, $m)) $supplierId = (int) $m[1];
}
if ($supplierId === null) {
    fwrite(STDERR, "Chybí --supplier-id=N\n");
    exit(2);
}

$config = Config::load(Bootstrap::rootDir());
$conn   = new Connection($config);
$crypto = new SecretEncryption($config);
$client = new FakturoidClient($conn, $crypto, new NullLogger());
$pdo    = $conn->pdo();

// Načti všechny faktury, které máme z Fakturoidu, indexované podle fakturoid_id
$stmt = $pdo->prepare(
    "SELECT id, fakturoid_id, varsymbol, status, issue_date
       FROM invoices
      WHERE supplier_id = ? AND fakturoid_id IS NOT NULL"
);
$stmt->execute([$supplierId]);
$ours = [];
foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
    $ours[(int) $row['fakturoid_id']] = $row;
}
if (!$ours) {
    echo "Žádné faktury s fakturoid_id pro supplier #$supplierId.\n";
    exit(0);
}
echo "Lokálně nalezeno faktur s fakturoid_id: " . count($ours) . "\n";

// Stáhni stavy z Fakturoidu
$plan = []; // [{local_id, varsymbol, current_status, fakturoid_status, set_status, set_sent_at, set_paid_at, set_cancelled_at}]
$skippedNotDraft = 0;
$missingInFakturoid = array_flip(array_keys($ours));

$isoToDt = static function (?string $iso): ?string {
    if ($iso === null || $iso === '') return null;
    $ts = strtotime($iso);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
};

foreach ($client->getAll($supplierId, 'invoices.json') as $inv) {
    $fid = (int) ($inv['id'] ?? 0);
    if (!isset($ours[$fid])) continue;
    unset($missingInFakturoid[$fid]);
    $local = $ours[$fid];

    if ($local['status'] !== 'draft') {
        $skippedNotDraft++;
        continue;
    }

    $fStatus    = (string) ($inv['status'] ?? 'open');
    $sentAt     = $isoToDt($inv['sent_at'] ?? null);
    $paidAt     = isset($inv['paid_on']) && $inv['paid_on'] !== null
                    ? (string) $inv['paid_on'] . ' 12:00:00' : null;
    $fallback   = ((string) $local['issue_date']) . ' 12:00:00';

    [$target, $finalSent, $finalCancelled] = match ($fStatus) {
        'paid'                       => ['paid',      $sentAt ?? $fallback, null],
        'cancelled', 'uncollectible' => ['cancelled', $sentAt,              $isoToDt($inv['updated_at'] ?? null) ?? date('Y-m-d H:i:s')],
        'sent', 'overdue'            => ['sent',      $sentAt ?? $fallback, null],
        default                      => ['issued',    $sentAt,              null],
    };

    $plan[] = [
        'local_id'         => (int) $local['id'],
        'varsymbol'        => $local['varsymbol'] ?? '',
        'fakturoid_status' => $fStatus,
        'set_status'       => $target,
        'set_sent_at'      => $finalSent,
        'set_paid_at'      => $paidAt,
        'set_cancelled_at' => $finalCancelled,
    ];
}

if (!$plan) {
    echo "Nic k aktualizaci.\n";
    if ($skippedNotDraft > 0) echo "  Přeskočeno (už nejsou draft): $skippedNotDraft\n";
    if ($missingInFakturoid)  echo "  Nenalezeno ve Fakturoidu: " . count($missingInFakturoid) . "\n";
    exit(0);
}

$summary = [];
foreach ($plan as $p) $summary[$p['set_status']] = ($summary[$p['set_status']] ?? 0) + 1;
echo "K aktualizaci: " . count($plan) . " faktur\n";
foreach ($summary as $s => $n) echo "  → $s: $n\n";
if ($skippedNotDraft > 0)    echo "Přeskočeno (už nejsou draft): $skippedNotDraft\n";
if ($missingInFakturoid)     echo "Nenalezeno ve Fakturoidu: " . count($missingInFakturoid) . "\n";

if (!$apply) {
    echo "\n(dry-run — pro zápis spusť s --apply)\n\n";
    foreach (array_slice($plan, 0, 20) as $p) {
        echo sprintf("  #%-6d %-15s draft → %-10s (fakt: %s)\n",
            $p['local_id'], $p['varsymbol'], $p['set_status'], $p['fakturoid_status']);
    }
    if (count($plan) > 20) echo "  … a další " . (count($plan) - 20) . "\n";
    exit(0);
}

$upd = $pdo->prepare(
    "UPDATE invoices
        SET status = ?, sent_at = ?, paid_at = ?, cancelled_at = ?
      WHERE id = ? AND supplier_id = ? AND status = 'draft'"
);

$pdo->beginTransaction();
$ok = 0;
try {
    foreach ($plan as $p) {
        $upd->execute([
            $p['set_status'], $p['set_sent_at'], $p['set_paid_at'], $p['set_cancelled_at'],
            $p['local_id'], $supplierId,
        ]);
        $ok += $upd->rowCount();
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "✗ Chyba, transakce zrušena: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Hotovo: $ok faktur aktualizováno.\n";

<?php

declare(strict_types=1);

/**
 * Doplnění zaokrouhlení u vydaných faktur importovaných z Fakturoidu / iDokladu (#258).
 *
 * PROBLÉM
 * -------
 * Import dřív přepočítal celkovou částku z položek na haléře a zaokrouhlení
 * zdrojového dokladu zahodil. Klient ale zaplatil zaokrouhlenou částku, takže
 * po spárování platby faktura visí jako „Přeplaceno" (1 640,76 × zaplaceno 1 641)
 * nebo „Částečně uhrazeno" (1 056,33 × zaplaceno 1 056).
 *
 * CO SKRIPT DĚLÁ
 * --------------
 * Najde importované vydané faktury (fakturoid_id / idoklad_id) bez zaokrouhlení,
 * u kterých evidované platby (paid_total) odpovídají částce k úhradě zaokrouhlené
 * na celé jednotky měny (rozdíl nejvýš 0,50 a paid_total je celé číslo). Rozdíl
 * uloží do invoices.rounding (do DPH se nepočítá, je součástí amount_to_pay)
 * a nezaplacenou fakturu, kterou platby nově pokrývají, překlopí na zaplacenou
 * (stejně jako InvoicePaymentService). DPH ani položky se nemění.
 *
 * Použití:
 *   php api/bin/backfill-imported-invoice-rounding.php                # dry-run
 *   php api/bin/backfill-imported-invoice-rounding.php --supplier=1   # jen jeden tenant
 *   php api/bin/backfill-imported-invoice-rounding.php --apply
 *
 * Idempotentní — opravený doklad už má rounding <> 0 a znovu se nevybere.
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun     = !in_array('--apply', $argv, true);
$supplierId = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--supplier=')) $supplierId = (int) substr($a, 11);
}

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

$params = [];
$sql = "SELECT i.id, i.supplier_id, i.varsymbol, i.status, i.amount_to_pay, i.paid_total,
               cur.code AS currency
          FROM invoices i
          JOIN currencies cur ON cur.id = i.currency_id
         WHERE (i.fakturoid_id IS NOT NULL OR i.idoklad_id IS NOT NULL)
           AND i.invoice_type IN ('invoice', 'proforma')
           AND i.status IN ('issued', 'sent', 'reminded', 'paid')
           AND i.rounding = 0
           AND i.paid_total > 0";
if ($supplierId !== null) { $sql .= ' AND i.supplier_id = ?'; $params[] = $supplierId; }
$sql .= ' ORDER BY i.supplier_id, i.issue_date, i.id';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$candidates = [];
foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
    $paid = (float) $r['paid_total'];
    $diff = round($paid - (float) $r['amount_to_pay'], 2);
    $paidIsWhole = abs($paid - round($paid)) < 0.005;
    if ($paidIsWhole && abs($diff) >= 0.005 && abs($diff) <= 0.5) {
        $r['diff'] = $diff;
        $candidates[] = $r;
    }
}

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Importované faktury s haléřovým rozdílem proti zaokrouhlené úhradě: " . count($candidates) . "\n";
if ($candidates === []) {
    exit(0);
}

$updRounding = $pdo->prepare('UPDATE invoices SET rounding = ? WHERE id = ? AND rounding = 0');
$markPaid = $pdo->prepare(
    "UPDATE invoices
        SET status = 'paid',
            paid_at = (SELECT MAX(p.paid_on) FROM invoice_payments p WHERE p.invoice_id = invoices.id)
      WHERE id = ? AND status IN ('issued', 'sent', 'reminded')
        AND paid_total >= amount_to_pay - 0.05"
);

$stats = $container->get(\MyInvoice\Service\Stats\StatsRecomputer::class);
$becamePaid = 0;
foreach ($candidates as $c) {
    $flip = $c['status'] !== 'paid' ? ' → zaplaceno' : '';
    printf("  t%-2d #%-6d %-16s %s  k úhradě %.2f, uhrazeno %.2f → zaokrouhlení %+.2f%s\n",
        $c['supplier_id'], $c['id'], (string) $c['varsymbol'], $c['currency'],
        (float) $c['amount_to_pay'], (float) $c['paid_total'], $c['diff'], $flip);
    if ($dryRun) {
        continue;
    }
    $pdo->beginTransaction();
    try {
        $updRounding->execute([$c['diff'], $c['id']]);
        $markPaid->execute([$c['id']]);
        $becamePaid += $markPaid->rowCount();
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $stats->recomputeForInvoiceId((int) $c['id']);
}

echo $dryRun
    ? "Nic nezměněno. Pro zápis spusť s --apply.\n"
    : "Hotovo: zaokrouhlení doplněno u " . count($candidates) . " faktur, nově zaplaceno {$becamePaid}.\n";

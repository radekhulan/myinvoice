<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Crm;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Invoice\OverduePolicy;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OverdueActionItemsTest extends TestCase
{
    public static function boundaries(): array
    {
        return ['strict' => [false, [1]], 'including today' => [true, [1, 2]]];
    }

    #[DataProvider('boundaries')]
    public function testWidgetAndHistoricalSnapshotRespectBoundary(bool $includesToday, array $expected): void
    {
        $sqlite = new \Pdo\Sqlite('sqlite::memory:');
        $sqlite->createFunction('CURDATE', static fn () => date('Y-m-d'));
        $sqlite->exec('CREATE TABLE invoices (id INTEGER, supplier_id INTEGER, status TEXT, due_date TEXT,
            invoice_type TEXT, parent_invoice_id INTEGER, amount_to_pay REAL, paid_total REAL)');
        $insert = $sqlite->prepare("INSERT INTO invoices VALUES (?, ?, ?, ?, 'invoice', NULL, 100, ?)");
        foreach ([-1, 0, 1] as $offset) {
            $insert->execute([$offset + 2, 7, 'issued', date('Y-m-d', strtotime("$offset days")), 0]);
        }
        $insert->execute([4, 8, 'issued', date('Y-m-d', strtotime('-1 day')), 0]);
        $insert->execute([5, 7, 'paid', date('Y-m-d', strtotime('-1 day')), 100]);
        $insert->execute([6, 7, 'issued', date('Y-m-d', strtotime('-1 day')), 100]);

        $empty = $this->createStub(PDOStatement::class);
        $empty->method('fetchAll')->willReturn([]);
        $empty->method('fetch')->willReturn(false);
        $empty->method('fetchColumn')->willReturn(0);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($sqlite, $empty) {
            return str_contains($sql, 'SELECT i.id FROM invoices i') ? $sqlite->prepare($sql) : $empty;
        });
        $db = new Connection(new Config([]));
        new \ReflectionProperty(Connection::class, 'pdo')->setValue($db, $pdo);
        $service = new CrmAggregationService($db, new OverduePolicy(
            new Config(['invoices' => ['overdue_includes_today' => $includesToday]]),
        ));

        $result = $service->actionItems(7);
        $items = array_values(array_filter($result['items'], static fn (array $item) => $item['type'] === 'overdue_invoices'));
        self::assertCount(1, $items);
        self::assertSame(count($expected), $items[0]['count']);
        self::assertSame('/invoices?overdue=1', $items[0]['link']);
        $ids = new \ReflectionMethod($service, 'snapshotCurrentIds')->invoke($service, 7, 'overdue_invoices');
        self::assertSame($expected, $ids);
    }
}

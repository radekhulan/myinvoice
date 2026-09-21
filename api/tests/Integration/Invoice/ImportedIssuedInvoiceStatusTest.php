<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\FakturoidImportService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #250: vydané doklady z API importu zachovávají lifecycle zdroje. Otevřený doklad
 * je vystavený (se snapshoty, bez sent_at) a má vypnuté automatické upomínky,
 * uhrazený je zaplacený, doklad bez čísla zůstává koncept.
 */
#[Group('integration')]
final class ImportedIssuedInvoiceStatusTest extends TestCase
{
    private const SUBJECT_ID = 990250001;

    private Connection $db;
    private FakturoidImportService $service;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $userId = 0;
    private ?int $previousFakturoidId = null;

    /** @var int[] */
    private array $createdInvoiceIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(FakturoidImportService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $row = $pdo->query(
            "SELECT c.id, c.supplier_id, c.fakturoid_id
               FROM clients c
               JOIN currencies cur ON cur.supplier_id = c.supplier_id AND cur.code = 'CZK' AND cur.is_active = 1
              WHERE c.archived_at IS NULL
              ORDER BY c.id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if (!$row || $this->userId <= 0) {
            $this->markTestSkipped('Chybí klient s CZK měnou nebo uživatel.');
        }
        $this->clientId = (int) $row['id'];
        $this->supplierId = (int) $row['supplier_id'];
        $this->previousFakturoidId = $row['fakturoid_id'] !== null ? (int) $row['fakturoid_id'] : null;
        $pdo->prepare('UPDATE clients SET fakturoid_id = ? WHERE id = ?')->execute([self::SUBJECT_ID, $this->clientId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->createdInvoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('UPDATE clients SET fakturoid_id = ? WHERE id = ?')->execute([$this->previousFakturoidId, $this->clientId]);
        }
        $this->db->close();
    }

    /** @return array<string,mixed> */
    private function import(array $doc): array
    {
        $method = new \ReflectionMethod(FakturoidImportService::class, 'createIssued');
        $id = (int) $method->invoke($this->service, $doc + [
            'subject_id' => self::SUBJECT_ID,
            'issued_on'  => '2099-02-01',
            'taxable_fulfillment_due' => '2099-02-01',
            'due_on'     => '2099-02-15',
            'currency'   => 'CZK',
            'lines'      => [['name' => 'TEST #250', 'quantity' => 1, 'unit_name' => 'ks', 'unit_price' => 1000, 'vat_rate' => 21]],
        ], $this->supplierId, $this->userId);
        $this->createdInvoiceIds[] = $id;
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, sent_at, paid_at, auto_send_reminders, client_snapshot, supplier_snapshot, varsymbol
               FROM invoices WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function openStates(): array
    {
        return [['open'], ['sent'], ['overdue'], ['uncollectible']];
    }

    #[DataProvider('openStates')]
    public function testOpenInvoiceIsIssuedWithoutAutomaticReminders(string $status): void
    {
        $row = $this->import(['status' => $status, 'number' => 'T250-' . $status, 'variable_symbol' => '2099250' . random_int(100, 999)]);

        self::assertSame('issued', $row['status']);
        self::assertNull($row['sent_at'], 'Import neví, zda byl doklad odeslán.');
        self::assertSame(0, (int) $row['auto_send_reminders'], 'Historická pohledávka nesmí spustit automatické upomínky.');
        self::assertNotEmpty($row['client_snapshot']);
        self::assertNotEmpty($row['supplier_snapshot']);
    }

    public function testPaidInvoiceStaysPaid(): void
    {
        $row = $this->import(['status' => 'paid', 'paid_on' => '2099-02-10', 'variable_symbol' => '2099250' . random_int(100, 999)]);

        self::assertSame('paid', $row['status']);
        self::assertSame('2099-02-10', substr((string) $row['paid_at'], 0, 10));
        self::assertSame(1, (int) $row['auto_send_reminders']);
    }
}

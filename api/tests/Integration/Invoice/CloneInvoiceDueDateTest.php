<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Klon faktury odvozuje splatnost včetně jednotky v prioritě
 * zakázka → klient → dodavatel → 7 dní.
 *
 * Soft-skip bez cfg.php / DB (CI runner).
 */
#[Group('integration')]
final class CloneInvoiceDueDateTest extends TestCase
{
    private Connection $db;
    private BulkReissueAction $bulk;
    private InvoiceDefaults $defaults;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $sourceId = 0;
    /** @var int[] */
    private array $createdClones = [];

    protected function setUp(): void
    {
        // cfg.php leží v rootu repa (Bootstrap::rootDir() = dirname(api/src, 2)).
        // Z api/tests/Integration/Invoice je to o 4 úrovně výš.
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->bulk = $c->get(BulkReissueAction::class);
            $this->defaults = $c->get(InvoiceDefaults::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        // Zdrojová faktura bez zakázky (project_id IS NULL); supplier odvodíme z ní,
        // ne z „prvního v tabulce" — jinak by se test zbytečně skipoval, když faktury
        // patří jinému dodavateli.
        $row = $pdo->query(
            "SELECT id, supplier_id FROM invoices
              WHERE project_id IS NULL
                AND invoice_type = 'invoice' AND status NOT IN ('cancelled')
              ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->sourceId = (int) ($row['id'] ?? 0);
        $this->supplierId = (int) ($row['supplier_id'] ?? 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->sourceId === 0) {
            $this->markTestSkipped('Chybí supplier/user/zdrojová faktura bez zakázky.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->createdClones as $id) {
            $this->db->pdo()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
    }

    public function testCloneWithoutProjectUsesDefaultDueDays(): void
    {
        $pdo = $this->db->pdo();
        // Stejná priorita jako cloneOne / InvoiceDefaults: bez zakázky → klient → dodavatel → 7.
        $clientId = (int) $pdo->query("SELECT client_id FROM invoices WHERE id = {$this->sourceId}")->fetchColumn();
        $clientDays = $pdo->query("SELECT payment_due_default FROM clients WHERE id = {$clientId}")->fetchColumn();
        $supplierDays = $pdo->query("SELECT default_payment_due_days FROM supplier WHERE id = {$this->supplierId}")->fetchColumn();
        $days = $clientDays !== false && $clientDays !== null
            ? (int) $clientDays
            : ($supplierDays !== false && $supplierDays !== null ? (int) $supplierDays : 7);
        if ($days <= 0) {
            self::markTestSkipped('Výchozí splatnost klienta i dodavatele je 0 — regrese (≠ vystavení) by nešla ověřit.');
        }

        $issueDate = date('Y-m-d');
        $cloneId = $this->bulk->cloneOne($this->sourceId, $issueDate, false, $this->userId);
        $this->createdClones[] = $cloneId;

        $row = $pdo->query("SELECT issue_date, due_date FROM invoices WHERE id = $cloneId")->fetch(PDO::FETCH_ASSOC);
        $expected = date('Y-m-d', strtotime($issueDate . " +{$days} days"));

        self::assertSame($issueDate, (string) $row['issue_date'], 'issue_date klonu má být zadané datum.');
        self::assertSame($expected, (string) $row['due_date'], 'splatnost klonu má být vystavení + výchozí splatnost (klient → dodavatel → 7).');
        self::assertNotSame($issueDate, (string) $row['due_date'], 'splatnost nesmí být rovna datu vystavení (0 dní).');
    }

    public function testCloneUsesClientCalendarMonthDueDate(): void
    {
        $pdo = $this->db->pdo();
        $clientId = (int) $pdo->query("SELECT client_id FROM invoices WHERE id = {$this->sourceId}")->fetchColumn();
        $stmt = $pdo->prepare('SELECT payment_due_default, payment_due_unit FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($clientId === 0 || $original === false) {
            self::markTestSkipped('Zdrojová faktura nemá klienta.');
        }

        try {
            $pdo->prepare(
                "UPDATE clients SET payment_due_default = 1, payment_due_unit = 'month' WHERE id = ?"
            )->execute([$clientId]);

            $cloneId = $this->bulk->cloneOne($this->sourceId, '2026-07-31', false, $this->userId);
            $this->createdClones[] = $cloneId;

            $dueDate = $pdo->query("SELECT due_date FROM invoices WHERE id = $cloneId")->fetchColumn();
            self::assertSame('2026-08-31', (string) $dueDate);

            $resolved = $this->defaults->resolve([
                'client_id' => $clientId,
                'issue_date' => '2026-07-31',
            ], $this->supplierId);
            self::assertSame('2026-08-31', $resolved['due_date']);
        } finally {
            $pdo->prepare(
                'UPDATE clients SET payment_due_default = ?, payment_due_unit = ? WHERE id = ?'
            )->execute([
                $original['payment_due_default'],
                $original['payment_due_unit'],
                $clientId,
            ]);
        }
    }

    public function testCloneUsesSupplierCalendarMonthDueDate(): void
    {
        $pdo = $this->db->pdo();
        $clientId = (int) $pdo->query("SELECT client_id FROM invoices WHERE id = {$this->sourceId}")->fetchColumn();

        $clientStmt = $pdo->prepare('SELECT payment_due_default, payment_due_unit FROM clients WHERE id = ?');
        $clientStmt->execute([$clientId]);
        $originalClient = $clientStmt->fetch(PDO::FETCH_ASSOC);
        $supplierStmt = $pdo->prepare(
            'SELECT default_payment_due_days, default_payment_due_unit FROM supplier WHERE id = ?'
        );
        $supplierStmt->execute([$this->supplierId]);
        $originalSupplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
        if ($clientId === 0 || $originalClient === false || $originalSupplier === false) {
            self::markTestSkipped('Chybí klient nebo dodavatel zdrojové faktury.');
        }

        try {
            $pdo->prepare(
                'UPDATE clients SET payment_due_default = NULL, payment_due_unit = NULL WHERE id = ?'
            )->execute([$clientId]);
            $pdo->prepare(
                "UPDATE supplier
                    SET default_payment_due_days = 1, default_payment_due_unit = 'month'
                  WHERE id = ?"
            )->execute([$this->supplierId]);

            $cloneId = $this->bulk->cloneOne($this->sourceId, '2026-07-31', false, $this->userId);
            $this->createdClones[] = $cloneId;

            $dueDate = $pdo->query("SELECT due_date FROM invoices WHERE id = $cloneId")->fetchColumn();
            self::assertSame('2026-08-31', (string) $dueDate);
        } finally {
            $pdo->prepare(
                'UPDATE clients SET payment_due_default = ?, payment_due_unit = ? WHERE id = ?'
            )->execute([
                $originalClient['payment_due_default'],
                $originalClient['payment_due_unit'],
                $clientId,
            ]);
            $pdo->prepare(
                'UPDATE supplier
                    SET default_payment_due_days = ?, default_payment_due_unit = ?
                  WHERE id = ?'
            )->execute([
                $originalSupplier['default_payment_due_days'],
                $originalSupplier['default_payment_due_unit'],
                $this->supplierId,
            ]);
        }
    }

    public function testClientValueInheritsSupplierCalendarMonthUnit(): void
    {
        $pdo = $this->db->pdo();
        $clientId = (int) $pdo->query("SELECT client_id FROM invoices WHERE id = {$this->sourceId}")->fetchColumn();

        $clientStmt = $pdo->prepare('SELECT payment_due_default, payment_due_unit FROM clients WHERE id = ?');
        $clientStmt->execute([$clientId]);
        $originalClient = $clientStmt->fetch(PDO::FETCH_ASSOC);
        $supplierStmt = $pdo->prepare(
            'SELECT default_payment_due_days, default_payment_due_unit FROM supplier WHERE id = ?'
        );
        $supplierStmt->execute([$this->supplierId]);
        $originalSupplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
        if ($clientId === 0 || $originalClient === false || $originalSupplier === false) {
            self::markTestSkipped('Chybí klient nebo dodavatel zdrojové faktury.');
        }

        try {
            $pdo->prepare(
                'UPDATE clients SET payment_due_default = 1, payment_due_unit = NULL WHERE id = ?'
            )->execute([$clientId]);
            $pdo->prepare(
                "UPDATE supplier SET default_payment_due_unit = 'month' WHERE id = ?"
            )->execute([$this->supplierId]);

            $cloneId = $this->bulk->cloneOne($this->sourceId, '2026-07-31', false, $this->userId);
            $this->createdClones[] = $cloneId;

            $dueDate = $pdo->query("SELECT due_date FROM invoices WHERE id = $cloneId")->fetchColumn();
            self::assertSame('2026-08-31', (string) $dueDate);

            $resolved = $this->defaults->resolve([
                'client_id' => $clientId,
                'issue_date' => '2026-07-31',
            ], $this->supplierId);
            self::assertSame('2026-08-31', $resolved['due_date']);
        } finally {
            $pdo->prepare(
                'UPDATE clients SET payment_due_default = ?, payment_due_unit = ? WHERE id = ?'
            )->execute([
                $originalClient['payment_due_default'],
                $originalClient['payment_due_unit'],
                $clientId,
            ]);
            $pdo->prepare(
                'UPDATE supplier SET default_payment_due_unit = ? WHERE id = ?'
            )->execute([
                $originalSupplier['default_payment_due_unit'],
                $this->supplierId,
            ]);
        }
    }
}

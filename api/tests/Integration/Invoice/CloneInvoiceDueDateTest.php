<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Klon faktury BEZ zakázky musí splatnost odvodit z výchozí splatnosti dodavatele
 * (default_payment_due_days), ne ji nechat rovnou datu vystavení (0 dní).
 *
 * Regrese: cloneOne počítal due_date jen z project.payment_due_days; bez zakázky
 * zůstalo due_date = issue_date.
 *
 * Soft-skip bez cfg.php / DB (CI runner).
 */
#[Group('integration')]
final class CloneInvoiceDueDateTest extends TestCase
{
    private Connection $db;
    private BulkReissueAction $bulk;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $sourceId = 0;
    /** @var int[] */
    private array $createdClones = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->bulk = $c->get(BulkReissueAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        // Zdrojová faktura bez zakázky (project_id IS NULL).
        $this->sourceId = (int) ($pdo->query(
            "SELECT id FROM invoices
              WHERE supplier_id = {$this->supplierId} AND project_id IS NULL
                AND invoice_type = 'invoice' AND status NOT IN ('cancelled')
              ORDER BY id DESC LIMIT 1"
        )->fetchColumn() ?: 0);
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

    public function testCloneWithoutProjectUsesSupplierDefaultDueDays(): void
    {
        $pdo = $this->db->pdo();
        $days = (int) $pdo->query("SELECT default_payment_due_days FROM supplier WHERE id = {$this->supplierId}")->fetchColumn();
        if ($days <= 0) {
            self::markTestSkipped('Dodavatel nemá kladnou výchozí splatnost.');
        }

        $issueDate = date('Y-m-d');
        $cloneId = $this->bulk->cloneOne($this->sourceId, $issueDate, false, $this->userId);
        $this->createdClones[] = $cloneId;

        $row = $pdo->query("SELECT issue_date, due_date FROM invoices WHERE id = $cloneId")->fetch(PDO::FETCH_ASSOC);
        $expected = date('Y-m-d', strtotime($issueDate . " +{$days} days"));

        self::assertSame($issueDate, (string) $row['issue_date'], 'issue_date klonu má být zadané datum.');
        self::assertSame($expected, (string) $row['due_date'], 'splatnost klonu má být vystavení + výchozí splatnost dodavatele.');
        self::assertNotSame($issueDate, (string) $row['due_date'], 'splatnost nesmí být rovna datu vystavení (0 dní).');
    }
}

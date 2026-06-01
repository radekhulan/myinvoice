<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Samoopravné číslování (issue #85): když je counter pozadu za již použitými čísly
 * (typicky po importu / ruční úpravě DB), generátor nesmí vrátit obsazené číslo —
 * buď přeskočí na volné, nebo se counter dorovná přes syncCounter().
 *
 * Izolace: testuje se v daleké budoucnosti (rok 2099), aby se renderovaná čísla
 * nekřížila s reálnými daty v testovací DB. Soft-skip bez cfg.php / DB / template.
 */
#[Group('integration')]
final class VarsymbolCounterSyncTest extends TestCase
{
    private Connection $db;
    private VarsymbolGenerator $gen;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $template = '';
    private \DateTimeImmutable $date;
    /** @var int[] */
    private array $created = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->gen = $c->get(VarsymbolGenerator::class);
            $config = $c->get(Config::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        // Efektivní template = supplier format, jinak cfg fallback (stejná priorita jako generátor).
        $this->template = trim((string) ($pdo->query(
            "SELECT invoice_number_format FROM supplier WHERE id = {$this->supplierId}"
        )->fetchColumn() ?: ''));
        if ($this->template === '') {
            $this->template = trim((string) $config->get('varsymbol.templates.invoice', ''));
        }
        if ($this->template === '' || !str_contains($this->template, '{C')) {
            $this->markTestSkipped('Není template s counterem ({C+}) pro vydanou fakturu.');
        }

        $this->clientId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->clientId === 0 || $this->currencyId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/CZK currency/user.');
        }

        $this->date = new \DateTimeImmutable('2099-06-15');
        // Čistý start scope (kdyby zbyl z dřívějšího běhu).
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->created as $id) {
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        $this->created = [];
        // Counter scope(y) roku 2099 — nezávisle na period (year "2099" / month "209906").
        $pdo->prepare("DELETE FROM invoice_counters WHERE supplier_id = ? AND period LIKE '2099%'")
            ->execute([$this->supplierId]);
    }

    private function insertIssued(int $counter): string
    {
        $varsymbol = $this->gen->render($this->template, $this->date, $counter);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, 'issued', 0, 0, ?)"
        )->execute([
            $varsymbol, $this->clientId, $this->supplierId,
            $this->date->format('Y-m-d'), $this->date->format('Y-m-d'), $this->date->format('Y-m-d'),
            $this->currencyId, $this->userId,
        ]);
        $this->created[] = (int) $pdo->lastInsertId();
        return $varsymbol;
    }

    public function testNextSkipsAlreadyUsedNumber(): void
    {
        // Counter scope je čistý (0). Obsadíme číslo s counterem 1 napřímo (jako by po importu).
        $occupied = $this->insertIssued(1);

        // next() by naivně vrátil counter=1 (== $occupied) → musí přeskočit na volné.
        $next = $this->gen->next($this->supplierId, 'invoice', $this->date);

        self::assertNotSame($occupied, $next, 'Generátor nesmí vrátit již použité číslo.');
        self::assertSame($this->gen->render($this->template, $this->date, 2), $next, 'Má navázat na další volné číslo (counter 2).');
    }

    public function testSyncCounterLiftsToHighestUsed(): void
    {
        // Importovaná historická čísla s vysokým counterem, counter scope ale stojí na 0.
        $this->insertIssued(40);
        $this->insertIssued(42);

        $synced = $this->gen->syncCounter($this->supplierId, 'invoice', $this->date);
        self::assertSame(42, $synced, 'syncCounter dorovná counter na nejvyšší použité číslo.');

        // Další vystavení tedy dostane counter 43 (žádná kolize).
        $next = $this->gen->next($this->supplierId, 'invoice', $this->date);
        self::assertSame($this->gen->render($this->template, $this->date, 43), $next);
    }

    public function testSyncCounterNeverLowers(): void
    {
        // Faktura na counteru 42, ale counter scope je ručně vepředu na 50.
        $this->insertIssued(42);
        // Založ counter row pro správnou scope (přes next()) a vytlač na 50.
        $this->gen->next($this->supplierId, 'invoice', $this->date);
        $this->db->pdo()->prepare(
            "UPDATE invoice_counters SET last_number = 50
              WHERE supplier_id = ? AND invoice_type = 'invoice' AND client_id = 0 AND period LIKE '2099%'"
        )->execute([$this->supplierId]);

        // sync nesmí counter snížit na 42 (GREATEST) → zůstává 50.
        $synced = $this->gen->syncCounter($this->supplierId, 'invoice', $this->date);
        self::assertSame(50, $synced, 'syncCounter nikdy nesnižuje (GREATEST).');
    }

    public function testSyncCounterNoopWithoutInvoices(): void
    {
        // Žádná faktura v scope → není co dorovnávat.
        $synced = $this->gen->syncCounter($this->supplierId, 'invoice', $this->date);
        self::assertSame(0, $synced);
    }
}

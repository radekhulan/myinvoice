<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regrese: itemsFor() / find() musí vracet vat_classification_code na položkách.
 *
 * Sloupec invoice_items.vat_classification_code je v DB vyplněný (replaceItems
 * ho defaultuje podle sazby + reverse charge + země klienta) a slouží DPH
 * výkazům, ale čtecí SELECT v itemsFor() ho dřív nevybíral — API ho na
 * položkách nevracelo (jen na hlavičce faktury). To rozbíjelo GET→PUT
 * round-trip: ruční klasifikace na řádku se při uložení zpět ztratila.
 *
 * Používá existující supplier/client/currency/vat_rate z dev DB; uklízí po sobě.
 */
#[Group('integration')]
final class InvoiceItemVatClassificationTest extends TestCase
{
    private Connection $db;
    private InvoiceRepository $repo;
    private InvoiceCalculator $calc;

    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;

    /** @var int[] */
    private array $createdInvoiceIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->db = $container->get(Connection::class);
            $this->repo = $container->get(InvoiceRepository::class);
            $this->calc = $container->get(InvoiceCalculator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();

        $supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        if ($supplierId <= 0) {
            $this->markTestSkipped('Žádný supplier');
        }

        $stmt = $pdo->prepare('SELECT id FROM clients WHERE supplier_id = ? AND archived_at IS NULL LIMIT 1');
        $stmt->execute([$supplierId]);
        $this->clientId = (int) $stmt->fetchColumn();
        if ($this->clientId <= 0) {
            $this->markTestSkipped("Supplier #{$supplierId} nemá klienty");
        }

        $stmt = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$supplierId]);
        $this->currencyId = (int) $stmt->fetchColumn();
        if ($this->currencyId <= 0) {
            $this->markTestSkipped('Supplier nemá aktivní měnu');
        }

        $this->vatRateId = (int) $pdo->query(
            'SELECT id FROM vat_rates
              WHERE is_reverse_charge = 0
                AND (valid_from IS NULL OR valid_from <= CURDATE())
                AND (valid_to IS NULL OR valid_to >= CURDATE())
              ORDER BY is_default DESC, rate_percent DESC LIMIT 1'
        )->fetchColumn();
        if ($this->vatRateId <= 0) {
            $this->markTestSkipped('Žádná použitelná VAT sazba');
        }

        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($this->userId <= 0) {
            $this->markTestSkipped('Žádný uživatel');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->createdInvoiceIds !== []) {
            $pdo = $this->db->pdo();
            foreach ($this->createdInvoiceIds as $id) {
                $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
            }
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testItemsForExposesVatClassificationCode(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $id = $this->repo->createDraft([
            'invoice_type'     => 'invoice',
            'client_id'        => $this->clientId,
            'issue_date'       => $today,
            'tax_date'         => $today,
            'due_date'         => $today,
            'currency_id'      => $this->currencyId,
            'reverse_charge'   => false,
            'language'         => 'cs',
            'discount_percent' => 0,
        ], $this->userId);
        $this->createdInvoiceIds[] = $id;

        // Položka BEZ explicitní klasifikace — replaceItems ji má dopočítat a uložit.
        $this->repo->replaceItems($id, [[
            'description'            => 'TEST položka (PHPUnit)',
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 1000,
            'vat_rate_id'            => $this->vatRateId,
            'order_index'            => 0,
        ]]);
        $this->calc->recompute($id);

        $items = $this->repo->itemsFor($id);
        $this->assertNotEmpty($items, 'itemsFor() má vrátit alespoň jednu položku');
        $item = $items[0];

        $this->assertArrayHasKey(
            'vat_classification_code',
            $item,
            'itemsFor() musí vracet vat_classification_code (čtecí SELECT ho dřív vynechával)'
        );
        $this->assertNotNull(
            $item['vat_classification_code'],
            'replaceItems klasifikaci defaultuje, takže po uložení nesmí být null'
        );

        // Konzistence s DB jako zdrojem pravdy.
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_classification_code FROM invoice_items
              WHERE invoice_id = ? ORDER BY order_index, id LIMIT 1'
        );
        $stmt->execute([$id]);
        $this->assertSame(
            (string) $stmt->fetchColumn(),
            (string) $item['vat_classification_code'],
            'Hodnota z API musí odpovídat tomu, co je v DB'
        );
    }
}

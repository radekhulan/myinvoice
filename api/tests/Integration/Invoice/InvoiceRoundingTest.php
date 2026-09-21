<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Import\ImportedInvoiceRounding;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #258: zaokrouhlení vydané faktury (převzaté z importu) je součástí částky
 * k úhradě, přepočet položek ho nesmaže a zaokrouhlená úhrada fakturu vyrovná
 * bez přeplatku i nedoplatku.
 */
#[Group('integration')]
final class InvoiceRoundingTest extends TestCase
{
    private Connection $db;
    private InvoiceRepository $repo;
    private InvoiceCalculator $calc;
    private InvoicePaymentService $payments;

    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;

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
            $this->repo = $container->get(InvoiceRepository::class);
            $this->calc = $container->get(InvoiceCalculator::class);
            $this->payments = $container->get(InvoicePaymentService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $stmt = $pdo->prepare('SELECT id FROM clients WHERE supplier_id = ? AND archived_at IS NULL LIMIT 1');
        $stmt->execute([$supplierId]);
        $this->clientId = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$supplierId]);
        $this->currencyId = (int) $stmt->fetchColumn();
        $this->vatRateId = (int) $pdo->query(
            'SELECT id FROM vat_rates
              WHERE is_reverse_charge = 0 AND rate_percent > 0
                AND (valid_from IS NULL OR valid_from <= CURDATE())
                AND (valid_to IS NULL OR valid_to >= CURDATE())
              ORDER BY is_default DESC, rate_percent DESC LIMIT 1'
        )->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($this->clientId <= 0 || $this->currencyId <= 0 || $this->vatRateId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí klient, měna, sazba DPH nebo uživatel.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            foreach ($this->createdInvoiceIds as $id) {
                $pdo->prepare('DELETE FROM invoice_payments WHERE invoice_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
            }
            $this->db->close();
        }
    }

    /** Vystavená faktura, jejíž součet položek není celé číslo. */
    private function createIssued(): int
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $id = $this->repo->createDraft([
            'invoice_type'   => 'invoice',
            'client_id'      => $this->clientId,
            'issue_date'     => $today,
            'tax_date'       => $today,
            'due_date'       => $today,
            'currency_id'    => $this->currencyId,
            'reverse_charge' => false,
            'language'       => 'cs',
            'varsymbol'      => '2099' . random_int(100000, 999999),
        ], $this->userId);
        $this->createdInvoiceIds[] = $id;
        $this->repo->replaceItems($id, [[
            'description'            => 'TEST zaokrouhlení (PHPUnit)',
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 1356.33,
            'vat_rate_id'            => $this->vatRateId,
            'order_index'            => 0,
        ]]);
        $this->calc->recompute($id);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$id]);
        return $id;
    }

    /** @return array{total_with_vat:float, rounding:float, amount_to_pay:float, status:string} */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT total_with_vat, rounding, amount_to_pay, status FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'total_with_vat' => (float) $r['total_with_vat'],
            'rounding'       => (float) $r['rounding'],
            'amount_to_pay'  => (float) $r['amount_to_pay'],
            'status'         => (string) $r['status'],
        ];
    }

    public function testRoundingIsPartOfAmountToPayAndSurvivesRecompute(): void
    {
        $id = $this->createIssued();
        $withVat = $this->row($id)['total_with_vat'];
        $rounding = ImportedInvoiceRounding::fromFakturoid(['total' => (string) round($withVat)], $withVat);
        self::assertNotEquals(0.0, $rounding, 'Testovací součet musí mít haléře.');

        $this->repo->setRounding($id, $rounding);
        $this->calc->recompute($id);

        $row = $this->row($id);
        self::assertEqualsWithDelta($rounding, $row['rounding'], 0.001, 'Přepočet položek nesmí zaokrouhlení smazat.');
        self::assertEqualsWithDelta(round($withVat), $row['amount_to_pay'], 0.001);
        self::assertEqualsWithDelta($withVat, $row['total_with_vat'], 0.001, 'Zaokrouhlení nesmí změnit částku s DPH.');
    }

    public function testRoundedPaymentSettlesInvoiceExactly(): void
    {
        $id = $this->createIssued();
        $withVat = $this->row($id)['total_with_vat'];
        $this->repo->setRounding($id, round(round($withVat) - $withVat, 2));

        $this->payments->recordPayment($id, round($withVat), (new \DateTimeImmutable('today'))->format('Y-m-d'));

        $row = $this->row($id);
        self::assertSame('paid', $row['status']);
        self::assertSame('paid', InvoicePaymentService::paymentStatus($row + ['paid_total' => round($withVat)]));
    }
}

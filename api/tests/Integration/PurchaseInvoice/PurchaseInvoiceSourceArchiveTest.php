<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\InvoiceImportService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Import přijaté faktury z ISDOC uloží ORIGINÁLNÍ zdrojový artefakt do source_* (issue #175):
 * bajty as-is, oddělený sources/ podstrom, write-once.
 */
final class PurchaseInvoiceSourceArchiveTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private InvoiceImportService $importer;
    private int $supplierId = 0;
    private int $userId = 0;
    private string $supplierIc = '';
    private int $currencyId = 0;
    private ?int $vendorId = null;
    private array $createdPurchaseIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db       = $c->get(Connection::class);
            $this->config   = $c->get(Config::class);
            $this->importer = $c->get(InvoiceImportService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $row = $pdo->query('SELECT id, ic FROM supplier ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->supplierId = (int) ($row['id'] ?? 0);
        $this->supplierIc = preg_replace('/\D/', '', (string) ($row['ic'] ?? '')) ?? '';
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->supplierIc === '' || $this->userId === 0 || $this->currencyId === 0) {
            $this->markTestSkipped('Chybí supplier s IČO / user / měna v DB.');
        }
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        // Pre-create vendor (ať resolveVendor reusne a nevolá ARES).
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Source Test Vendor", "Test 1", "Praha", "11000", ?, "12345678", "v@example.com",
                     "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $countryId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->createdPurchaseIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        if ($this->vendorId !== null) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->vendorId]);
        }
        $this->db->close();
    }

    public function testIsdocImportArchivesOriginalSourceArtifact(): void
    {
        $isdoc = $this->minimalIsdoc('SRC-TEST-001');
        $report = $this->importer->importBundle(
            [['name' => 'src-test.isdoc', 'content' => $isdoc]],
            $this->supplierId,
            $this->userId,
            'purchase',
        );
        self::assertSame(1, $report['summary']['created'] ?? 0, 'import musí vytvořit 1 přijatou fakturu');

        $row = $this->loadCreated('SRC-TEST-001');
        self::assertNotNull($row, 'faktura se musí vytvořit');
        self::assertSame('isdoc', $row['source_format'], 'source_format = isdoc');
        self::assertStringStartsWith('sources/supplier-' . $this->supplierId . '/', (string) $row['source_path'],
            'source_path je v odděleném sources/ podstromu');
        self::assertSame(strlen($isdoc), (int) $row['source_size_bytes'], 'velikost = originál');
        self::assertSame(hash('sha256', $isdoc), $row['source_hash'], 'hash = originál');

        // Soubor na disku = originální bajty AS-IS.
        $abs = $this->archiveRoot() . '/' . $row['source_path'];
        self::assertFileExists($abs);
        self::assertSame($isdoc, file_get_contents($abs), 'uložené bajty = originál (as-is)');
    }

    public function testSourceMetadataIsWriteOnce(): void
    {
        $isdoc = $this->minimalIsdoc('SRC-TEST-002');
        $this->importer->importBundle([['name' => 'a.isdoc', 'content' => $isdoc]], $this->supplierId, $this->userId, 'purchase');
        $first = $this->loadCreated('SRC-TEST-002');
        self::assertNotNull($first);
        $origUploadedAt = $first['source_uploaded_at'];

        // Re-import téhož dokladu — dedup vrátí existující fakturu; source_* se NESMÍ přepsat.
        $this->importer->importBundle([['name' => 'a.isdoc', 'content' => $isdoc]], $this->supplierId, $this->userId, 'purchase');
        $second = $this->loadCreated('SRC-TEST-002');
        self::assertSame($first['source_path'], $second['source_path'], 'source_path write-once');
        self::assertSame($origUploadedAt, $second['source_uploaded_at'], 'source_uploaded_at se nepřepisuje');
    }

    /** @return array<string,mixed>|null */
    private function loadCreated(string $varsymbol): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, source_path, source_hash, source_size_bytes, source_format, source_uploaded_at
               FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ? LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $varsymbol]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $this->createdPurchaseIds[(int) $row['id']] = (int) $row['id'];
        return $row;
    }

    private function archiveRoot(): string
    {
        $dir = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($dir !== '') return $dir;
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') return dirname($uploads) . '/purchase-invoices';
        return RuntimePaths::storage('purchase-invoices');
    }

    private function minimalIsdoc(string $id): string
    {
        $ic = $this->supplierIc;
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="http://isdoc.cz/namespace/2013">
  <DocumentType>1</DocumentType>
  <ID>{$id}</ID>
  <IssueDate>2026-05-01</IssueDate>
  <TaxPointDate>2026-05-01</TaxPointDate>
  <LocalCurrencyCode>CZK</LocalCurrencyCode>
  <CurrencyCode>CZK</CurrencyCode>
  <AccountingSupplierParty><Party>
    <PartyIdentification><ID>12345678</ID></PartyIdentification>
    <PartyName><Name>Source Test Vendor</Name></PartyName>
  </Party></AccountingSupplierParty>
  <AccountingCustomerParty><Party>
    <PartyName><Name>Buyer</Name></PartyName>
    <PartyIdentification><ID>{$ic}</ID></PartyIdentification>
  </Party></AccountingCustomerParty>
  <InvoiceLines><InvoiceLine>
    <Item><Description>Test položka</Description></Item>
    <InvoicedQuantity unitCode="ks">1</InvoicedQuantity>
    <UnitPrice>100</UnitPrice>
    <ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory>
  </InvoiceLine></InvoiceLines>
</Invoice>
XML;
    }
}

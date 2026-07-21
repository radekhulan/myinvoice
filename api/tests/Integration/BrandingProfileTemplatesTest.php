<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BrandingProfileRepository;
use MyInvoice\Repository\EmailProfileRepository;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class BrandingProfileTemplatesTest extends TestCase
{
    private PDO $pdo;
    private BrandingProfileRepository $branding;
    private EmailProfileRepository $emailProfiles;
    private EmailTemplateRepository $templates;
    private SnapshotBuilder $snapshots;
    private InvoicePdfRenderer $pdf;
    private int $supplierId;
    private ?int $brandingId = null;
    private ?int $emailProfileId = null;
    private ?int $originalDefaultBrandingId = null;

    protected function setUp(): void
    {
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) $this->markTestSkipped('Container not available');
            $this->pdo = $container->get(Connection::class)->pdo();
            $this->branding = $container->get(BrandingProfileRepository::class);
            $this->emailProfiles = $container->get(EmailProfileRepository::class);
            $this->templates = $container->get(EmailTemplateRepository::class);
            $this->snapshots = $container->get(SnapshotBuilder::class);
            $this->pdf = $container->get(InvoicePdfRenderer::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
        $this->supplierId = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierId <= 0) $this->markTestSkipped('No supplier');
        $this->originalDefaultBrandingId = $this->branding->defaultForSupplier($this->supplierId)['id'] ?? null;
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        if ($this->originalDefaultBrandingId !== null) {
            $this->branding->setDefault($this->originalDefaultBrandingId, $this->supplierId);
        }
        if ($this->brandingId !== null) $this->branding->delete($this->brandingId, $this->supplierId);
        if ($this->emailProfileId !== null) $this->pdo->prepare('DELETE FROM email_profiles WHERE id = ?')->execute([$this->emailProfileId]);
    }

    public function testTemplateEmailOverrideSnapshotAndPdfPreview(): void
    {
        $this->emailProfileId = $this->emailProfiles->createProfile($this->supplierId, [
            'name' => 'Branding test sender', 'code' => 'branding_test_' . bin2hex(random_bytes(4)),
            'from_email' => 'branding@example.test', 'reply_to_email' => 'reply@example.test',
        ], null);
        $this->brandingId = $this->branding->create($this->supplierId, ['name' => 'Branding template test']);
        $this->branding->update($this->brandingId, $this->supplierId, ['email_profile_id' => $this->emailProfileId]);
        self::assertNotNull($this->branding->defaultForSupplier($this->supplierId));
        self::assertTrue($this->branding->setDefault($this->brandingId, $this->supplierId));
        self::assertTrue($this->branding->findForSupplier($this->brandingId, $this->supplierId)['is_default']);
        self::assertSame(['Branding template test'], $this->emailProfiles->brandingProfileUsages($this->supplierId, $this->emailProfileId));
        $html = '<html><body>{{ supplier.display_name|default(supplier.company_name) }} — {{ invoice.varsymbol }}</body></html>';
        $css = 'body { color: #123456; }';
        self::assertTrue($this->branding->saveInvoiceTemplate($this->brandingId, $this->supplierId, $html, $css));

        $this->templates->saveForBranding($this->brandingId, 'invoice_issued', 'cs', 'Brand subject', '<p>Brand</p>', 'Brand', null);
        self::assertSame('Brand subject', $this->templates->findForBranding($this->brandingId, 'invoice_issued', 'cs')['subject'] ?? null);
        self::assertNull($this->templates->findForBranding($this->brandingId, 'invoice_issued', 'en'));

        $clientId = (int) $this->pdo->query('SELECT MIN(id) FROM clients WHERE supplier_id = ' . $this->supplierId)->fetchColumn();
        $currencyId = (int) $this->pdo->query('SELECT MIN(id) FROM currencies WHERE supplier_id = ' . $this->supplierId)->fetchColumn();
        if ($clientId <= 0 || $currencyId <= 0) $this->markTestSkipped('No client or currency');
        $snapshot = $this->snapshots->build($clientId, $currencyId, $this->supplierId, $this->brandingId);
        self::assertSame($html, $snapshot['supplier']['invoice_template_html']);
        self::assertSame($css, $snapshot['supplier']['invoice_template_css']);
        self::assertSame($this->emailProfileId, $snapshot['supplier']['email_profile_id']);
        $this->branding->saveInvoiceTemplate($this->brandingId, $this->supplierId, '<p>Nová verze</p>', '');
        self::assertSame($html, $snapshot['supplier']['invoice_template_html'], 'Vystavený snapshot musí zůstat neměnný.');

        $pdf = $this->pdf->previewTemplate($html, $css, $this->supplierId);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertTrue($this->branding->resetInvoiceTemplate($this->brandingId, $this->supplierId));
        self::assertNull($this->branding->invoiceTemplate($this->brandingId, $this->supplierId)['html']);

        $this->expectException(\Twig\Sandbox\SecurityNotAllowedFunctionError::class);
        $this->pdf->previewTemplate('{{ source("/etc/passwd") }}', '', $this->supplierId);

    }
}

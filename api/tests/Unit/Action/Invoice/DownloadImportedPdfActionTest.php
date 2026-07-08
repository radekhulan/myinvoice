<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Invoice;

use MyInvoice\Action\Invoice\DownloadImportedPdfAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Chování GET /api/invoices/{id}/imported-pdf — servírování archivovaného zdrojového
 * PDF vydané faktury z importu. Final třídy (repo/logger/matcher) jsou mockované přes
 * dg/bypass-finals (viz tests/bootstrap.php); DB není potřeba — soubor je na tmp disku.
 */
final class DownloadImportedPdfActionTest extends TestCase
{
    private const SUPPLIER_ID = 1;
    private const REL_PATH = 'supplier-1/ab/abcdef1234567890.pdf';
    private const PDF_BYTES = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    private string $archiveRoot = '';

    protected function setUp(): void
    {
        $this->archiveRoot = sys_get_temp_dir() . '/mi-imported-pdf-test-' . getmypid() . '-' . uniqid();
        @mkdir($this->archiveRoot . '/supplier-1/ab', 0777, true);
        file_put_contents($this->archiveRoot . '/' . self::REL_PATH, self::PDF_BYTES);
    }

    protected function tearDown(): void
    {
        // Uklidit celý tmp strom.
        foreach (array_reverse(glob($this->archiveRoot . '/{,*/,*/*/}*', GLOB_BRACE) ?: []) as $p) {
            is_dir($p) ? @rmdir($p) : @unlink($p);
        }
        @rmdir($this->archiveRoot);
    }

    /** @param array<string,mixed>|null $invoiceRow */
    private function action(?array $invoiceRow): DownloadImportedPdfAction
    {
        $repo = $this->createStub(InvoiceRepository::class);
        $repo->method('find')->willReturn($invoiceRow);

        $config = new Config(['invoice' => ['import_archive_storage' => $this->archiveRoot]], null);

        $logger = $this->createStub(ActivityLogger::class);

        $ip = $this->createStub(IpMatcher::class);
        $ip->method('clientIpFromRequest')->willReturn('127.0.0.1');

        return new DownloadImportedPdfAction($repo, $config, $logger, $ip);
    }

    private function request(string $query = ''): \Psr\Http\Message\ServerRequestInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/invoices/854/imported-pdf' . ($query ? "?$query" : ''))
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, self::SUPPLIER_ID);
        if ($query !== '') {
            parse_str($query, $qp);
            $req = $req->withQueryParams($qp);
        }
        return $req;
    }

    private function ownedInvoice(array $overrides = []): array
    {
        return array_merge([
            'id'                         => 854,
            'supplier_id'                => self::SUPPLIER_ID,
            'varsymbol'                  => '20260013',
            'imported_pdf_path'          => self::REL_PATH,
            'imported_pdf_original_name' => '20260013.pdf',
            'imported_pdf_size_bytes'    => strlen(self::PDF_BYTES),
        ], $overrides);
    }

    public function testServesArchivedPdfAsAttachment(): void
    {
        $resp = ($this->action($this->ownedInvoice()))($this->request(), (new ResponseFactory())->createResponse(), ['id' => 854]);

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('application/pdf', $resp->getHeaderLine('Content-Type'));
        self::assertSame((string) strlen(self::PDF_BYTES), $resp->getHeaderLine('Content-Length'));
        self::assertSame(self::PDF_BYTES, (string) $resp->getBody());
        self::assertStringContainsString('attachment', $resp->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('20260013.pdf', $resp->getHeaderLine('Content-Disposition'));
        // Nosniff vždy; sandbox/frame-ancestors jen v inline režimu.
        self::assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
        self::assertFalse($resp->hasHeader('X-Frame-Options'), 'attachment režim nesmí nastavovat X-Frame-Options');
    }

    public function testInlineModeSetsFrameAncestorsForIframePreview(): void
    {
        $resp = ($this->action($this->ownedInvoice()))($this->request('inline=1'), (new ResponseFactory())->createResponse(), ['id' => 854]);

        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('inline', $resp->getHeaderLine('Content-Disposition'));
        self::assertSame("frame-ancestors 'self'", $resp->getHeaderLine('Content-Security-Policy'));
        self::assertSame('SAMEORIGIN', $resp->getHeaderLine('X-Frame-Options'));
    }

    public function testForcesPdfExtensionOnDownloadName(): void
    {
        $inv = $this->ownedInvoice(['imported_pdf_original_name' => 'uctenka.jpg']);
        $resp = ($this->action($inv))($this->request(), (new ResponseFactory())->createResponse(), ['id' => 854]);

        self::assertStringContainsString('uctenka.pdf', $resp->getHeaderLine('Content-Disposition'));
        self::assertStringNotContainsString('.jpg', $resp->getHeaderLine('Content-Disposition'));
    }

    public function testReturns404WhenInvoiceNotOwnedBySupplier(): void
    {
        $inv = $this->ownedInvoice(['supplier_id' => 999]);
        $resp = ($this->action($inv))($this->request(), (new ResponseFactory())->createResponse(), ['id' => 854]);

        self::assertSame(404, $resp->getStatusCode());
    }

    public function testReturns404WhenNoImportedPdf(): void
    {
        $inv = $this->ownedInvoice(['imported_pdf_path' => null]);
        $resp = ($this->action($inv))($this->request(), (new ResponseFactory())->createResponse(), ['id' => 854]);

        self::assertSame(404, $resp->getStatusCode());
        self::assertStringContainsString('no_pdf', (string) $resp->getBody());
    }

    public function testReturns404WhenFileMissingOnDisk(): void
    {
        $inv = $this->ownedInvoice(['imported_pdf_path' => 'supplier-1/zz/doesnotexist000.pdf']);
        $resp = ($this->action($inv))($this->request(), (new ResponseFactory())->createResponse(), ['id' => 854]);

        self::assertSame(404, $resp->getStatusCode());
    }

    public function testRejectsPathTraversalOutsideArchiveRoot(): void
    {
        // Soubor mimo archive root — cesta se přes ../ snaží utéct ven.
        $outside = $this->archiveRoot . '-secret.pdf';
        file_put_contents($outside, 'SECRET');
        try {
            $inv = $this->ownedInvoice(['imported_pdf_path' => '../' . basename($outside)]);
            $resp = ($this->action($inv))($this->request(), (new ResponseFactory())->createResponse(), ['id' => 854]);

            self::assertNotSame(200, $resp->getStatusCode(), 'traversal nesmí vrátit obsah souboru mimo root');
            self::assertStringNotContainsString('SECRET', (string) $resp->getBody());
        } finally {
            @unlink($outside);
        }
    }
}

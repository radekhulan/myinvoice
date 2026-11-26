<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class InvoiceReferenceValidationTest extends TestCase
{
    private Connection $db;
    private InvoiceDefaults $defaults;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(
            "CREATE TABLE supplier (id INTEGER, default_currency_id, default_payment_due_days, default_payment_due_unit);
             CREATE TABLE clients (id INTEGER, supplier_id INTEGER, language, currency_default_id, reverse_charge,
                                   payment_due_default, payment_due_unit);
             CREATE TABLE projects (id INTEGER, client_id INTEGER, currency_id, payment_due_days, payment_due_unit);
             CREATE TABLE currencies (id INTEGER, supplier_id INTEGER, code TEXT, is_default);
             CREATE TABLE revenue_categories (id INTEGER, supplier_id INTEGER);
             INSERT INTO supplier VALUES (1, 11, 14, 'days'), (2, 21, 7, 'days');
             INSERT INTO clients VALUES (12, 1, 'cs', 11, 0, NULL, NULL),
                                        (22, 2, 'en', 21, 0, NULL, NULL);
             INSERT INTO projects VALUES (13, 12, 11, NULL, NULL), (23, 22, 21, NULL, NULL);
             INSERT INTO currencies VALUES (11, 1, 'CZK', 1), (21, 2, 'CZK', 1);
             INSERT INTO revenue_categories VALUES (14, 1), (24, 2);"
        );

        $this->db = $this->createStub(Connection::class);
        $this->db->method('pdo')->willReturn($pdo);
        $this->defaults = new InvoiceDefaults($this->db);
    }

    public function testAcceptsOwnReferencesAndResolvesLegacyCurrencyInCurrentCompany(): void
    {
        $body = $this->defaults->resolve([
            'client_id' => 12,
            'project_id' => 13,
            'revenue_category_id' => 14,
            'currency' => 'CZK',
            'issue_date' => '2026-11-27',
        ], 1);

        self::assertSame(11, $body['currency_id']);
        self::assertSame('2026-12-11', $body['due_date']);
    }

    public static function invalidReferences(): iterable
    {
        yield 'invalid client' => ['client_id', 22];
        yield 'unknown client' => ['client_id', 99];
        yield 'invalid project' => ['project_id', 23];
        yield 'unknown project' => ['project_id', 99];
        yield 'invalid currency' => ['currency_id', 21];
        yield 'unknown currency' => ['currency_id', 99];
        yield 'invalid revenue category' => ['revenue_category_id', 24];
        yield 'unknown revenue category' => ['revenue_category_id', 99];
    }

    #[DataProvider('invalidReferences')]
    public function testRejectsInvalidReferencesWithoutIdentifyingTheirOwner(string $field, int $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Neplatný odkaz na související záznam faktury.');

        $this->defaults->resolve([$field => $id] + ['client_id' => 12], 1);
    }

    public static function invoiceStatuses(): iterable
    {
        yield 'draft' => ['draft'];
        yield 'issued' => ['issued'];
    }

    #[DataProvider('invoiceStatuses')]
    public function testCreateAndUpdateRejectInvalidReferencesBeforeWriting(string $status): void
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('find')->willReturn([
            'supplier_id' => 1,
            'status' => $status,
            'invoice_type' => 'invoice',
            'parent_invoice_id' => null,
        ]);
        $repo->expects(self::never())->method('createDraft');
        $repo->expects(self::never())->method('updateDraft');
        $repo->expects(self::never())->method('replaceItems');

        $calculator = $this->createStub(InvoiceCalculator::class);
        $logger = $this->createStub(ActivityLogger::class);
        $ipMatcher = $this->createStub(IpMatcher::class);
        $rateApplier = $this->createStub(ExchangeRateApplier::class);
        $vatDefaulter = $this->createStub(VatClassificationDefaulter::class);
        $create = new CreateInvoiceAction(
            $repo, $this->defaults, $calculator, $vatDefaulter, $logger, $ipMatcher, $rateApplier,
        );
        $varsymbol = $this->createMock(VarsymbolGenerator::class);
        $varsymbol->expects(self::never())->method('releaseIfLatest');
        $varsymbol->expects(self::never())->method('next');
        $update = new UpdateInvoiceAction(
            $repo, $this->defaults, $calculator, $logger, $ipMatcher,
            $this->createStub(StatsRecomputer::class), $rateApplier, $this->createStub(InvoicePdfRenderer::class),
            $vatDefaulter, $varsymbol, $this->db,
        );
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
            ->withParsedBody(['client_id' => 12, 'revenue_category_id' => 24, 'invoice_type' => 'proforma']);

        $createResponse = $create($request, new Response());
        $updateRequest = $request->withMethod('PUT')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'admin'])
            ->withQueryParams(['force' => '1']);
        $updateResponse = $update($updateRequest, new Response(), ['id' => 1]);

        self::assertSame(400, $createResponse->getStatusCode());
        self::assertSame(400, $updateResponse->getStatusCode());
        self::assertSame((string) $createResponse->getBody(), (string) $updateResponse->getBody());
        self::assertSame('integrity_violation', json_decode((string) $updateResponse->getBody(), true)['error']['code']);
    }
}

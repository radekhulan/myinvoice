<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BankIgnoreNoteTest extends TestCase
{
    public static function notes(): array
    {
        return [
            'trimmed note' => ['  Testovací poplatek  ', 'Testovací poplatek', 200],
            'empty note' => [" \n ", null, 200],
            'without note' => [null, null, 200],
            'unicode limit' => [str_repeat('ž', 1000), str_repeat('ž', 1000), 200],
            'too long' => [str_repeat('ž', 1001), null, 422],
            'array' => [['note'], null, 422],
            'number' => [123, null, 422],
        ];
    }

    #[DataProvider('notes')]
    public function testIgnorePersistsAndReturnsValidatedNote(mixed $note, ?string $expected, int $status): void
    {
        $sqlite = new PDO('sqlite::memory:');
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sqlite->exec('CREATE TABLE bank_transactions (id INTEGER, statement_id INTEGER, match_status TEXT, matched_invoice_id INTEGER, ignore_note TEXT)');
        $sqlite->exec("INSERT INTO bank_transactions VALUES (1, 2, 'unmatched', NULL, NULL)");
        $sqlite->exec('CREATE TABLE bank_statements (id INTEGER, matched_count INTEGER)');
        $sqlite->exec('INSERT INTO bank_statements VALUES (2, 0)');
        $sqlite->exec('CREATE TABLE activity_log (supplier_id, user_id, action, entity_type, entity_id, payload, ip, user_agent)');

        // MariaDB ownership SQL is covered separately; all writes execute against SQLite.
        $scope = $this->createStub(\PDOStatement::class);
        $scope->method('execute')->willReturn(true);
        $scope->method('fetchColumn')->willReturn(1);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($scope, $sqlite) {
            return str_contains($sql, 'SELECT bt.id') ? $scope : $sqlite->prepare($sql);
        });
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $reflection = new \ReflectionClass(BankStatementAction::class);
        $action = $reflection->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'logger' => new ActivityLogger($db), 'ipMatcher' => new IpMatcher()] as $name => $value) {
            $reflection->getProperty($name)->setValue($action, $value);
        }
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/bank-transactions/1/ignore')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withParsedBody($note === null ? [] : ['note' => $note]);
        $response = $action->ignore($request, new Response(), ['id' => 1]);
        self::assertSame($status, $response->getStatusCode());
        $row = $sqlite->query('SELECT * FROM bank_transactions')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($expected, $row['ignore_note']);
        self::assertSame($status === 200 ? 'ignored' : 'unmatched', $row['match_status']);
        if ($status === 200) {
            $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($result['ignored']);
            self::assertSame($expected, $result['ignore_note']);
            $audit = json_decode($sqlite->query('SELECT payload FROM activity_log')->fetchColumn(), true);
            self::assertSame($expected, $audit['note']);
        } else {
            self::assertSame(0, (int) $sqlite->query('SELECT COUNT(*) FROM activity_log')->fetchColumn());
        }
    }

    public function testUnmatchClearsPersistedIgnoreNote(): void
    {
        $sqlite = new PDO('sqlite::memory:');
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sqlite->exec('CREATE TABLE bank_transactions (id INTEGER, statement_id INTEGER, match_status TEXT, matched_invoice_id INTEGER, ignore_note TEXT, posted_at TEXT, matched_at TEXT, matched_by INTEGER)');
        $sqlite->exec("INSERT INTO bank_transactions VALUES (1, 2, 'ignored', NULL, 'Test note', '2099-01-01', NULL, NULL)");
        $sqlite->exec('CREATE TABLE bank_statements (id INTEGER, matched_count INTEGER)');
        $sqlite->exec('INSERT INTO bank_statements VALUES (2, 0)');
        $sqlite->exec('CREATE TABLE activity_log (supplier_id, user_id, action, entity_type, entity_id, payload, ip, user_agent)');

        $sqlite->exec('CREATE TABLE invoice_payments (id INTEGER, source TEXT, bank_transaction_id INTEGER, tax_document_invoice_id INTEGER)');
        $sqlite->exec('CREATE TABLE invoices (id INTEGER, status TEXT)');

        // MariaDB ownership SQL is covered separately; all writes execute against SQLite.
        $scope = $this->createStub(\PDOStatement::class);
        $scope->method('execute')->willReturn(true);
        $scope->method('fetchColumn')->willReturn(1);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($scope, $sqlite) {
            return (str_contains($sql, 'SELECT bt.id') || str_contains($sql, 'SELECT 1 FROM bank_statements bs')) ? $scope : $sqlite->prepare($sql);
        });
        $pdo->method('beginTransaction')->willReturnCallback(fn () => $sqlite->beginTransaction());
        $pdo->method('commit')->willReturnCallback(fn () => $sqlite->commit());
        $pdo->method('rollBack')->willReturnCallback(fn () => $sqlite->rollBack());
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $reflection = new \ReflectionClass(BankStatementAction::class);
        $action = $reflection->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'logger' => new ActivityLogger($db), 'ipMatcher' => new IpMatcher()] as $name => $value) {
            $reflection->getProperty($name)->setValue($action, $value);
        }
        $paymentsReflection = new \ReflectionClass(\MyInvoice\Service\Invoice\InvoicePaymentService::class);
        $payments = $paymentsReflection->newInstanceWithoutConstructor();
        $paymentsReflection->getProperty('db')->setValue($payments, $db);
        $reflection->getProperty('payments')->setValue($action, $payments);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/bank-transactions/1/unmatch')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7);
        $response = $action->unmatch($request, new Response(), ['id' => 1]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $sqlite->query('SELECT match_status, ignore_note FROM bank_transactions')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('unmatched', $row['match_status']);
        self::assertNull($row['ignore_note']);
    }
}

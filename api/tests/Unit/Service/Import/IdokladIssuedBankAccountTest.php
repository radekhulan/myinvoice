<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IdokladImportService;
use PHPUnit\Framework\TestCase;

final class IdokladIssuedBankAccountTest extends TestCase
{
    /** @return list<array{id:int,account_number:string,bank_code:string,iban:null}> */
    private static function accounts(): array
    {
        return [
            ['id' => 10, 'account_number' => '1000000005', 'bank_code' => '0100', 'iban' => null],
            ['id' => 20, 'account_number' => '1000000005', 'bank_code' => '2010', 'iban' => null],
        ];
    }

    public function testMatchesAccountAndBankCodeFromInvoiceAddress(): void
    {
        $doc = ['MyAddress' => ['AccountNumber' => '1000000005', 'BankCode' => '2010']];

        self::assertSame(20, IdokladImportService::matchIssuedBankAccount($doc, self::accounts()));
    }

    public function testNormalizesAccountNumberAndBankCodeFormatting(): void
    {
        $doc = ['MyAddress' => ['AccountNumber' => '0000001000000005', 'BankCode' => '/0100']];

        self::assertSame(10, IdokladImportService::matchIssuedBankAccount($doc, self::accounts()));
    }

    public function testDoesNotGuessWhenBankCodeIsMissingAndNumberIsAmbiguous(): void
    {
        $doc = ['MyAddress' => ['AccountNumber' => '1000000005', 'BankCode' => '']];

        self::assertNull(IdokladImportService::matchIssuedBankAccount($doc, self::accounts()));
    }

    public function testReturnsNullWhenInvoiceHasNoAccount(): void
    {
        self::assertNull(IdokladImportService::matchIssuedBankAccount(['MyAddress' => []], self::accounts()));
    }
}

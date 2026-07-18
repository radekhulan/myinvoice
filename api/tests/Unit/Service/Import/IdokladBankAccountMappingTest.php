<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IdokladImportService;
use PHPUnit\Framework\TestCase;

final class IdokladBankAccountMappingTest extends TestCase
{
    public function testMatchesSingleAccountByNumberAndIbanBankCode(): void
    {
        $external = [
            'AccountNumber' => '1000000005',
            'Iban' => 'CZ0001000000001000000005',
        ];
        $candidates = [
            ['id' => 10, 'account_number' => '1000000005', 'bank_code' => '0100', 'iban' => null],
            ['id' => 20, 'account_number' => '1000000005', 'bank_code' => '2010', 'iban' => null],
        ];

        self::assertSame(
            ['currency_id' => 10, 'status' => 'matched'],
            IdokladImportService::matchExternalBankAccount($external, $candidates),
        );
    }

    public function testReportsAmbiguousNumberWithoutBankIdentification(): void
    {
        $external = ['AccountNumber' => '1000000005', 'Iban' => ''];
        $candidates = [
            ['id' => 10, 'account_number' => '1000000005', 'bank_code' => '0100', 'iban' => null],
            ['id' => 20, 'account_number' => '1000000005', 'bank_code' => '2010', 'iban' => null],
        ];

        self::assertSame(
            ['currency_id' => null, 'status' => 'ambiguous'],
            IdokladImportService::matchExternalBankAccount($external, $candidates),
        );
    }

    public function testReportsUnmatchedAccount(): void
    {
        self::assertSame(
            ['currency_id' => null, 'status' => 'unmatched'],
            IdokladImportService::matchExternalBankAccount(
                ['AccountNumber' => '1000000005'],
                [['id' => 10, 'account_number' => '1000000006', 'bank_code' => '0100', 'iban' => null]],
            ),
        );
    }
}

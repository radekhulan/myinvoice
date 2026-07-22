<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\AccountNumberNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountNumberNormalizerTest extends TestCase
{
    #[DataProvider('normalizeCases')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, AccountNumberNormalizer::normalize($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function normalizeCases(): iterable
    {
        yield 'zero-padded GPC'         => ['0000000123456789', '123456789'];
        yield 'plain digits'            => ['123456789',        '123456789'];
        yield 'CZ prefix dash'          => ['19-2000145399',    '192000145399'];
        yield 'spaces'                  => ['1 000 000 005',    '1000000005'];
        yield 'prefix + zero padding'   => ['0000019-2000145399', '192000145399'];
        yield 'leading zeros only'      => ['0000000000',       ''];
        yield 'empty'                   => ['',                  ''];
        yield 'IBAN style stripped'     => ['CZ6508000000192000145399', '6508000000192000145399'];
    }

    public function testEqualsZeroPaddedVsPlain(): void
    {
        self::assertTrue(AccountNumberNormalizer::equals('0000000123456789', '123456789'));
        self::assertTrue(AccountNumberNormalizer::equals('123456789', '0000000123456789'));
    }

    public function testEqualsDifferentAccounts(): void
    {
        self::assertFalse(AccountNumberNormalizer::equals('1000000005', '1000000006'));
    }

    public function testEqualsPrefixVsBase(): void
    {
        // Note: prefixed account `19-1000000005` normalizes to `191000000005`,
        // a different value than `1000000005`. So they are NOT considered same.
        self::assertFalse(AccountNumberNormalizer::equals('19-1000000005', '1000000005'));
    }

    public function testEqualsEmptyEmpty(): void
    {
        self::assertTrue(AccountNumberNormalizer::equals('', ''));
        self::assertTrue(AccountNumberNormalizer::equals('0000', ''));
    }

    public function testEqualsMonetaMaskedAccount(): void
    {
        // Moneta Info Servis: „Účet: 238***891" vs. plné číslo stejné délky.
        self::assertTrue(AccountNumberNormalizer::equals('238***891', '238456891'));
        self::assertTrue(AccountNumberNormalizer::equals('238456891', '238***891'));
        self::assertTrue(AccountNumberNormalizer::equals('238***891/0600', '238456891'));
        self::assertTrue(AccountNumberNormalizer::equals('238***891', '238456891/0600'));
        self::assertFalse(AccountNumberNormalizer::equals('238***891', '239456891'));
        self::assertFalse(AccountNumberNormalizer::equals('238***891', '2384567891')); // jiná délka
        self::assertFalse(AccountNumberNormalizer::equals('238***891', '238***892')); // dvě masky
    }

    public function testMatchesAnyViaMonetaMaskedAccount(): void
    {
        self::assertTrue(AccountNumberNormalizer::matchesAny('238***891', '238456891', null));
        self::assertFalse(AccountNumberNormalizer::matchesAny('238***891', '239456891', null));
    }

    // ── IBAN podpora (#109 — EUR účty evidované jen IBANem vs GPC výpis) ──

    #[DataProvider('ibanAccountPartCases')]
    public function testCzechIbanAccountPart(string $iban, ?string $expected): void
    {
        self::assertSame($expected, AccountNumberNormalizer::czechIbanAccountPart($iban));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function ibanAccountPartCases(): iterable
    {
        yield 'CZ IBAN compact'   => ['CZ6508000000192000145399', '0000192000145399'];
        yield 'CZ IBAN spaces'    => ['CZ65 0800 0000 1920 0014 5399', '0000192000145399'];
        yield 'CZ IBAN lowercase' => ['cz6508000000192000145399', '0000192000145399'];
        yield 'non-CZ IBAN'       => ['DE89370400440532013000', null];
        yield 'plain account'     => ['192000145399', null];
        yield 'too short'         => ['CZ650800019200014539', null];
        yield 'empty'             => ['', null];
    }

    public function testCzechIbanBankCode(): void
    {
        self::assertSame('0800', AccountNumberNormalizer::czechIbanBankCode('CZ6508000000192000145399'));
        self::assertSame('0800', AccountNumberNormalizer::czechIbanBankCode('CZ65 0800 0000 1920 0014 5399'));
        self::assertNull(AccountNumberNormalizer::czechIbanBankCode('DE89370400440532013000'));
        self::assertNull(AccountNumberNormalizer::czechIbanBankCode(''));
    }

    public function testMatchesAnyViaAccountNumber(): void
    {
        self::assertTrue(AccountNumberNormalizer::matchesAny('0000192000145399', '19-2000145399', null));
    }

    public function testMatchesAnyViaIbanOnly(): void
    {
        // EUR účet evidovaný jen IBANem — GPC výpis nese domácí číslo (#109).
        self::assertTrue(AccountNumberNormalizer::matchesAny('0000192000145399', null, 'CZ6508000000192000145399'));
        self::assertTrue(AccountNumberNormalizer::matchesAny('192000145399', '', 'CZ65 0800 0000 1920 0014 5399'));
    }

    public function testMatchesAnyIbanPastedIntoAccountNumberField(): void
    {
        self::assertTrue(AccountNumberNormalizer::matchesAny('0000192000145399', 'CZ6508000000192000145399', null));
    }

    public function testMatchesAnyRejectsDifferentAccount(): void
    {
        self::assertFalse(AccountNumberNormalizer::matchesAny('1000000005', '1000000006', 'CZ6508000000192000145399'));
        self::assertFalse(AccountNumberNormalizer::matchesAny('1000000005', null, null));
    }
}

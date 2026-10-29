<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\EmailNotice\Parser\RegexBankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

/**
 * Regrese k #291: whitelist `fio.cz` (opsaný ze systémového Fio providera) prošel
 * v Testu parseru, kde se jako odesílatel zadalo přímo `fio.cz`, ale při scanu
 * IMAP s odesílatelem `automat@fio.cz` nesedl a avízo skončilo na parse_failed.
 */
final class RegexBankEmailNoticeParserSenderWhitelistTest extends TestCase
{
    private const BODY = <<<TEXT
    the balance of account 2901618111 has increased by CZK 1.00 on 10/25/2026 at 10:37.

    Variable symbol: 123456
    TEXT;

    private function provider(?string $whitelist): BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: 2,
            supplierId: null,
            providerRef: 'db:2',
            code: 'fio-en',
            name: 'Fio banka - new income on account',
            parserType: 'regex',
            enabled: true,
            senderWhitelist: $whitelist,
            subjectPattern: 'Fio\s+banka\s+-\s+new\s+income\s+on\s+account',
            bodyPattern: 'has\s+increased\s+by',
            fieldPatterns: [
                'variable_symbol' => 'Variable\s+symbol:\s*(\d+)',
                'amount' => 'increased\s+by\s+[A-Z]{3}\s*([0-9][0-9.,]*)',
                'currency' => 'increased\s+by\s+([A-Z]{3})',
                'posted_at' => '\bon\s+(\d{1,2}/\d{1,2}/\d{4})\s+at',
                'recipient_account' => 'balance\s+of\s+account\s+([0-9-]+)',
            ],
            normalizerConfig: [],
            system: false,
        );
    }

    private function message(string $sender): BankEmailNoticeMessage
    {
        return new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sample@fio.cz>',
            date: new \DateTimeImmutable('2026-10-25 10:37:00'),
            sender: $sender,
            subject: 'Fio banka - new income on account',
            text: self::BODY,
            raw: self::BODY,
        );
    }

    public function testDomainWhitelistEntryMatchesSubdomainsButNotSuffixTricks(): void
    {
        $parser = new RegexBankEmailNoticeParser();
        $provider = $this->provider('fio.cz');

        self::assertTrue($parser->supports($this->message('automat@fio.cz'), $provider));
        self::assertTrue($parser->supports($this->message('Fio banka <automat@fio.cz>'), $provider));
        self::assertTrue($parser->supports($this->message('noreply@mail.fio.cz'), $provider));
        self::assertFalse($parser->supports($this->message('automat@fio.cz.evil.example'), $provider));
        self::assertFalse($parser->supports($this->message('automat@evil.example'), $provider));
    }

    public function testExactAddressWhitelistMatchesOnlyThatSender(): void
    {
        $parser = new RegexBankEmailNoticeParser();
        $provider = $this->provider('automat@fio.cz');

        self::assertTrue($parser->supports($this->message('automat@fio.cz'), $provider));
        self::assertTrue($parser->supports($this->message('Fio banka <automat@fio.cz>'), $provider));
        self::assertFalse($parser->supports($this->message('noreply@fio.cz'), $provider));
        self::assertFalse($parser->supports($this->message('automat@fio.cz.evil.example'), $provider));
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\EmailNotice\Parser\FioBankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

final class FioBankEmailNoticeParserTest extends TestCase
{
    public function testParsesIncomingNotice(): void
    {
        $body = <<<TEXT
Příjem na kontě: 1234560005
Částka: 3 898,00
VS: 26006439
Zpráva příjemci: test potvrzeni
Aktuální zůstatek: 168 459,32
Protiúčet: 7654320005/0800
SS:
KS:
TEXT;

        $parser = new FioBankEmailNoticeParser();
        $message = $this->message($body, 'Fio banka - prijem na konte');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('26006439', $parsed->variableSymbol);
        self::assertSame(3898.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-06-10', $parsed->postedAt);
        self::assertSame('1234560005/2010', $parsed->recipientAccount);
        self::assertSame('7654320005', $parsed->counterpartyAccount);
        self::assertSame('0800', $parsed->counterpartyBank);
        self::assertSame('test potvrzeni', $parsed->message);
        self::assertNull($parsed->constantSymbol);
        self::assertSame(168459.32, $parsed->balance);
    }

    public function testParsesOutgoingNoticeAsNegativeAmount(): void
    {
        $body = <<<TEXT
Výdaj na kontě: 1234560005
Částka: 5 000,00
VS: 08111111
US: Najem skladovych prostor
Aktuální zůstatek: 17 449,23
Protiúčet: 7654320005/3030
SS:
KS: 0
TEXT;

        $parser = new FioBankEmailNoticeParser();
        $message = $this->message($body, 'Fio banka - vydaj na konte');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('8111111', $parsed->variableSymbol);
        self::assertSame(-5000.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('1234560005/2010', $parsed->recipientAccount);
        self::assertSame('7654320005', $parsed->counterpartyAccount);
        self::assertSame('3030', $parsed->counterpartyBank);
        self::assertSame('Najem skladovych prostor', $parsed->message);
        self::assertSame('0', $parsed->constantSymbol);
        self::assertSame(17449.23, $parsed->balance);
    }

    public function testParsesNoticeWithExplicitCurrencyAndWithoutSymbols(): void
    {
        $body = <<<TEXT
Příjem na kontě: 1234560005
Částka: 1 250,00 EUR
Zpráva příjemci: invoice 2026001
Aktuální zůstatek: 2 500,00
Protiúčet: 7654320005/0100
SS:
KS:
TEXT;

        $parser = new FioBankEmailNoticeParser();
        $parsed = $parser->parse($this->message($body, 'Fio banka - prijem na konte'), $this->provider($parser));

        self::assertSame('', $parsed->variableSymbol);
        self::assertSame(1250.0, $parsed->amount);
        self::assertSame('EUR', $parsed->currency);
        self::assertNull($parsed->constantSymbol);
    }

    public function testRejectsSpoofedSenderDomain(): void
    {
        $parser = new FioBankEmailNoticeParser();
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<spoof@evil.com>',
            date: new \DateTimeImmutable('2026-06-10 23:35:22'),
            sender: 'Fio banka <attacker@fio.cz.evil.com>',
            subject: 'Fio banka - prijem na konte',
            text: "Příjem na kontě: 1234560005\nČástka: 1,00",
            raw: '',
        );
        self::assertFalse($parser->supports($message, $this->provider($parser)));
    }

    public function testThrowsWithoutMessageDate(): void
    {
        $body = "Příjem na kontě: 1234560005\nČástka: 1,00\nVS: 123";
        $parser = new FioBankEmailNoticeParser();
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sanitized-sample@fio.cz>',
            date: null,
            sender: 'automat@fio.cz',
            subject: 'Fio banka - prijem na konte',
            text: $body,
            raw: $body,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('datum');
        $parser->parse($message, $this->provider($parser));
    }

    public function testParsesProseIncomingNotice(): void
    {
        $body = <<<TEXT
Dobrý den,

zůstatek účtu 2901618111 se 24.08.2026 v 12:43 zvýšil o 10,00 CZK.

Další parametry
Typ pohybu: Okamžitá příchozí platba
Protistrana: 7654320005/0800 (ing. Novák)
ID pokynu: 4711
Variabilní symbol: 26006439
Konstantní symbol: 0308
Uživatelský symbol: xxx
Zpráva pro příjemce: Faktura 26006439

Aktuální zůstatek: 1 234,75 CZK

Fio banka
TEXT;

        $parser = new FioBankEmailNoticeParser();
        $message = $this->message($body, 'Fio banka - zmena zustatku');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('26006439', $parsed->variableSymbol);
        self::assertSame(10.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-08-24', $parsed->postedAt);
        self::assertSame('2901618111/2010', $parsed->recipientAccount);
        self::assertSame('7654320005', $parsed->counterpartyAccount);
        self::assertSame('0800', $parsed->counterpartyBank);
        self::assertSame('ing. Novák', $parsed->counterpartyName);
        self::assertSame('0308', $parsed->constantSymbol);
        self::assertSame('Faktura 26006439', $parsed->message);
        self::assertSame('4711', $parsed->bankRef);
        self::assertSame(1234.75, $parsed->balance);
    }

    public function testParsesProseOutgoingNoticeAsNegativeAmount(): void
    {
        $body = <<<TEXT
zůstatek účtu 2901618111 se 1. 9. 2026 v 8:05 snížil o 5 000,00 CZK.

Další parametry
Typ pohybu: Odchozí platba
Protistrana: 7654320005/3030
Variabilní symbol: 08111111
Uživatelský symbol: Najem skladovych prostor

Aktuální zůstatek: 17 449,23 CZK
TEXT;

        $parser = new FioBankEmailNoticeParser();
        $message = $this->message($body, 'Fio banka - zmena zustatku');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('8111111', $parsed->variableSymbol);
        self::assertSame(-5000.0, $parsed->amount);
        self::assertSame('2026-09-01', $parsed->postedAt);
        self::assertSame('7654320005', $parsed->counterpartyAccount);
        self::assertSame('3030', $parsed->counterpartyBank);
        self::assertNull($parsed->counterpartyName);
        self::assertNull($parsed->constantSymbol);
        self::assertSame('Najem skladovych prostor', $parsed->message);
        self::assertSame(17449.23, $parsed->balance);
    }

    public function testProseNoticeRejectsSpoofedSenderDomain(): void
    {
        $parser = new FioBankEmailNoticeParser();
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<spoof@evil.com>',
            date: new \DateTimeImmutable('2026-08-24 12:43:00'),
            sender: 'Fio banka <attacker@fio.cz.evil.com>',
            subject: 'Fio banka',
            text: "zůstatek účtu 2901618111 se 24.08.2026 v 12:43 zvýšil o 10,00 CZK.",
            raw: '',
        );
        self::assertFalse($parser->supports($message, $this->provider($parser)));
    }

    public function testParsesEnglishProseIncomingNotice(): void
    {
        $body = <<<TEXT
Dear Client,

the balance of account 2901618111 has increased by CZK 1.00 on 10/25/2026 at 10:37.

Additional details
Transaction type: Credit transfers within the bank
Counterparty: 1234567890/2010 (Novák, Jan)
Instruction ID: 4711
Variable symbol: 123456
Message for beneficiary: Faktura 123456

Current balance: CZK 1,234.75

If you no longer wish to receive this information, you can disable it anytime in the settings of our applications.

Fio banka
TEXT;

        $parser = new FioBankEmailNoticeParser();
        $message = $this->message($body, 'Fio banka - new income on account');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('123456', $parsed->variableSymbol);
        self::assertSame(1.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-10-25', $parsed->postedAt);
        self::assertSame('2901618111/2010', $parsed->recipientAccount);
        self::assertSame('1234567890', $parsed->counterpartyAccount);
        self::assertSame('2010', $parsed->counterpartyBank);
        self::assertSame('Novák, Jan', $parsed->counterpartyName);
        self::assertNull($parsed->constantSymbol);
        self::assertSame('Faktura 123456', $parsed->message);
        self::assertSame('4711', $parsed->bankRef);
        self::assertSame(1234.75, $parsed->balance);
    }

    public function testParsesEnglishProseOutgoingNoticeAsNegativeAmount(): void
    {
        $body = <<<TEXT
the balance of account 2901618111 has decreased by EUR 5,000.00 on 9/1/2026 at 8:05.

Additional details
Counterparty: 7654320005/3030
Variable symbol: 08111111
Constant symbol: 0308

Current balance: EUR 17,449.23
TEXT;

        $parser = new FioBankEmailNoticeParser();
        $message = $this->message($body, 'Fio banka - new expense on account');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('8111111', $parsed->variableSymbol);
        self::assertSame(-5000.0, $parsed->amount);
        self::assertSame('EUR', $parsed->currency);
        self::assertSame('2026-09-01', $parsed->postedAt);
        self::assertSame('7654320005', $parsed->counterpartyAccount);
        self::assertSame('3030', $parsed->counterpartyBank);
        self::assertNull($parsed->counterpartyName);
        self::assertSame('0308', $parsed->constantSymbol);
        self::assertNull($parsed->message);
        self::assertSame(17449.23, $parsed->balance);
    }

    public function testEnglishProseNoticeRejectsDayMonthSwappedDate(): void
    {
        $parser = new FioBankEmailNoticeParser();
        $message = $this->message(
            'the balance of account 2901618111 has increased by CZK 1.00 on 25/10/2026 at 10:37.',
            'Fio banka - new income on account',
        );

        $this->expectException(\RuntimeException::class);
        $parser->parse($message, $this->provider($parser));
    }

    private function message(string $body, string $subject): BankEmailNoticeMessage
    {
        return new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sanitized-sample@fio.cz>',
            date: new \DateTimeImmutable('2026-06-10 23:35:22'),
            sender: 'automat@fio.cz',
            subject: $subject,
            text: $body,
            raw: $body,
        );
    }

    private function provider(FioBankEmailNoticeParser $parser): BankEmailNoticeProvider
    {
        $provider = $parser->defaultProvider();
        self::assertInstanceOf(BankEmailNoticeProvider::class, $provider);
        return $provider;
    }
}

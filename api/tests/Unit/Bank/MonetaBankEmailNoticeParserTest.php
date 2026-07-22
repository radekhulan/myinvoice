<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\EmailNotice\Parser\MonetaBankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

final class MonetaBankEmailNoticeParserTest extends TestCase
{
    public function testParsesIncomingNoticeWithMaskedAccount(): void
    {
        $body = <<<TEXT
Dobrý den,
zasíláme Vám nové upozornění. Na tento e-mail prosím neodpovídejte, jedná se o automaticky generovaný e-mail.
Přišly peníze

Účet:
238***891

Datum:
01.07.2026

Částka:
35 000,00 Kč

Popis:
OKAMŽITÁ ÚHRADA, DOM-AVIZO:202600009 FIRMA DEMO S.R.O., VS: 202600009

Od:
264555317/0300

Disponibilní zůstatek:
64 831,42 Kč

Účetní zůstatek:
64 831,42 Kč
TEXT;

        $parser = new MonetaBankEmailNoticeParser();
        $message = $this->message($body, 'Přišly peníze');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('202600009', $parsed->variableSymbol);
        self::assertSame(35000.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-07-01', $parsed->postedAt);
        self::assertSame('238***891/0600', $parsed->recipientAccount);
        self::assertSame('264555317', $parsed->counterpartyAccount);
        self::assertSame('0300', $parsed->counterpartyBank);
        self::assertSame('OKAMŽITÁ ÚHRADA, DOM-AVIZO:202600009 FIRMA DEMO S.R.O., VS: 202600009', $parsed->message);
        self::assertSame(64831.42, $parsed->balance);
    }

    public function testParsesOutgoingNoticeAsNegativeAmount(): void
    {
        $body = <<<TEXT
Odešly peníze

Účet:
238***891

Datum:
15.07.2026

Částka:
1 250,50 Kč

Popis:
Platba faktury VS: 2607001

Od:
123456789/0100

Disponibilní zůstatek:
10 000,00 Kč
TEXT;

        $parser = new MonetaBankEmailNoticeParser();
        $message = $this->message($body, 'Odešly peníze');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('2607001', $parsed->variableSymbol);
        self::assertSame(-1250.50, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-07-15', $parsed->postedAt);
        self::assertSame('238***891/0600', $parsed->recipientAccount);
        self::assertSame('123456789', $parsed->counterpartyAccount);
        self::assertSame('0100', $parsed->counterpartyBank);
    }

    public function testSupportsDiacriticFoldedSubjectAndBody(): void
    {
        $body = <<<TEXT
Prisly penize
Ucet: 238***891
Datum: 01.07.2026
Castka: 100,00 Kc
Popis: VS: 1
Od: 1000000005/0100
Disponibilni zustatek: 1,00 Kc
TEXT;

        $parser = new MonetaBankEmailNoticeParser();
        $message = $this->message($body, 'Prisly penize');
        self::assertTrue($parser->supports($message, $this->provider($parser)));
    }

    public function testRejectsSpoofedSenderDomain(): void
    {
        $parser = new MonetaBankEmailNoticeParser();
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<spoof@evil.com>',
            date: new \DateTimeImmutable('2026-07-01 12:49:00'),
            sender: 'MONETA <attacker@moneta.cz.evil.com>',
            subject: 'Přišly peníze',
            text: "Přišly peníze\nÚčet: 238***891\nČástka: 1,00 Kč",
            raw: '',
        );
        self::assertFalse($parser->supports($message, $this->provider($parser)));
    }

    public function testThrowsWithoutAmount(): void
    {
        $body = "Přišly peníze\nÚčet: 238***891\nDatum: 01.07.2026\nPopis: VS: 1";
        $parser = new MonetaBankEmailNoticeParser();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('částku');
        $parser->parse($this->message($body, 'Přišly peníze'), $this->provider($parser));
    }

    private function message(string $body, string $subject): BankEmailNoticeMessage
    {
        return new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sanitized-sample@moneta.cz>',
            date: new \DateTimeImmutable('2026-07-01 12:49:00'),
            sender: 'infoservis@moneta.cz',
            subject: $subject,
            text: $body,
            raw: $body,
        );
    }

    private function provider(MonetaBankEmailNoticeParser $parser): BankEmailNoticeProvider
    {
        $provider = $parser->defaultProvider();
        self::assertInstanceOf(BankEmailNoticeProvider::class, $provider);
        return $provider;
    }
}

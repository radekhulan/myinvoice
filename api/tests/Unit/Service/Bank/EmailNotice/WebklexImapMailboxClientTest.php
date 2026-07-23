<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\EmailNotice;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use MyInvoice\Service\Bank\EmailNotice\WebklexImapMailboxClient;
use PHPUnit\Framework\TestCase;

final class WebklexImapMailboxClientTest extends TestCase
{
    /**
     * Regrese: ve Webklexu je getUid() magická metoda (jen @method + __call), takže
     * method_exists($msg, 'getUid') === false. Guard `method_exists(...) ? ... : null`
     * proto zapisoval uid = null pro každou zprávu → postProcess se hned vrátil a
     * „označit jako přečtené" (a add_flag/move) se nikdy nespustilo.
     */
    public function testUidIsReadFromMagicGetUidAccessor(): void
    {
        $message = new MagicWebklexMessageStub([
            'getUid' => 42,
            'getMessageId' => '<mid@bank.cz>',
            'getFrom' => 'noreply@bank.cz',
            'getSubject' => 'Avízo o platbě',
            'getDate' => '2026-01-15 10:00:00',
            'getHTMLBody' => '',
            'getTextBody' => 'Přijatá platba 1000 CZK',
        ]);

        // Přesně vlastnost, která bug způsobila: magickou getUid() method_exists nevidí.
        self::assertFalse(method_exists($message, 'getUid'));

        $client = new WebklexImapMailboxClient(new EmailNoticeTextNormalizer());
        $toNoticeMessage = new \ReflectionMethod(WebklexImapMailboxClient::class, 'toNoticeMessage');

        /** @var BankEmailNoticeMessage $notice */
        $notice = $toNoticeMessage->invoke($client, $message, ['allow_forwarded' => false, 'forwarded_from' => '']);

        self::assertSame(42, $notice->uid);
        self::assertSame('<mid@bank.cz>', $notice->messageId);
    }
}

/**
 * Napodobuje Webklex\PHPIMAP\Message: accessory (getUid, getFrom, …) existují jen
 * přes magické __call, takže method_exists() je na ně false — přesně jako v knihovně.
 */
final class MagicWebklexMessageStub
{
    /** @param array<string,mixed> $values */
    public function __construct(private readonly array $values) {}

    public function __call(string $method, array $arguments): mixed
    {
        return $this->values[$method] ?? '';
    }
}

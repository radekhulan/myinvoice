<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Admin;

use MyInvoice\Action\Admin\ListSentEmailsAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Normalizace příjemců z activity_log payloadu. Klíč i typ se mezi e-mailovými
 * akcemi liší: `to` je string (recurring single recipient) | pole (invoice.sent,
 * upomínky), `recipients` je pole (payment_thanks). extractRecipients to musí
 * sjednotit do plochého pole neprázdných, otrimovaných adres.
 *
 * Čistě unit — metoda nesahá na DB, takže instancujeme bez konstruktoru.
 */
final class ListSentEmailsRecipientsTest extends TestCase
{
    /**
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    private function extract(array $payload): array
    {
        $action = (new ReflectionClass(ListSentEmailsAction::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($action, 'extractRecipients');
        /** @var list<string> $r */
        $r = $m->invoke($action, $payload);
        return $r;
    }

    public function testToAsArray(): void
    {
        self::assertSame(['a@b.cz', 'c@d.cz'], $this->extract(['to' => ['a@b.cz', 'c@d.cz']]));
    }

    public function testToAsSingleString(): void
    {
        // recurring.reminder_sent loguje `to` jako string.
        self::assertSame(['solo@example.cz'], $this->extract(['to' => 'solo@example.cz']));
    }

    public function testRecipientsKeyUsedWhenToAbsent(): void
    {
        // invoice.payment_thanks_sent používá `recipients`.
        self::assertSame(['pay@example.cz'], $this->extract(['recipients' => ['pay@example.cz']]));
    }

    public function testToTakesPrecedenceOverRecipients(): void
    {
        self::assertSame(['from-to@x.cz'], $this->extract([
            'to'         => ['from-to@x.cz'],
            'recipients' => ['from-recipients@x.cz'],
        ]));
    }

    public function testTrimsAndDropsEmptyEntries(): void
    {
        self::assertSame(['a@b.cz', 'c@d.cz'], $this->extract(['to' => ['  a@b.cz ', '', '   ', 'c@d.cz']]));
    }

    public function testDropsNonStringEntries(): void
    {
        // Reindexuje (list), nesmí nechat díry po vyhozených ne-string položkách.
        self::assertSame(['ok@x.cz'], $this->extract(['to' => ['ok@x.cz', 42, ['nested' => 'y@z.cz'], null]]));
    }

    public function testEmptyWhenNoRecipientKeys(): void
    {
        self::assertSame([], $this->extract(['varsymbol' => 'X1', 'smtp_response' => '250 OK']));
    }

    public function testEmptyPayload(): void
    {
        self::assertSame([], $this->extract([]));
    }

    public function testNonArrayNonStringRawYieldsEmpty(): void
    {
        // `to` jako skalár jiný než string (číslo) → žádný platný příjemce.
        self::assertSame([], $this->extract(['to' => 123]));
    }
}

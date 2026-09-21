<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\ImportedPaymentStateMapper;
use PHPUnit\Framework\TestCase;

/**
 * Issue #121 — import z Fakturoidu/iDokladu neoznačil zaplacené doklady jako
 * zaplacené → visely jako pohledávky a cron-send-reminders na ně upomínal.
 */
final class ImportedPaymentStateMapperTest extends TestCase
{
    // ── Fakturoid ──────────────────────────────────────────────────────

    public function testFakturoidPaidWithPaidOn(): void
    {
        $state = ImportedPaymentStateMapper::fromFakturoid([
            'status'  => 'paid',
            'paid_on' => '2024-03-15',
        ]);
        self::assertSame(['status' => 'paid', 'paid_at' => '2024-03-15'], $state);
    }

    public function testFakturoidPaidWithoutPaidOnFallsBackToNull(): void
    {
        // paid_on chybí/null → paid_at null, fallback (tax/issue date) doplní volající
        $state = ImportedPaymentStateMapper::fromFakturoid(['status' => 'paid', 'paid_on' => null]);
        self::assertSame(['status' => 'paid', 'paid_at' => null], $state);
    }

    public function testFakturoidCancelled(): void
    {
        $state = ImportedPaymentStateMapper::fromFakturoid(['status' => 'cancelled']);
        self::assertSame(['status' => 'cancelled', 'paid_at' => null], $state);
    }

    /** Otevřené/po splatnosti/nedobytné doklady nemají platební stav (import je vystaví bez upomínek, #250). */
    public function testFakturoidOpenStatesHaveNoPaymentState(): void
    {
        foreach (['open', 'sent', 'overdue', 'uncollectible', '', 'unknown'] as $status) {
            self::assertNull(
                ImportedPaymentStateMapper::fromFakturoid(['status' => $status]),
                "status '{$status}' nesmí měnit stav dokladu",
            );
        }
        self::assertNull(ImportedPaymentStateMapper::fromFakturoid([]));
    }

    /** Fakturoid datetime tvar (ISO 8601) se ořeže na Y-m-d. */
    public function testFakturoidPaidOnDatetimeIsTrimmedToDate(): void
    {
        $state = ImportedPaymentStateMapper::fromFakturoid([
            'status'  => 'paid',
            'paid_on' => '2024-03-15T10:30:00+01:00',
        ]);
        self::assertSame('2024-03-15', $state['paid_at']);
    }

    // ── iDoklad ────────────────────────────────────────────────────────

    public function testIdokladPaid(): void
    {
        $state = ImportedPaymentStateMapper::fromIdoklad([
            'PaymentStatus' => 1,
            'DateOfPayment' => '2023-05-01T00:00:00',
        ]);
        self::assertSame(['status' => 'paid', 'paid_at' => '2023-05-01'], $state);
    }

    public function testIdokladOverpaidCountsAsPaid(): void
    {
        $state = ImportedPaymentStateMapper::fromIdoklad(['PaymentStatus' => 3]);
        self::assertSame('paid', $state['status'] ?? null);
    }

    public function testIdokladUnpaidAndPartialHaveNoPaymentState(): void
    {
        self::assertNull(ImportedPaymentStateMapper::fromIdoklad(['PaymentStatus' => 0]));
        self::assertNull(ImportedPaymentStateMapper::fromIdoklad(['PaymentStatus' => 2]));
        self::assertNull(ImportedPaymentStateMapper::fromIdoklad([]));
    }

    /** C# default DateTime '0001-01-01…' (neuhrazený doklad v list modelu) → null. */
    public function testIdokladCsharpDefaultDateOfPaymentIsRejected(): void
    {
        $state = ImportedPaymentStateMapper::fromIdoklad([
            'PaymentStatus' => 1,
            'DateOfPayment' => '0001-01-01T00:00:00',
        ]);
        self::assertSame(['status' => 'paid', 'paid_at' => null], $state);
    }

    // ── normalizeDate ──────────────────────────────────────────────────

    public function testNormalizeDateRejectsGarbage(): void
    {
        self::assertNull(ImportedPaymentStateMapper::normalizeDate(null));
        self::assertNull(ImportedPaymentStateMapper::normalizeDate(''));
        self::assertNull(ImportedPaymentStateMapper::normalizeDate('not-a-date'));
        self::assertNull(ImportedPaymentStateMapper::normalizeDate('0000-00-00'));
        self::assertNull(ImportedPaymentStateMapper::normalizeDate('2024-13-45'));
        self::assertNull(ImportedPaymentStateMapper::normalizeDate(20240315));
        self::assertSame('2024-02-29', ImportedPaymentStateMapper::normalizeDate('2024-02-29 08:00:00'));
    }
}

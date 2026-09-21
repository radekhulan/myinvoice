<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Mapování platebního stavu zdrojového dokladu (Fakturoid / iDoklad) na náš
 * status — issue #121: importované faktury s evidovanou platbou zůstávaly
 * "nezaplaceno" a cron-send-reminders na ně posílal klientům upomínky.
 *
 * Vrací ?array{status: 'paid'|'cancelled', paid_at: ?string}:
 *   - 'paid'      → doklad ve zdroji uhrazen (paid_at = datum úhrady, pokud ho zdroj má)
 *   - 'cancelled' → doklad ve zdroji stornován (jen Fakturoid; iDoklad storno stav nemá)
 *   - null        → otevřený doklad (open/sent/overdue/partial/uncollectible). Vydaný
 *                   doklad importy zakládají jako 'issued' s vypnutými automatickými
 *                   upomínkami (#250) — povýšení na 'sent' nebo zapnuté upomínky by
 *                   u historických pohledávek spustily hromadné upomínky klientům (#121).
 *                   Přijatý doklad zůstává draft.
 */
final class ImportedPaymentStateMapper
{
    /**
     * Fakturoid v3: invoice.status ∈ {open, sent, overdue, paid, cancelled, uncollectible},
     * expense.status ∈ {open, overdue, paid}; paid_on = datum označení jako zaplaceno.
     * Částečné platby nechávají status 'open' → otevřený doklad (částky úhrad
     * se z Fakturoidu nepřebírají).
     *
     * @param array<string,mixed> $doc  Fakturoid invoice/expense JSON
     * @return ?array{status:string, paid_at:?string}
     */
    public static function fromFakturoid(array $doc): ?array
    {
        return match ((string) ($doc['status'] ?? '')) {
            'paid'      => ['status' => 'paid', 'paid_at' => self::normalizeDate($doc['paid_on'] ?? null)],
            'cancelled' => ['status' => 'cancelled', 'paid_at' => null],
            default     => null,
        };
    }

    /**
     * iDoklad v3: PaymentStatus ∈ {0=Unpaid, 1=Paid, 2=PartialPaid, 3=Overpaid},
     * DateOfPayment je C# DateTime — neuhrazený doklad má default '0001-01-01…',
     * který normalizeDate odfiltruje. Overpaid (3) je fakticky uhrazeno.
     * Storno stav iDoklad v list modelu nenese → cancelled se nemapuje.
     *
     * @param array<string,mixed> $doc  iDoklad IssuedInvoice/CreditNote/ReceivedInvoice JSON
     * @return ?array{status:string, paid_at:?string}
     */
    public static function fromIdoklad(array $doc): ?array
    {
        $paymentStatus = (int) ($doc['PaymentStatus'] ?? 0);
        if ($paymentStatus !== 1 && $paymentStatus !== 3) {
            return null;
        }
        return ['status' => 'paid', 'paid_at' => self::normalizeDate($doc['DateOfPayment'] ?? null)];
    }

    /**
     * 'YYYY-MM-DD' prefix z date/datetime stringu; placeholder data (C# default
     * 0001-01-01, MySQL 0000-00-00) a nesmysly → null (volající doplní fallback
     * tax_date/issue_date).
     */
    public static function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return null;
        }
        if ((int) $m[1] < 1990 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
}

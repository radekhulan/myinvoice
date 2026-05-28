<?php

declare(strict_types=1);

namespace MyInvoice\Service;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Hlídá blížící se překročení obratových prahů pro českého OSVČ:
 *
 *   1) DPH plátcovství (§6 odst. 1 ZDPH) — povinná registrace po překročení
 *      2 000 000 Kč obratu za 12 po sobě jdoucích kalendářních měsíců.
 *      Od 1.1.2025 navíc 2 536 500 Kč za kalendářní rok = okamžitá registrace.
 *      Počítáme z **vystavených** faktur (status ∈ issued/sent/reminded/paid,
 *      tj. ne draft a ne cancelled), na základě issue_date.
 *
 *   2) Paušální daň (§7a ZDP) — limit per pásmo:
 *        band1: 1 000 000 Kč/rok
 *        band2: 1 500 000 Kč/rok
 *        band3: 2 000 000 Kč/rok
 *      Počítáme z **zaplacených** faktur (status = paid) podle paid_at v
 *      aktuálním kalendářním roce — paušalista typicky používá kasovou metodu.
 *
 * Pro obě řady přepočítáváme cizí měny na CZK přes `invoices.exchange_rate`
 * (CNB k DUZP, fallback 1.0 pro CZK řádky bez kurzu) — stejný pattern jako
 * CrmDashboardAction / client_revenue_cache.
 *
 * Service je read-only; widget na Přehled / CRM volá `compute()` při každém
 * page-load (lehký SQL: 2-3 sum queries per supplier, žádný cron).
 */
final class TaxThresholdService
{
    /**
     * DPH §6 odst. 1 ZDPH (od 2024). Aktualizovat jen pokud se zákon změní.
     */
    public const VAT_LIMIT_ROLLING_12M_CZK = 2_000_000;

    /**
     * DPH §6 odst. 2 ZDPH (od 1.1.2025) — okamžitá povinnost registrace,
     * pokud obrat za kalendářní rok přesáhne tuhle hranici.
     */
    public const VAT_LIMIT_CALENDAR_YEAR_CZK = 2_536_500;

    /**
     * Pásma paušální daně. Hodnoty platí pro rok 2026 — měsíční záloha v
     * pásmu 1 byla 2026 zvýšena z 8 716 na 9 984 Kč. Pásma 2 a 3 zůstávají.
     * Pokud se hodnoty změní, upravíme tady — drží se mimo DB schválně, ať
     * historická data nepotřebují migraci.
     *
     * @var array<string, array{income_limit_czk:int, monthly_advance_czk:int}>
     */
    public const FLAT_TAX_BANDS = [
        'band1' => ['income_limit_czk' => 1_000_000, 'monthly_advance_czk' =>  9_984],
        'band2' => ['income_limit_czk' => 1_500_000, 'monthly_advance_czk' => 16_745],
        'band3' => ['income_limit_czk' => 2_000_000, 'monthly_advance_czk' => 27_139],
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Spočítá aktuální stav prahů pro daného supplier. Vrací prázdnou strukturu
     * `applicable = false` pokud daný práh neplatí (plátce DPH neřeší VAT limit;
     * supplier s flat_tax_band='none' neřeší paušál).
     *
     * @return array{
     *   vat_threshold: array{
     *     applicable: bool,
     *     rolling12m: ?array{current_czk:float, limit_czk:int, percent:int, status:string, window_from:string, window_to:string},
     *     calendar_year: ?array{current_czk:float, limit_czk:int, percent:int, status:string, year:int}
     *   },
     *   flat_tax_threshold: array{
     *     applicable: bool,
     *     band: ?string,
     *     monthly_advance_czk: ?int,
     *     current_czk: ?float,
     *     limit_czk: ?int,
     *     percent: ?int,
     *     status: ?string,
     *     year: ?int
     *   }
     * }
     */
    public function compute(int $supplierId): array
    {
        $sup = $this->loadSupplier($supplierId);

        return [
            'vat_threshold'      => $this->computeVat($supplierId, $sup),
            'flat_tax_threshold' => $this->computeFlatTax($supplierId, $sup),
        ];
    }

    /**
     * @return array{is_vat_payer:bool, flat_tax_band:string}
     */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT is_vat_payer, flat_tax_band FROM supplier WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'is_vat_payer'  => (bool) ($row['is_vat_payer'] ?? false),
            'flat_tax_band' => (string) ($row['flat_tax_band'] ?? 'none'),
        ];
    }

    private function computeVat(int $supplierId, array $sup): array
    {
        if ($sup['is_vat_payer']) {
            // Už je plátcem → práh ho neřeší
            return ['applicable' => false, 'rolling12m' => null, 'calendar_year' => null];
        }

        $now = new \DateTimeImmutable('today');
        $windowFrom = $now->modify('-12 months +1 day')->format('Y-m-d');
        $windowTo   = $now->format('Y-m-d');

        $rolling = $this->sumIssuedRevenueCzk($supplierId, $windowFrom, $windowTo);
        $year    = (int) $now->format('Y');
        $yearSum = $this->sumIssuedRevenueCzk($supplierId, $year . '-01-01', $year . '-12-31');

        return [
            'applicable'   => true,
            'rolling12m'   => $this->wrapThreshold($rolling, self::VAT_LIMIT_ROLLING_12M_CZK, [
                'window_from' => $windowFrom,
                'window_to'   => $windowTo,
            ]),
            'calendar_year' => $this->wrapThreshold($yearSum, self::VAT_LIMIT_CALENDAR_YEAR_CZK, [
                'year' => $year,
            ]),
        ];
    }

    private function computeFlatTax(int $supplierId, array $sup): array
    {
        $band = $sup['flat_tax_band'];
        if ($band === 'none' || !isset(self::FLAT_TAX_BANDS[$band])) {
            return [
                'applicable' => false,
                'band' => null, 'monthly_advance_czk' => null,
                'current_czk' => null, 'limit_czk' => null, 'percent' => null, 'status' => null, 'year' => null,
            ];
        }

        $year = (int) (new \DateTimeImmutable('today'))->format('Y');
        $current = $this->sumPaidRevenueCzk($supplierId, $year . '-01-01', $year . '-12-31');
        $limit   = self::FLAT_TAX_BANDS[$band]['income_limit_czk'];
        $base    = $this->wrapThreshold($current, $limit, ['year' => $year]);

        return [
            'applicable'          => true,
            'band'                => $band,
            'monthly_advance_czk' => self::FLAT_TAX_BANDS[$band]['monthly_advance_czk'],
            'current_czk'         => $base['current_czk'],
            'limit_czk'           => $base['limit_czk'],
            'percent'             => $base['percent'],
            'status'              => $base['status'],
            'year'                => $year,
        ];
    }

    /**
     * Suma `total_with_vat` vystavených faktur (issued/sent/reminded/paid) v daném
     * okně, přepočtená na CZK přes invoice.exchange_rate. CZK řádky multiplier 1.
     */
    private function sumIssuedRevenueCzk(int $supplierId, string $from, string $to): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(
                       i.total_with_vat
                       * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)
                     ), 0) AS sum_czk
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note')
                AND i.issue_date BETWEEN ? AND ?"
        );
        $stmt->execute([$supplierId, $from, $to]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Suma `total_with_vat` zaplacených faktur (status=paid, paid_at v okně),
     * přepočtená na CZK. Paušalista počítá obrat podle kasové metody → bere paid_at.
     */
    private function sumPaidRevenueCzk(int $supplierId, string $from, string $to): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(
                       i.total_with_vat
                       * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)
                     ), 0) AS sum_czk
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status = 'paid'
                AND i.paid_at IS NOT NULL
                AND i.invoice_type IN ('invoice', 'credit_note')
                AND DATE(i.paid_at) BETWEEN ? AND ?"
        );
        $stmt->execute([$supplierId, $from, $to]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Spočítá percent + status (ok/notice/warning/danger) + extra metadata.
     *
     * @return array{current_czk:float, limit_czk:int, percent:int, status:string}
     */
    private function wrapThreshold(float $current, int $limit, array $extra = []): array
    {
        $percent = $limit > 0 ? (int) round($current / $limit * 100) : 0;
        $status = match (true) {
            $percent >= 95 => 'danger',
            $percent >= 80 => 'warning',
            $percent >= 60 => 'notice',
            default        => 'ok',
        };
        return array_merge([
            'current_czk' => round($current, 2),
            'limit_czk'   => $limit,
            'percent'     => $percent,
            'status'      => $status,
        ], $extra);
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Tax;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Tax\TaxOptimizer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Daňový optimalizátor — REST endpointy.
 *  GET /api/tax/analysis?year=YYYY  → příjmy + srovnání režimů (retrospektiva)
 *                                     nebo projekce + limity (běžící rok)
 *  PUT /api/tax/profile             → uloží daňový profil pro rok
 */
final class TaxAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxProfileRepository $profiles,
        private readonly TaxOptimizer $optimizer,
        private readonly TaxConstantsRepository $constants,
    ) {}

    /** GET /api/tax/analysis */
    public function analysis(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }

        $currentYear = (int) date('Y');
        $year = (int) ($request->getQueryParams()['year'] ?? $currentYear);
        if ($year < 2018 || $year > $currentYear + 1) {
            return Json::error($response, 'tax_invalid_year', 'Neplatný rok.', 422);
        }

        $flags = $this->supplierFlags($sid);
        $isVat = $flags['is_vat_payer'];
        $profileRow = $this->profiles->find($sid, $year);
        $publicProfile = $this->publicProfile($profileRow, $flags);
        $engineProfile = $publicProfile + ['is_vat_payer' => $isVat];

        $c = $this->constants->forYear($year);
        $payload = [
            'year'            => $year,
            'profile'         => $publicProfile,
            'is_vat_payer'    => $isVat,
            'supplier_band'   => $flags['flat_tax_band'],
            'constants'       => $c,
            'available_years' => $this->availableYears($sid, $currentYear),
            // Příjmy označené „osvobozeno od daně z příjmů" (§4 / přefakturace) — do
            // výpočtu daně ani pojistného NEvstupují (jsou už vyloučené v annualIncome);
            // tady jen pro transparentní zobrazení „z toho vyloučeno" v UI.
            'exempt_income'   => $this->profiles->annualExemptIncome($sid, $year, $isVat),
            'last_month'      => $this->lastMonthEstimate($sid, $isVat, $flags),
        ];

        if ($year < $currentYear) {
            // Uzavřený rok → retrospektiva (srovnání režimů na skutečném příjmu)
            $income = $this->profiles->annualIncome($sid, $year, $isVat);
            $payload['mode']    = 'retrospective';
            $payload['income']  = $income;
            $payload['compare'] = $this->optimizer->compare($engineProfile, $income, $c);
            // YoY: příjem + konstanty předchozího roku (frontend dopočítá meziroční srovnání).
            $prevYear   = $year - 1;
            $prevIncome = $this->profiles->annualIncome($sid, $prevYear, $isVat);
            $payload['prev'] = $prevIncome > 0
                ? ['year' => $prevYear, 'income' => $prevIncome, 'constants' => $this->constants->forYear($prevYear)]
                : null;
        } else {
            // Běžící rok → projekce a sledování limitů
            $monthly = $this->profiles->monthlyIncome($sid, $year, $isVat);
            [$ytd, $months] = $this->ytd($monthly, $year, $currentYear, (int) date('n'));
            $payload['mode']           = 'forecast';
            $payload['ytd_income']     = $ytd;
            $payload['months_elapsed'] = $months;
            $payload['predict']        = $this->optimizer->predict($engineProfile, $ytd, $months, $c);
        }

        return Json::ok($response, $payload);
    }

    /** PUT /api/tax/profile */
    public function updateProfile(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }

        $body = (array) $request->getParsedBody();
        $currentYear = (int) date('Y');
        $year = (int) ($body['year'] ?? $currentYear);
        if ($year < 2018 || $year > $currentYear + 1) {
            return Json::error($response, 'tax_invalid_year', 'Neplatný rok.', 422);
        }

        $saved = $this->profiles->upsert($sid, $year, $body);
        return Json::ok($response, ['profile' => $saved]);
    }

    /** @return array{is_vat_payer: bool, flat_tax_band: string} */
    private function supplierFlags(int $sid): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT is_vat_payer, flat_tax_band FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'is_vat_payer'  => (bool) ($row['is_vat_payer'] ?? false),
            'flat_tax_band' => (string) ($row['flat_tax_band'] ?? 'none'),
        ];
    }

    /**
     * Profil pro frontend (form) i engine. Default z supplieru, pokud řádek neexistuje.
     * Engine si bere tentýž tvar + `is_vat_payer` (viz analysis()).
     * @param array<string,mixed>|null $row
     * @param array{is_vat_payer: bool, flat_tax_band: string} $flags
     * @return array<string,mixed>
     */
    private function publicProfile(?array $row, array $flags): array
    {
        return [
            'activity_rate'       => (int) ($row['activity_rate'] ?? 60),
            'use_actual_expenses' => (bool) ($row['use_actual_expenses'] ?? false),
            'actual_expenses'     => (float) ($row['actual_expenses'] ?? 0),
            'flat_tax_band'     => (string) ($row['flat_tax_band'] ?? $flags['flat_tax_band']),
            'is_secondary'      => (bool) ($row['is_secondary'] ?? false),
            'spouse_credit'     => (bool) ($row['spouse_credit'] ?? false),
            'children_count'    => (int) ($row['children_count'] ?? 0),
            'mortgage_interest' => (float) ($row['mortgage_interest'] ?? 0),
            'pension_contrib'   => (float) ($row['pension_contrib'] ?? 0),
            'life_insurance'    => (float) ($row['life_insurance'] ?? 0),
            'donations'         => (float) ($row['donations'] ?? 0),
            'saved'             => $row !== null,
        ];
    }

    /**
     * YTD příjem a počet uplynulých celých měsíců (pro projekci).
     * @param array<int,float> $monthly
     * @return array{0: float, 1: int}
     */
    private function ytd(array $monthly, int $year, int $currentYear, int $currentMonth): array
    {
        $elapsed = $year < $currentYear ? 12 : max(1, $currentMonth - 1);
        $ytd = 0.0;
        for ($m = 1; $m <= $elapsed; $m++) {
            $ytd += $monthly[$m] ?? 0.0;
        }
        // Fallback: na začátku roku / řídká data → vezmi vše a počet měsíců s daty.
        if ($ytd <= 0) {
            $ytd = array_sum($monthly);
            $withData = count(array_filter($monthly, static fn ($v) => $v > 0));
            $elapsed = max(1, $withData);
        }
        return [round($ytd, 2), $elapsed];
    }

    /**
     * Pravděpodobný čistý příjem za minulý kalendářní měsíc — vždy nezávisle na
     * zvoleném roce v přepínači (viz {@see TaxOptimizer::estimateMonthly()}).
     * @param array{is_vat_payer: bool, flat_tax_band: string} $flags
     * @return array<string,mixed>
     */
    private function lastMonthEstimate(int $sid, bool $isVat, array $flags): array
    {
        $lastMonth = new \DateTimeImmutable('first day of last month');
        $lmYear = (int) $lastMonth->format('Y');
        $lmYm   = $lastMonth->format('Y-m');

        $profileRow = $this->profiles->find($sid, $lmYear);
        $engineProfile = $this->publicProfile($profileRow, $flags) + ['is_vat_payer' => $isVat];
        $c = $this->constants->forYear($lmYear);

        $income   = $this->profiles->monthIncome($sid, $lmYm, $isVat);
        $expenses = $this->profiles->monthExpenses($sid, $lmYm, $isVat);

        return ['ym' => $lmYm] + $this->optimizer->estimateMonthly($engineProfile, $income, $expenses, $c);
    }

    /**
     * Roky pro přepínač: roky s fakturami sjednocené s aktuálním a minulým rokem.
     * @return list<int>
     */
    private function availableYears(int $sid, int $currentYear): array
    {
        $years = $this->profiles->incomeYears($sid);
        foreach ([$currentYear, $currentYear - 1] as $y) {
            if (!in_array($y, $years, true)) {
                $years[] = $y;
            }
        }
        rsort($years);
        return $years;
    }
}

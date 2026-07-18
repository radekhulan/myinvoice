<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Daňové profily (tax_profiles) — per supplier × rok — a agregace příjmů
 * pro daňový optimalizátor. Příjem = zaplacené vystavené faktury přepočtené
 * na CZK (kasová metoda, stejně jako sledování limitu paušálu na dashboardu).
 */
final class TaxProfileRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, year, activity_rate, use_actual_expenses, actual_expenses,
                    flat_tax_band, is_secondary, spouse_credit, children_count, mortgage_interest,
                    pension_contrib, life_insurance, donations
               FROM tax_profiles WHERE supplier_id = ? AND year = ?'
        );
        $stmt->execute([$supplierId, $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Vytvoří nebo aktualizuje profil (UNIQUE supplier_id+year).
     * @param array<string,mixed> $data
     * @return array<string,mixed> uložený profil
     */
    public function upsert(int $supplierId, int $year, array $data): array
    {
        $cols = ['activity_rate', 'flat_tax_band', 'is_secondary', 'spouse_credit',
                 'children_count', 'mortgage_interest', 'pension_contrib', 'life_insurance', 'donations'];

        $this->db->pdo()->prepare(
            'INSERT INTO tax_profiles
                (supplier_id, year, activity_rate, use_actual_expenses, actual_expenses, flat_tax_band,
                 is_secondary, spouse_credit, children_count, mortgage_interest, pension_contrib,
                 life_insurance, donations)
             VALUES (:sid, :year, :activity_rate, :use_actual_expenses, :actual_expenses, :flat_tax_band,
                 :is_secondary, :spouse_credit, :children_count, :mortgage_interest, :pension_contrib,
                 :life_insurance, :donations)
             ON DUPLICATE KEY UPDATE
                activity_rate = VALUES(activity_rate),
                use_actual_expenses = VALUES(use_actual_expenses),
                actual_expenses = VALUES(actual_expenses),
                flat_tax_band = VALUES(flat_tax_band),
                is_secondary = VALUES(is_secondary),
                spouse_credit = VALUES(spouse_credit),
                children_count = VALUES(children_count),
                mortgage_interest = VALUES(mortgage_interest),
                pension_contrib = VALUES(pension_contrib),
                life_insurance = VALUES(life_insurance),
                donations = VALUES(donations)'
        )->execute([
            ':sid' => $supplierId,
            ':year' => $year,
            ':activity_rate' => in_array((string) ($data['activity_rate'] ?? '60'), ['30', '40', '60', '80'], true) ? (string) $data['activity_rate'] : '60',
            ':use_actual_expenses' => !empty($data['use_actual_expenses']) ? 1 : 0,
            ':actual_expenses' => max(0.0, (float) ($data['actual_expenses'] ?? 0)),
            ':flat_tax_band' => in_array((string) ($data['flat_tax_band'] ?? 'none'), ['none', 'band1', 'band2', 'band3'], true) ? (string) $data['flat_tax_band'] : 'none',
            ':is_secondary' => !empty($data['is_secondary']) ? 1 : 0,
            ':spouse_credit' => !empty($data['spouse_credit']) ? 1 : 0,
            ':children_count' => max(0, (int) ($data['children_count'] ?? 0)),
            ':mortgage_interest' => max(0.0, (float) ($data['mortgage_interest'] ?? 0)),
            ':pension_contrib' => max(0.0, (float) ($data['pension_contrib'] ?? 0)),
            ':life_insurance' => max(0.0, (float) ($data['life_insurance'] ?? 0)),
            ':donations' => max(0.0, (float) ($data['donations'] ?? 0)),
        ]);

        return $this->find($supplierId, $year) ?? [];
    }

    /**
     * Roční příjem (zaplacené faktury daného roku, přepočet na CZK).
     * Pro plátce DPH se bere bez DPH, pro neplátce s DPH (= fakturovaná částka).
     */
    public function annualIncome(int $supplierId, int $year, bool $isVatPayer): float
    {
        $col = $isVatPayer ? 'i.total_without_vat' : 'i.total_with_vat';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM({$col} * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)), 0)
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status = 'paid'
                AND i.paid_at IS NOT NULL
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                AND COALESCE(i.income_tax_exempt, 0) = 0
                AND YEAR(i.paid_at) = ?"
        );
        $stmt->execute([$supplierId, $year]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Roční příjem označený „osvobozeno od daně z příjmů" (income_tax_exempt=1,
     * zaplacené faktury daného roku). Slouží jen k transparentnímu zobrazení
     * „z toho vyloučeno ze základu daně z příjmů" v daňovém optimalizátoru —
     * do výpočtu daně/pojistného NEvstupuje (ten už osvobozené příjmy nezahrnuje).
     */
    public function annualExemptIncome(int $supplierId, int $year, bool $isVatPayer): float
    {
        $col = $isVatPayer ? 'i.total_without_vat' : 'i.total_with_vat';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM({$col} * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)), 0)
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status = 'paid'
                AND i.paid_at IS NOT NULL
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                AND COALESCE(i.income_tax_exempt, 0) = 1
                AND YEAR(i.paid_at) = ?"
        );
        $stmt->execute([$supplierId, $year]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Měsíční příjmy roku [1..12] => CZK (pro projekci běžícího roku).
     * @return array<int,float>
     */
    public function monthlyIncome(int $supplierId, int $year, bool $isVatPayer): array
    {
        $col = $isVatPayer ? 'i.total_without_vat' : 'i.total_with_vat';
        $stmt = $this->db->pdo()->prepare(
            "SELECT MONTH(i.paid_at) AS m,
                    COALESCE(SUM({$col} * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)), 0) AS total
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status = 'paid'
                AND i.paid_at IS NOT NULL
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                AND COALESCE(i.income_tax_exempt, 0) = 0
                AND YEAR(i.paid_at) = ?
           GROUP BY MONTH(i.paid_at)"
        );
        $stmt->execute([$supplierId, $year]);
        $out = array_fill(1, 12, 0.0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['m']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /**
     * Příjem za konkrétní měsíc (zaplacené faktury, 'YYYY-MM', kasová metoda).
     * Pro odhad "čistý příjem za minulý měsíc" v daňovém optimalizátoru.
     */
    public function monthIncome(int $supplierId, string $ym, bool $isVatPayer): float
    {
        $col = $isVatPayer ? 'i.total_without_vat' : 'i.total_with_vat';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM({$col} * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)), 0)
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status = 'paid'
                AND i.paid_at IS NOT NULL
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                AND COALESCE(i.income_tax_exempt, 0) = 0
                AND DATE_FORMAT(i.paid_at, '%Y-%m') = ?"
        );
        $stmt->execute([$supplierId, $ym]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Náklady za konkrétní měsíc (zaplacené přijaté faktury, 'YYYY-MM', kasová metoda).
     * Stejná konvence jako PurchaseSummaryAction::advanceCostExclude() — zaplacenou
     * zálohu spárovanou s vyúčtovací fakturou nepočítej dvakrát.
     */
    public function monthExpenses(int $supplierId, string $ym, bool $isVatPayer): float
    {
        $col = $isVatPayer ? 'pi.total_without_vat' : 'pi.total_with_vat';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM({$col} * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)), 0)
               FROM purchase_invoices pi
          LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status = 'paid'
                AND pi.paid_at IS NOT NULL
                AND DATE_FORMAT(pi.paid_at, '%Y-%m') = ?
                AND NOT (COALESCE(pi.document_kind, '') = 'advance'
                     AND EXISTS (SELECT 1 FROM purchase_invoices adv_s
                                 WHERE adv_s.advance_purchase_invoice_id = pi.id))"
        );
        $stmt->execute([$supplierId, $ym]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Roky, za které existují zaplacené faktury (pro přepínač roku).
     * @return list<int>
     */
    public function incomeYears(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT YEAR(paid_at) AS y FROM invoices
              WHERE supplier_id = ? AND status = 'paid' AND paid_at IS NOT NULL
           ORDER BY y DESC"
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn ($r) => (int) $r['y'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['year'] = (int) $r['year'];
        $r['activity_rate'] = (int) $r['activity_rate'];
        $r['use_actual_expenses'] = (bool) $r['use_actual_expenses'];
        $r['actual_expenses'] = (float) $r['actual_expenses'];
        $r['is_secondary'] = (bool) $r['is_secondary'];
        $r['spouse_credit'] = (bool) $r['spouse_credit'];
        $r['children_count'] = (int) $r['children_count'];
        $r['mortgage_interest'] = (float) $r['mortgage_interest'];
        $r['pension_contrib'] = (float) $r['pension_contrib'];
        $r['life_insurance'] = (float) $r['life_insurance'];
        $r['donations'] = (float) $r['donations'];
        return $r;
    }
}

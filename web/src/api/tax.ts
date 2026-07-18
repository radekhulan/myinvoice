import { api } from './client'

export type FlatTaxBand = 'none' | 'band1' | 'band2' | 'band3'

export interface TaxProfile {
  activity_rate: number
  use_actual_expenses: boolean
  actual_expenses: number
  flat_tax_band: FlatTaxBand
  is_secondary: boolean
  spouse_credit: boolean
  children_count: number
  mortgage_interest: number
  pension_contrib: number
  life_insurance: number
  donations: number
  saved?: boolean
}

/** Roční konstanty (tvar TaxConstants::forYear z backendu). Volné typování — engine je čte dynamicky. */
export interface TaxConstantsData {
  year: number
  pausal_annual: Record<string, number>
  band_ceilings: Record<string, Record<string, number>>
  credit_taxpayer: number
  credit_spouse: number
  child_credits: number[]
  tax_rate_low: number
  tax_rate_high: number
  tax_high_threshold: number
  social_rate: number
  health_rate: number
  social_assessment_pct: number
  health_assessment_pct: number
  social_min_base_main: number
  social_min_base_secondary: number
  /** Rozhodná částka (zisk) pro povinnou účast na důch. pojištění u vedlejší SVČ */
  social_secondary_participation_threshold: number
  health_min_base: number
  expense_caps: Record<string, number>
  mortgage_cap: number
  pension_cap: number
  vat_limit_low: number
  vat_limit_high: number
  /** DPH — platí pro všechny plátce (nejen OSVČ) */
  vat_rate_standard: number
  vat_rate_reduced: number
  kh_item_threshold: number
}

/** Pravděpodobný čistý příjem za minulý kalendářní měsíc (odhad, viz TaxOptimizer::estimateMonthly). */
export interface LastMonthEstimate {
  ym: string
  revenue: number
  expenses: number
  profit: number
  income_tax: number
  social: number
  health: number
  net_income: number
}

export interface TaxAnalysis {
  year: number
  mode: 'retrospective' | 'forecast'
  profile: TaxProfile
  is_vat_payer: boolean
  supplier_band: FlatTaxBand
  constants: TaxConstantsData
  available_years: number[]
  income?: number
  ytd_income?: number
  months_elapsed?: number
  /** YoY: příjem + konstanty předchozího roku (jen v retrospektivě, pokud loni byl příjem). */
  prev?: { year: number; income: number; constants: TaxConstantsData } | null
  last_month: LastMonthEstimate
}

export const taxApi = {
  analysis: (year: number) =>
    api.get<TaxAnalysis>('/tax/analysis', { params: { year } }).then(r => r.data),
  saveProfile: (payload: Partial<TaxProfile> & { year: number }) =>
    api.put<{ profile: TaxProfile }>('/tax/profile', payload).then(r => r.data.profile),
}

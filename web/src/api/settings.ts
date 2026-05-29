import { api } from './client'

export interface Supplier {
  id: number
  company_name: string
  display_name: string | null
  street: string
  city: string
  zip: string
  country_id: number
  country_iso?: string
  country_name_cs?: string
  country_name_en?: string
  ic: string | null
  dic: string | null
  is_vat_payer: boolean
  email: string
  phone: string | null
  web: string | null
  tagline: string | null
  commercial_register: string | null
  default_currency_id: number
  default_currency: string
  default_vat_rate_id: number
  default_payment_due_days: number
  default_payment_due_unit: 'days' | 'month'
  default_hourly_rate: number
  auto_send_reminders: boolean
  auto_generate_recurring: boolean
  embed_isdoc: boolean
  logo_path: string | null
  signature_path: string | null
  pohoda_account_code: string | null
  pohoda_centre_code: string | null
  pohoda_activity_code: string | null
  pohoda_contract_code: string | null
  // Per-supplier konfigurace číslování faktur (migrace 0014).
  // *_format — template typu 'JD{YYYY}-{CC}', null = fallback na cfg.varsymbol.templates.{type}.
  // period — 'year' (1.1.) | 'month' (1. dne v měsíci) | 'none' (nikdy).
  invoice_number_format: string | null
  proforma_number_format: string | null
  credit_note_number_format: string | null
  invoice_number_period: 'year' | 'month' | 'none'
  // Per-supplier email branding (migrace 0016)
  email_branding_enabled: boolean
  email_accent_color: string  // #RRGGBB
  pdf_logo_show_name: boolean // vedle loga v PDF zobrazit i název firmy (migrace 0058)
  has_email_logo?: boolean    // server flag (existence storage/supplier-logos/sup-{id}.png)
  // Podpis PDF certifikátem (PAdES, migrace 0072)
  pdf_signing_enabled: boolean
  signing_tsa_url: string | null  // RFC 3161 TSA endpoint; null = PAdES-B bez razítka
  signing_reason: string          // důvod podpisu (default „Faktura")
  has_signing_cert?: boolean       // server flag (existence P12); heslo/cesta se NIKDY nevrací
  // Tax settings pro EPO výkazy DPH/KH (migrace 0038, fáze 6)
  taxpayer_type?: 'fo' | 'po' | null
  vat_period?: 'monthly' | 'quarterly' | null
  flat_tax_band?: 'none' | 'band1' | 'band2' | 'band3' | null
  financial_office_code?: string | null
  workplace_code?: string | null
  cz_nace_code?: string | null
  data_box_type?: string | null
  data_box_id?: string | null
  sest_jmeno?: string | null
  sest_prijmeni?: string | null
  sest_telefon?: string | null
  sest_email?: string | null
  sest_funkce?: string | null
  // Doplňky pro DPH/KH XML VetaP (migrace 0043)
  street_number_pop?: string | null
  street_number_orient?: string | null
  opr_jmeno?: string | null
  opr_prijmeni?: string | null
  opr_postaveni?: string | null
  // Globální cfg fallback (read-only) — UI ho ukáže jako placeholder
  // v prázdných polích per-supplier šablon. Hodnota přichází z cfg.varsymbol.templates.
  cfg_varsymbol_fallback?: {
    invoice: string
    proforma: string
    credit_note: string
  }
}

export interface CurrencyAccount {
  id: number
  code: string
  label: string
  symbol: string
  name_cs: string
  name_en: string
  decimals: number
  is_active: boolean
  is_default: boolean
  account_number: string | null
  bank_code: string | null
  bank_name: string | null
  iban: string | null
  bic: string | null
  invoices_count?: number
}

export interface VatRate {
  id: number
  code: string
  rate_percent: number
  country: string
  label_cs: string
  label_en: string
  is_default: boolean
  is_reverse_charge: boolean
  valid_from: string
  valid_to: string | null
  items_count?: number
}

export interface Country {
  id: number
  iso2: string
  iso3: string
  name_cs: string
  name_en: string
  is_eu: boolean
  uses_count?: number
}

export interface Unit {
  id: number
  code: string
  label_cs: string
  label_en: string
  is_default: boolean
  display_order: number
  items_count?: number
}

export interface SigningCertMeta {
  has_cert: boolean
  cn?: string
  issuer?: string
  valid_from?: string
  valid_to?: string
  expired?: boolean
  fingerprint?: string
}

export const settingsApi = {
  getSupplier: () => api.get<Supplier>('/settings/supplier').then(r => r.data),
  updateSupplier: (payload: Partial<Supplier>) => api.put<Supplier>('/settings/supplier', payload).then(r => r.data),

  listCurrencies: () => api.get<CurrencyAccount[]>('/settings/currencies').then(r => r.data),
  createCurrency: (payload: Partial<CurrencyAccount>) =>
    api.post<{ id: number; code: string }>('/settings/currencies', payload).then(r => r.data),
  updateCurrency: (id: number, payload: Partial<CurrencyAccount>) =>
    api.put<CurrencyAccount>(`/settings/currencies/${id}`, payload).then(r => r.data),
  deleteCurrency: (id: number) => api.delete(`/settings/currencies/${id}`).then(r => r.data),

  listVatRates:   () => api.get<VatRate[]>('/settings/vat-rates').then(r => r.data),
  createVatRate:  (p: Partial<VatRate>) => api.post('/settings/vat-rates', p).then(r => r.data),
  updateVatRate:  (id: number, p: Partial<VatRate>) => api.put(`/settings/vat-rates/${id}`, p).then(r => r.data),
  deleteVatRate:  (id: number) => api.delete(`/settings/vat-rates/${id}`).then(r => r.data),

  listCountries:  () => api.get<Country[]>('/settings/countries').then(r => r.data),
  createCountry:  (p: Partial<Country>) => api.post('/settings/countries', p).then(r => r.data),
  updateCountry:  (id: number, p: Partial<Country>) => api.put(`/settings/countries/${id}`, p).then(r => r.data),
  deleteCountry:  (id: number) => api.delete(`/settings/countries/${id}`).then(r => r.data),

  listUnits:  () => api.get<Unit[]>('/settings/units').then(r => r.data),
  createUnit: (p: Partial<Unit>) => api.post('/settings/units', p).then(r => r.data),
  updateUnit: (id: number, p: Partial<Unit>) => api.put(`/settings/units/${id}`, p).then(r => r.data),
  deleteUnit: (id: number) => api.delete(`/settings/units/${id}`).then(r => r.data),

  // Email branding (M16)
  uploadEmailLogo: (file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post<{ logo_path: string; width: number; height: number }>(
      '/settings/email-branding/logo',
      fd,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data)
  },
  deleteEmailLogo: () => api.delete('/settings/email-branding/logo').then(r => r.data),

  // Podpis PDF certifikátem (PAdES, migrace 0072)
  getSigningCert: () => api.get<SigningCertMeta>('/settings/signing-cert').then(r => r.data),
  uploadSigningCert: (file: File, password: string) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('password', password)
    return api.post<SigningCertMeta>('/settings/signing-cert', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  deleteSigningCert: () => api.delete('/settings/signing-cert').then(r => r.data),
  // Vrací HTML string — frontend ho pak nacpe do iframe.srcdoc (obejde X-Frame-Options DENY).
  emailPreviewHtml: (locale: 'cs' | 'en' = 'cs') =>
    api.get<string>(`/settings/email-branding/preview?locale=${locale}`, { responseType: 'text', transformResponse: [(d) => d] }).then(r => r.data),
}

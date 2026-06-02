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
  /** Výchozí režim cen u nových faktur (false = bez DPH, true = ceny s DPH). */
  default_prices_include_vat: boolean
  default_hourly_rate: number
  auto_send_reminders: boolean
  reminder_days_after_due: number
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
  // Děkovný e-mail za úhradu (issue #57)
  payment_thanks_enabled: boolean
  payment_thanks_auto_send: boolean
  payment_thanks_default_checked: boolean
  payment_thanks_attach_paid_pdf: boolean
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

export interface PdfSigningDiagnostics {
  platform_enabled: boolean
  supplier_enabled: boolean
  effective_can_sign: boolean
  unavailable_reason: string | null
  failure_policy: string
  backend: {
    configured: string
    effective: string
    health: {
      ok: boolean
      message: string
    }
    capabilities: {
      supports_invisible: boolean
      supports_visible: boolean
      supports_append_signature_page: boolean
      supports_timestamp: boolean
      supports_pades: boolean
      requires_external_binary: boolean
      supported_certificate_types: string[]
    }
  }
  profile: {
    code: string
    available: boolean
    owner_type: string
    owner_id: number | null
    source: string
  }
  certificate: {
    configured: boolean
    exists: boolean
    storage: string
  }
  tsa: {
    configured: boolean
    auth_configured: boolean
  }
}

export interface SigningSettings {
  supplier_id: number
  accountant_profiles_enabled: boolean
}

export type SigningProfileUsage = 'pdf' | 'email_smime'

export interface SigningProfile {
  id: number
  supplier_id: number
  owner_user_id: number | null
  name: string
  code: string
  allowed_usages: SigningProfileUsage[]
  default_backend: string
  pdf_tsa_url: string | null
  pdf_tsa_username: string | null
  has_pdf_tsa_password: boolean
  pdf_reason: string | null
  is_active: boolean
  created_by: number | null
  created_at: string
  updated_at: string
  deleted_at: string | null
}

export interface SigningProfilePayload {
  owner_user_id?: number | null
  name: string
  code: string
  allowed_usages: SigningProfileUsage[]
  default_backend: string
  pdf_tsa_enabled?: boolean
  pdf_tsa_url?: string | null
  pdf_tsa_username?: string | null
  pdf_tsa_password?: string | null
  pdf_reason?: string | null
  is_active?: boolean
}

export type SigningCredentialPassphrasePolicy = 'encrypted_store' | 'passphrase_file' | 'prompt_on_use'

export interface SigningProfileCredentialMeta {
  has_certificate: boolean
  certificate_fingerprint?: string | null
  certificate_subject?: string | null
  certificate_email?: string | null
  certificate_valid_from?: string | null
  certificate_valid_to?: string | null
  certificate_usage?: Record<string, unknown>
  passphrase_policy?: SigningCredentialPassphrasePolicy | null
  passphrase_profile_id?: string | null
  is_active?: boolean
  expired?: boolean
}

export interface SigningProfileCredentialPassphrasePayload {
  passphrase_policy: SigningCredentialPassphrasePolicy
  passphrase_profile_id?: string | null
  password?: string | null
}

export type PdfSignatureSelectionSource = 'logged_in_user' | 'admin_profile_settings'
export type PdfSignatureUserProfileFallback = 'admin_profile_settings' | 'fail_closed' | 'fallback_unsigned'
export type PdfSignatureFailurePolicy = 'fallback_unsigned' | 'fail_closed' | 'skip_when_unconfigured'

export interface PdfSignatureOutputSetting {
  supplier_id: number
  usage: SigningProfileUsage
  output_type: string
  enabled: boolean
  backend: string
  selection_source: PdfSignatureSelectionSource
  user_profile_fallback: PdfSignatureUserProfileFallback
  default_profile_id: number | null
  failure_policy: PdfSignatureFailurePolicy
  signature_config: Record<string, unknown>
}

export interface PdfSignatureSettings {
  output_types: string[]
  output_settings: PdfSignatureOutputSetting[]
}

export interface PdfSignatureUserDefault {
  supplier_id: number
  usage: SigningProfileUsage
  output_type: string
  user_id: number
  profile_id: number
}

export interface PdfSignatureUserDefaults {
  output_types: string[]
  user_defaults: PdfSignatureUserDefault[]
  output_settings: PdfSignatureOutputSetting[]
}

export type PdfSignatureDocumentEntityType = 'invoice' | 'work_report'
export type PdfSignatureDocumentSelectionSource = PdfSignatureSelectionSource | 'inherit'

export interface PdfSignatureDocumentSelection {
  usage: SigningProfileUsage
  entity_type: PdfSignatureDocumentEntityType
  entity_id: number
  selection_source: PdfSignatureDocumentSelectionSource
  admin_profile_id: number | null
  inherited_selection_source: PdfSignatureSelectionSource
  inherited_admin_profile_id: number | null
  effective_selection_source: PdfSignatureSelectionSource
  effective_admin_profile_id: number | null
  has_override: boolean
}

export interface PdfSignatureTestResult {
  output_type: string
  status: 'signed' | 'skipped' | 'failed' | 'fallback_unsigned'
  backend: string
  profile_code: string | null
  certificate_cn: string | null
  level: string | null
  timestamped: boolean
  failure_policy: PdfSignatureFailurePolicy
  reason?: string
  error?: string
}

export type PdfSignatureOutputSettingPayload = Partial<Pick<
  PdfSignatureOutputSetting,
  'enabled' | 'backend' | 'selection_source' | 'user_profile_fallback' | 'default_profile_id' | 'failure_policy' | 'signature_config'
>>

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

  getPdfSigningDiagnostics: () =>
    api.get<PdfSigningDiagnostics>('/settings/pdf-signing/diagnostics').then(r => r.data),
  getSigningSettings: () =>
    api.get<SigningSettings>('/settings/signing').then(r => r.data),
  updateSigningSettings: (payload: Pick<SigningSettings, 'accountant_profiles_enabled'>) =>
    api.put<SigningSettings>('/settings/signing', payload).then(r => r.data),
  listSigningProfiles: () =>
    api.get<SigningProfile[]>('/settings/signing/profiles').then(r => r.data),
  createSigningProfile: (payload: SigningProfilePayload) =>
    api.post<SigningProfile>('/settings/signing/profiles', payload).then(r => r.data),
  updateSigningProfile: (id: number, payload: Partial<SigningProfilePayload>) =>
    api.put<SigningProfile>(`/settings/signing/profiles/${id}`, payload).then(r => r.data),
  deleteSigningProfile: (id: number) =>
    api.delete<{ deleted: boolean }>(`/settings/signing/profiles/${id}`).then(r => r.data),
  getSigningProfileCredential: (id: number) =>
    api.get<SigningProfileCredentialMeta>(`/settings/signing/profiles/${id}/credentials/certificate`).then(r => r.data),
  uploadSigningProfileCredential: (
    id: number,
    file: File,
    password: string,
    passphrasePolicy: SigningCredentialPassphrasePolicy,
    passphraseProfileId: string | null,
  ) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('password', password)
    fd.append('passphrase_policy', passphrasePolicy)
    if (passphraseProfileId) fd.append('passphrase_profile_id', passphraseProfileId)
    return api.post<SigningProfileCredentialMeta>(
      `/settings/signing/profiles/${id}/credentials/certificate`,
      fd,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data)
  },
  updateSigningProfileCredentialPassphrase: (
    id: number,
    payload: SigningProfileCredentialPassphrasePayload,
  ) =>
    api.put<SigningProfileCredentialMeta>(
      `/settings/signing/profiles/${id}/credentials/certificate`,
      payload,
    ).then(r => r.data),
  deleteSigningProfileCredential: (id: number) =>
    api.delete<SigningProfileCredentialMeta>(`/settings/signing/profiles/${id}/credentials/certificate`).then(r => r.data),
  getPdfSigningSettings: () =>
    api.get<PdfSignatureSettings>('/settings/pdf-signing').then(r => r.data),
  testPdfSigning: (outputType: string) =>
    api.post<PdfSignatureTestResult>('/settings/pdf-signing/test', { output_type: outputType }).then(r => r.data),
  updatePdfSignatureOutputSetting: (outputType: string, payload: PdfSignatureOutputSettingPayload) =>
    api.put<PdfSignatureOutputSetting>(`/settings/pdf-signing/output-settings/${outputType}`, payload).then(r => r.data),
  getPdfSigningUserDefaults: () =>
    api.get<PdfSignatureUserDefaults>('/settings/pdf-signing/user-defaults').then(r => r.data),
  updatePdfSigningUserDefault: (outputType: string, profileId: number | null) =>
    api.put<PdfSignatureUserDefault | null>(`/settings/pdf-signing/user-defaults/${outputType}`, {
      profile_id: profileId,
    }).then(r => r.data),
  getPdfSignatureDocumentSelection: (entityType: PdfSignatureDocumentEntityType, id: number) =>
    api.get<PdfSignatureDocumentSelection>(`/documents/${entityType}/${id}/signature-selection`).then(r => r.data),
  updatePdfSignatureDocumentSelection: (
    entityType: PdfSignatureDocumentEntityType,
    id: number,
    payload: { selection_source: PdfSignatureDocumentSelectionSource; admin_profile_id?: number | null },
  ) =>
    api.put<PdfSignatureDocumentSelection>(`/documents/${entityType}/${id}/signature-selection`, payload).then(r => r.data),
  deletePdfSignatureDocumentSelection: (entityType: PdfSignatureDocumentEntityType, id: number) =>
    api.delete<PdfSignatureDocumentSelection>(`/documents/${entityType}/${id}/signature-selection`).then(r => r.data),
  // Vrací HTML string — frontend ho pak nacpe do iframe.srcdoc (obejde X-Frame-Options DENY).
  emailPreviewHtml: (locale: 'cs' | 'en' = 'cs') =>
    api.get<string>(`/settings/email-branding/preview?locale=${locale}`, { responseType: 'text', transformResponse: [(d) => d] }).then(r => r.data),
}

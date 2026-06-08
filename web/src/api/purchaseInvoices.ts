import { api } from './client'

export type PurchaseInvoiceStatus = 'draft' | 'received' | 'booked' | 'paid' | 'cancelled'
export type PurchaseDocumentKind = 'invoice' | 'receipt' | 'credit_note' | 'advance'
export type ExchangeRateSource = 'cnb' | 'manual' | 'idoklad' | 'fakturoid'

export interface PurchaseInvoiceItem {
  id?: number
  purchase_invoice_id?: number
  description: string
  quantity: number
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  vat_rate_snapshot?: number
  total_without_vat?: number
  total_vat?: number
  total_with_vat?: number
  order_index: number
  vat_classification_code?: string | null
  vat_code?: string
  vat_label_cs?: string
  vat_label_en?: string
}

export interface PurchaseVatBreakdownRow {
  vat_rate: number
  without_vat: number
  vat: number
  with_vat: number
}

/**
 * Ruční override rekapitulace DPH dle dokladu dodavatele (§ 73 ZDPH).
 * Per sazba lze přepsat základ i daň; kalkulátor reziduum zapeče do řádkových totálů.
 */
export interface PurchaseVatOverride {
  rate: number
  base: number
  vat: number
}

export interface PurchaseInvoiceTotals {
  without_vat: number
  vat: number
  with_vat: number
  rounding: number
  advance_paid_amount: number
  amount_to_pay: number
}

export interface VendorSnapshot {
  id?: number
  company_name?: string
  first_name?: string | null
  last_name?: string | null
  ic?: string | null
  dic?: string | null
  street?: string
  city?: string
  zip?: string
  main_email?: string
  phone?: string | null
  language?: 'cs' | 'en'
  country_iso2?: string
  country_name_cs?: string
  country_name_en?: string
}

/** Nárok na odpočet DPH: plný / bez nároku / krácený (poměrný §75, viz vat_deduction_percent). */
export type VatDeduction = 'full' | 'none' | 'proportional'

/** Stručné shrnutí navázané přijaté faktury (záloha ↔ vyúčtovací). */
export interface PurchaseInvoiceBrief {
  id: number
  varsymbol: string | null
  vendor_invoice_number: string | null
  document_kind: PurchaseDocumentKind | null
  status: PurchaseInvoiceStatus
  issue_date: string | null
  total_with_vat: number
  currency: string
}

export interface PurchaseInvoice {
  id: number
  supplier_id: number
  vendor_id: number
  varsymbol: string | null
  vendor_invoice_number: string
  document_kind: PurchaseDocumentKind
  issue_date: string
  tax_date: string | null
  due_date: string
  received_at: string
  currency_id: number
  currency: string
  currency_symbol?: string
  currency_decimals?: number
  exchange_rate: number | null
  exchange_rate_date: string | null
  exchange_rate_source: ExchangeRateSource
  reverse_charge: boolean
  prices_include_vat?: boolean
  is_fixed_asset: boolean
  /** Nárok na odpočet DPH (full=plný, none=bez nároku → mimo DPH evidenci, proportional=krácený §75). */
  vat_deduction: VatDeduction
  /** Procento odpočtu při vat_deduction='proportional' (§75 poměrný; 0–100, default 100). */
  vat_deduction_percent: number
  /** Daňová uznatelnost nákladu pro daň z příjmů (DPFO/DPPO). */
  tax_deductible: boolean
  language: 'cs' | 'en'
  note_above_items: string | null
  note_below_items: string | null
  vendor_snapshot: VendorSnapshot | null
  own_snapshot: Record<string, unknown> | null
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  rounding: number
  advance_paid_amount: number
  amount_to_pay: number
  // Multi-currency platba (USD faktura placená z CZK účtu)
  payment_currency_id: number | null
  payment_currency: string | null
  payment_exchange_rate: number | null
  paid_amount_payment_ccy: number | null
  paid_amount_invoice_ccy: number | null
  exchange_diff_base: number | null
  status: PurchaseInvoiceStatus
  booked_at: string | null
  paid_at: string | null
  cancelled_at: string | null
  pdf_path: string | null
  pdf_hash: string | null
  pdf_size_bytes: number | null
  pdf_original_name: string | null
  pdf_uploaded_at: string | null
  vat_classification_code: string | null
  expense_category_id: number | null
  /** Název + kód kategorie nákladu (join z expense_categories, jen v detailu). */
  expense_category_label?: string | null
  expense_category_code?: string | null
  /** Záloha (advance), kterou tato finální faktura vyúčtovává (vazba uložená na finální). */
  advance_purchase_invoice_id: number | null
  /** AI návrh propojení se zálohou (čeká na potvrzení uživatelem). */
  advance_link_suggested_id: number | null
  /** Shrnutí navázané zálohy (pokud advance_purchase_invoice_id != null). */
  linked_advance?: PurchaseInvoiceBrief | null
  /** Shrnutí AI-navržené zálohy (suggest & confirm). */
  advance_link_suggestion?: PurchaseInvoiceBrief | null
  /** U zálohy (advance): finální faktura, která ji vyúčtovává (reverzní pohled). */
  settled_by?: PurchaseInvoiceBrief | null
  /** Vyúčtovací faktura bez vazby: existuje nespárovaná záloha téhož dodavatele? */
  has_advance_candidates?: boolean
  /** Záloha bez vyúčtování: existuje nepropojená finální faktura téhož dodavatele? */
  has_settlement_candidates?: boolean
  /**
   * Diagnostický popis problému z AI extrakce (např. AI sečetla mezisoučty
   * jako další položky → suma řádků se výrazně liší od AI-vráceného totalu).
   * NULL = vše OK / faktura nebyla AI-importována.
   */
  extraction_warning: string | null
  created_by: number
  created_at: string
  updated_at: string
  /**
   * Non-blocking varování z create/update endpointu (kódy překládané přes
   * t('purchase_invoice.warning.<code>')). Např. `credit_note_positive_total`
   * = dobropis má kladný součet (dvojí negace znaménka). Viz issue #35.
   */
  _warnings?: string[]
  // Joined fields
  vendor_company_name?: string
  vendor_ic?: string | null
  vendor_dic?: string | null
  vendor_main_email?: string
  vendor_language?: 'cs' | 'en'
  /** Ruční rekapitulace DPH dle dokladu (§ 73). NULL = počítá se standardně. */
  vat_overrides: PurchaseVatOverride[] | null
  // Related
  items: PurchaseInvoiceItem[]
  vat_breakdown: PurchaseVatBreakdownRow[]
  totals: PurchaseInvoiceTotals
}

export interface PurchaseInvoiceListItem {
  id: number
  supplier_id: number
  vendor_id: number
  varsymbol: string | null
  vendor_invoice_number: string
  document_kind: PurchaseDocumentKind
  issue_date: string
  tax_date: string | null
  due_date: string
  received_at: string
  currency_id: number
  currency: string
  currency_symbol?: string
  currency_decimals?: number
  exchange_rate: number | null
  exchange_rate_date: string | null
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  advance_paid_amount: number
  amount_to_pay: number
  status: PurchaseInvoiceStatus
  booked_at: string | null
  paid_at: string | null
  cancelled_at: string | null
  vendor_company_name: string
  vendor_ic: string | null
  month_bucket: string
  extraction_warning: string | null
}

export interface PurchaseMonthGroup {
  month: string
  count: number
  totals_per_currency: Array<{
    currency: string
    without_vat: number
    vat: number
    with_vat: number
  }>
  invoices: PurchaseInvoiceListItem[]
}

export interface PurchaseInvoicePayload {
  vendor_id: number
  vendor_invoice_number: string
  document_kind?: PurchaseDocumentKind
  varsymbol?: string | null
  issue_date: string
  tax_date?: string | null
  due_date: string
  received_at?: string
  currency_id: number
  exchange_rate?: number | null
  exchange_rate_date?: string | null
  exchange_rate_source?: ExchangeRateSource
  reverse_charge?: boolean
  prices_include_vat?: boolean
  is_fixed_asset?: boolean
  vat_deduction?: VatDeduction
  vat_deduction_percent?: number
  tax_deductible?: boolean
  language?: 'cs' | 'en'
  note_above_items?: string | null
  note_below_items?: string | null
  advance_paid_amount?: number
  rounding?: number
  payment_currency_id?: number | null
  payment_exchange_rate?: number | null
  paid_amount_payment_ccy?: number | null
  paid_amount_invoice_ccy?: number | null
  exchange_diff_base?: number | null
  vat_classification_code?: string | null
  expense_category_id?: number | null
  /** Ruční rekapitulace DPH dle dokladu (§ 73). null/[] = počítat standardně. */
  vat_overrides?: PurchaseVatOverride[] | null
  items: Array<{
    description: string
    quantity: number
    unit: string
    unit_price_without_vat: number
    vat_rate_id: number
    order_index: number
    vat_classification_code?: string | null
  }>
}

export interface PurchaseListFilters {
  status?: PurchaseInvoiceStatus | PurchaseInvoiceStatus[]
  document_kind?: PurchaseDocumentKind | PurchaseDocumentKind[]
  vendor_id?: number
  year?: number
  month?: number
  date_from?: string
  date_to?: string
  currency?: string
  unpaid_only?: boolean
  overdue?: boolean
  needs_review?: boolean
  q?: string
  page?: number
  per_page?: number
}

export interface PurchaseListMeta {
  total: number
  page?: number
  per_page?: number
  pages?: number
}

export interface InboxScanResultDetail {
  file: string
  status: 'created' | 'skipped' | 'failed' | 'rejected' | 'mapper_pending' | 'config_missing' | 'inbox_missing' | 'limit_reached'
  reason?: string
  purchase_invoice_id?: number
  isdoc_invoice_count?: number
  supplier_ic?: string | null
}

export interface InboxScanResult {
  created: number
  skipped: number
  failed: number
  dry_run: boolean
  inbox_dir: string
  details: InboxScanResultDetail[]
}

export const purchaseInvoicesApi = {
  listGrouped: (filters: PurchaseListFilters = {}) => {
    const params: Record<string, string | number> = {}
    if (filters.q) params.q = filters.q
    if (filters.status) {
      params['filter[status]'] = Array.isArray(filters.status) ? filters.status.join(',') : filters.status
    }
    if (filters.document_kind) {
      params['filter[document_kind]'] = Array.isArray(filters.document_kind)
        ? filters.document_kind.join(',')
        : filters.document_kind
    }
    if (filters.vendor_id)   params['filter[vendor_id]']   = filters.vendor_id
    if (filters.year)        params['filter[year]']        = filters.year
    if (filters.month)       params['filter[month]']       = filters.month
    if (filters.date_from)   params['filter[date_from]']   = filters.date_from
    if (filters.date_to)     params['filter[date_to]']     = filters.date_to
    if (filters.currency)    params['filter[currency]']    = filters.currency
    if (filters.unpaid_only)  params['filter[unpaid_only]']  = 1
    if (filters.overdue)      params['filter[overdue]']      = 1
    if (filters.needs_review) params['filter[needs_review]'] = 1
    if (filters.page)        params.page                   = filters.page
    if (filters.per_page)    params.per_page               = filters.per_page
    return api.get<{ data: PurchaseMonthGroup[]; meta: PurchaseListMeta }>(
      '/purchase-invoices',
      { params },
    ).then(r => r.data)
  },

  get:    (id: number) => api.get<PurchaseInvoice>(`/purchase-invoices/${id}`).then(r => r.data),
  create: (payload: PurchaseInvoicePayload) =>
    api.post<PurchaseInvoice>('/purchase-invoices', payload).then(r => r.data),
  update: (id: number, payload: PurchaseInvoicePayload, force = false) =>
    api.put<PurchaseInvoice>(
      `/purchase-invoices/${id}${force ? '?force=1' : ''}`,
      payload,
    ).then(r => r.data),
  delete: (id: number, force = false) =>
    api.delete<{ ok: boolean; pdf_deleted?: boolean }>(
      `/purchase-invoices/${id}${force ? '?force=1' : ''}`,
    ).then(r => r.data),

  setItems: (id: number, items: PurchaseInvoicePayload['items']) =>
    api.put<PurchaseInvoice>(`/purchase-invoices/${id}/items`, { items }).then(r => r.data),

  setExchangeRate: (id: number, rate: number | null, rateDate: string | null, source: ExchangeRateSource = 'manual') =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/exchange-rate`, {
      rate, rate_date: rateDate, source,
    }).then(r => r.data),

  transition: (id: number, target: PurchaseInvoiceStatus, paidDate?: string) =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/transition`, {
      target,
      ...(target === 'paid' ? { paid_date: paidDate || new Date().toISOString().slice(0, 10) } : {}),
    }).then(r => r.data),

  dismissExtractionWarning: (id: number) =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/dismiss-extraction-warning`).then(r => r.data),

  // Propojení se zálohovou fakturou (advance) — proti dvojímu započtení nákladu
  advanceCandidates: (id: number) =>
    api.get<{ candidates: PurchaseInvoiceBrief[] }>(`/purchase-invoices/${id}/advance-candidates`)
      .then(r => r.data.candidates),
  // Opačný směr — z detailu zálohy nabídni nepropojené vyúčtovací faktury téhož dodavatele
  settlementCandidates: (id: number) =>
    api.get<{ candidates: PurchaseInvoiceBrief[] }>(`/purchase-invoices/${id}/settlement-candidates`)
      .then(r => r.data.candidates),
  linkAdvance: (id: number, advanceId: number) =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/link-advance`, { advance_id: advanceId })
      .then(r => r.data),
  unlinkAdvance: (id: number) =>
    api.delete<PurchaseInvoice>(`/purchase-invoices/${id}/link-advance`).then(r => r.data),
  dismissAdvanceSuggestion: (id: number) =>
    api.delete<PurchaseInvoice>(`/purchase-invoices/${id}/advance-suggestion`).then(r => r.data),

  uploadPdf: (id: number, file: File) => {
    const fd = new FormData()
    fd.append('file', file, file.name)
    return api.post<{ ok: boolean; pdf_path: string; pdf_hash: string; pdf_size_bytes: number; pdf_original_name: string }>(
      `/purchase-invoices/${id}/pdf`,
      fd,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data)
  },

  deletePdf: (id: number) =>
    api.delete<{ ok: boolean; file_deleted: boolean; still_used_by: number }>(
      `/purchase-invoices/${id}/pdf`,
    ).then(r => r.data),

  activity: (id: number) =>
    api.get<Array<{
      id: number; user_id: number | null; user_email: string | null; user_name: string | null;
      action: string; payload: Record<string, unknown> | null; ip: string | null; created_at: string;
    }>>(`/purchase-invoices/${id}/activity`).then(r => r.data),

  pdfUrl: (id: number, inline = false) => {
    // Přímá navigace v prohlížeči — supplier_id v query param (X-Supplier-Id header se neposílá).
    // inline=true → Content-Disposition: inline (iframe preview; bez tohoto Edge/IE blokuje pro attachment).
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    if (inline) params.set('inline', '1')
    const qs = params.toString()
    return `/api/purchase-invoices/${id}/pdf${qs ? '?' + qs : ''}`
  },

  /** Naše vygenerované PDF (mPDF z dat). Když nemáme originál nebo chceme vlastní layout. */
  ourPdfUrl: (id: number, inline = false) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    if (inline) params.set('inline', '1')
    const qs = params.toString()
    return `/api/purchase-invoices/${id}/our-pdf${qs ? '?' + qs : ''}`
  },

  /** ISDOC XML export přijaté faktury (role inversion — vendor=supplier, my=customer). */
  isdocUrl: (id: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/purchase-invoices/${id}/isdoc${qs ? '?' + qs : ''}`
  },

  /** Pohoda XML export přijaté faktury (dataPackItem s `<pur:purchase>`). */
  pohodaUrl: (id: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/purchase-invoices/${id}/pohoda${qs ? '?' + qs : ''}`
  },

  scanInbox: (dryRun = false) =>
    api.post<InboxScanResult>('/purchase-invoices/scan-inbox', { dry_run: dryRun }).then(r => r.data),

  /**
   * Export přijatých faktur za měsíc nebo čtvrtletí.
   * Vrací URL pro přímou navigaci (axios by stáhl jako blob).
   */
  exportUrl: (
    period: { type: 'monthly'; year: number; month: number } | { type: 'quarterly'; year: number; quarter: number },
    dateBy: 'tax' | 'issue' | 'received' = 'tax',
    format: 'pdf-zip' | 'pohoda' | 'isdoc' = 'pdf-zip',
  ) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({
      period: period.type,
      year: String(period.year),
      format,
      date_by: dateBy,
    })
    if (period.type === 'quarterly') {
      params.set('quarter', String(period.quarter))
    } else {
      params.set('month', String(period.month))
    }
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/purchase-invoices/export?${params.toString()}`
  },
}

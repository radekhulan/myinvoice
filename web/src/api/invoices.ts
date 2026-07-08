import { api } from './client'

export type InvoiceType = 'invoice' | 'proforma' | 'credit_note' | 'cancellation' | 'tax_document'
export type InvoiceStatus = 'draft' | 'issued' | 'sent' | 'reminded' | 'paid' | 'cancelled'
export type ApprovalStatus = 'none' | 'requested' | 'approved' | 'rejected'
/** Odvozený platební stav (#89) — počítá se z paid_total vs. amount_to_pay; null pro draft/cancelled. */
export type PaymentStatus = 'unpaid' | 'partially_paid' | 'paid' | 'overpaid'

/** Evidovaná platba faktury (#89). */
export interface InvoicePayment {
  id: number
  invoice_id: number
  paid_on: string
  amount: number
  currency: string
  variable_symbol: string | null
  bank_reference: string | null
  note: string | null
  source: 'manual' | 'mark_paid' | 'bank' | 'legacy'
  bank_transaction_id: number | null
  bank_statement_id?: number | null
  bank_counterparty_name?: string | null
  tax_document_invoice_id: number | null
  tax_document_varsymbol?: string | null
  tax_document_status?: InvoiceStatus | null
  created_at: string
}

export interface InvoicePaymentsResponse {
  payments: InvoicePayment[]
  paid_total: number
  amount_to_pay: number
  remaining: number
  payment_status: PaymentStatus | null
}

/** Nespárovaná zálohová faktura (proforma) nabídnutá k propojení s daňovým dokladem. */
export interface AdvanceCandidate {
  id: number
  varsymbol: string | null
  invoice_type: InvoiceType
  status: InvoiceStatus
  issue_date: string | null
  total_with_vat: number
  currency: string
}

export interface InvoiceItem {
  id?: number
  invoice_id?: number
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
  // 'discount' = systémově generovaná záporná slevová položka (z invoices.discount_percent).
  // Editor ji při načtení vyfiltruje — needituje se a negeneruje znovu při uložení.
  item_kind?: 'standard' | 'discount'
  linked_work_report_id?: number | null
  vat_code?: string
  vat_label_cs?: string
  vat_label_en?: string
}

export interface VatBreakdownRow {
  rate: number
  base: number
  vat: number
}

export interface InvoiceTotals {
  without_vat: number
  vat: number
  with_vat: number
  rounding?: number
  advance_paid_amount?: number
  amount_to_pay?: number
  discount_percent?: number
  discount_amount?: number
}

export type PaymentMethod = 'bank_transfer' | 'card' | 'cash' | 'other'

export interface Invoice {
  id: number
  varsymbol: string | null
  invoice_type: InvoiceType
  parent_invoice_id: number | null
  client_id: number
  project_id: number | null
  issue_date: string
  tax_date: string | null
  due_date: string
  currency_id: number
  currency: string
  reverse_charge: boolean
  /** Ceny položek zadané včetně DPH (brutto) — DPH se počítá shora koeficientem. */
  prices_include_vat: boolean
  /** Doklad není základem daně z příjmů (§4 osvobození / přefakturace) → vyloučen z DPFO/DPPO i SP/ZP. DPH/KH/tržby nedotčeny. */
  income_tax_exempt: boolean
  income_tax_exempt_reason: string | null
  language: 'cs' | 'en'
  note_above_items: string | null
  note_below_items: string | null
  revenue_category_id: number | null
  revenue_category_label?: string | null
  revenue_category_code?: string | null
  // Cross-link na související doklady (z find()): u proformy → vystavený daňový doklad;
  // u dokladu s parent_invoice_id → rodič (proforma / původní faktura).
  final_invoice?: { id: number; varsymbol: string | null; status: InvoiceStatus } | null
  parent_invoice?: { id: number; varsymbol: string | null; status: InvoiceStatus; invoice_type: InvoiceType } | null
  // U daňového dokladu bez vazby: existují u odběratele nespárované zálohy k propojení?
  has_advance_candidates?: boolean
  // U nepropojené proformy: existují u odběratele nepropojené daňové doklady k propojení?
  has_final_candidates?: boolean
  recurring_template_id: number | null
  advance_paid_amount: number
  discount_percent: number
  payment_method: PaymentMethod
  auto_send_reminders: boolean
  amount_to_pay: number
  /** Suma evidovaných plateb (#89); zbývá k úhradě = amount_to_pay - paid_total. */
  paid_total: number
  payment_status?: PaymentStatus | null
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  rounding: number
  status: InvoiceStatus
  approval_status: ApprovalStatus
  approval_token: string | null
  approval_token_expires_at: string | null
  approval_requested_at: string | null
  approval_decided_at: string | null
  approval_decided_by_email: string | null
  approval_rejection_reason: string | null
  approval_reminder_at: string | null
  approval_reminder_count: number
  project_requires_approval?: boolean
  sent_at: string | null
  last_reminder_at: string | null
  reminder_count: number
  paid_at: string | null
  cancelled_at: string | null
  pdf_path: string | null
  /** Zdrojové PDF z importu (iDoklad/Fakturoid) — oddělené od našeho rendered `pdf_path`. */
  imported_pdf_path: string | null
  imported_pdf_original_name?: string | null
  imported_pdf_size_bytes?: number | string | null
  created_at: string
  updated_at: string
  /** Výsledek děkovného e-mailu (issue #57) — vrací mark-paid, jen když se odesílalo. */
  payment_thanks?: { status: 'sent' | 'skipped' | 'failed'; reason?: string; recipients?: string[] } | null
  client_company_name?: string
  client_main_email?: string
  client_ic?: string | null
  client_dic?: string | null
  client_language?: 'cs' | 'en'
  client_currency_default?: string
  client_currency_default_id?: number
  client_reverse_charge?: boolean
  project_name?: string | null
  project_hourly_rate?: number | null
  project_payment_due_days?: number | null
  currency_symbol?: string
  currency_decimals?: number
  bank_account_number?: string | null
  bank_code?: string | null
  bank_name?: string | null
  bank_iban?: string | null
  bank_bic?: string | null
  project_billing_emails?: Array<{ email: string; label: string | null }>
  items: InvoiceItem[]
  vat_breakdown: VatBreakdownRow[]
  totals: InvoiceTotals
  exchange_rate?: number | null
  exchange_rate_date?: string | null
  czk_recap?: CzkRecap | null
  _meta?: {
    exchange_rate?: ExchangeRateMeta
  }
}

export interface CzkRecap {
  rate: number
  rate_date: string
  fallback_used: boolean
  breakdown: Array<{
    rate: number
    base_czk: number
    vat_czk: number
    with_vat_czk: number
  }>
  total_without_vat_czk: number
  total_vat_czk: number
  total_with_vat_czk: number
}

export interface ExchangeRateMeta {
  currency: string
  rate: number
  rate_date: string
  fallback_used: boolean
  source: 'cache' | 'fresh' | 'last_known'
}

export interface InvoiceListItem {
  id: number
  varsymbol: string | null
  invoice_type: InvoiceType
  parent_invoice_id: number | null
  recurring_template_id?: number | null
  client_id: number
  project_id: number | null
  issue_date: string
  tax_date: string | null
  due_date: string
  currency_id?: number
  currency: string
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  advance_paid_amount: number
  amount_to_pay: number
  paid_total?: number
  payment_status?: PaymentStatus | null
  status: InvoiceStatus
  payment_method: PaymentMethod
  sent_at: string | null
  last_reminder_at: string | null
  reminder_count: number
  paid_at: string | null
  cancelled_at: string | null
  client_company_name: string
  project_name: string | null
  project_requires_approval?: boolean
  has_work_report?: boolean
  month_bucket: string
}

export interface MonthGroup {
  month: string
  count: number
  totals_per_currency: Array<{
    currency: string
    without_vat: number
    vat: number
    with_vat: number
    /** Predikce — součet konceptů (draft) vystavených faktur/dobropisů v měsíci. */
    draft_without_vat: number
    draft_vat: number
    draft_with_vat: number
  }>
  invoices: InvoiceListItem[]
}

export interface InvoicePayload {
  invoice_type?: InvoiceType
  client_id: number
  project_id?: number | null
  issue_date?: string
  tax_date?: string | null
  due_date?: string
  currency_id?: number
  reverse_charge?: boolean
  prices_include_vat?: boolean
  income_tax_exempt?: boolean
  income_tax_exempt_reason?: string | null
  language?: 'cs' | 'en'
  note_above_items?: string | null
  note_below_items?: string | null
  advance_paid_amount?: number
  discount_percent?: number
  payment_method?: PaymentMethod
  auto_send_reminders?: boolean
  exchange_rate?: number | null
  // Volitelný ruční override čísla faktury (varsymbol). Prázdný řetězec / null =
  // generuje se automaticky při issue dle supplier templatu (Settings → Číslování faktur)
  // s fallbackem na cfg.varsymbol.templates. Backend ho akceptuje jen u draftu;
  // po vystavení je číslo immutable (snapshot).
  varsymbol?: string | null
  vat_classification_code?: string | null
  revenue_category?: string | null
  revenue_category_id?: number | null
  items: Array<{
    description: string
    quantity: number
    unit: string
    unit_price_without_vat: number
    vat_rate_id: number
    order_index: number
  }>
}

export interface ListFilters {
  status?: string | string[]
  type?: string | string[]
  client_id?: number
  project_id?: number
  year?: number
  month?: number
  date_from?: string
  date_to?: string
  currency?: string
  unpaid_only?: boolean
  overdue?: boolean
  q?: string
  page?: number
  per_page?: number
}

export interface InvoiceListMeta {
  total: number
  page?: number
  per_page?: number
  pages?: number
}

export const invoicesApi = {
  listGrouped: (filters: ListFilters = {}) => {
    const params: Record<string, string | number> = {}
    if (filters.q) params.q = filters.q
    if (filters.status) {
      params['filter[status]'] = Array.isArray(filters.status) ? filters.status.join(',') : filters.status
    }
    if (filters.type) {
      params['filter[type]'] = Array.isArray(filters.type) ? filters.type.join(',') : filters.type
    }
    if (filters.client_id)   params['filter[client_id]']   = filters.client_id
    if (filters.project_id)  params['filter[project_id]']  = filters.project_id
    if (filters.year)        params['filter[year]']        = filters.year
    if (filters.month)       params['filter[month]']       = filters.month
    if (filters.date_from)   params['filter[date_from]']   = filters.date_from
    if (filters.date_to)     params['filter[date_to]']     = filters.date_to
    if (filters.currency)    params['filter[currency]']    = filters.currency
    if (filters.unpaid_only) params['filter[unpaid_only]'] = 1
    if (filters.overdue)     params['filter[overdue]']     = 1
    if (filters.page)        params.page                   = filters.page
    if (filters.per_page)    params.per_page               = filters.per_page
    return api.get<{ data: MonthGroup[]; meta: InvoiceListMeta }>('/invoices', { params }).then(r => r.data)
  },

  /**
   * Plochý seznam OTEVŘENÝCH (nezaplacených) vystavených faktur a proforem pro picker
   * (např. kotva sloučené úhrady v bankovním párování). Vrací max `limit` položek
   * seřazených dle splatnosti. Hledá fulltextem (varsymbol + jméno klienta).
   */
  searchOpen: (q: string, limit = 20): Promise<InvoiceListItem[]> =>
    invoicesApi.listGrouped({
      q,
      status: ['issued', 'sent', 'reminded'],
      type: ['invoice', 'proforma'],
      unpaid_only: true,
      per_page: limit,
    }).then(r => r.data.flatMap(g => g.invoices)),

  /**
   * Jako searchOpen, ale vč. ZAPLACENÝCH faktur — pro kotvu sloučené úhrady, kde
   * spárování zaplacené faktury znamená rekonciliaci existující platby (proto musí
   * jít vybrat i 'paid'). Bez unpaid_only, status zahrnuje 'paid'.
   */
  searchMatchable: (q: string, limit = 20): Promise<InvoiceListItem[]> =>
    invoicesApi.listGrouped({
      q,
      status: ['issued', 'sent', 'reminded', 'paid'],
      type: ['invoice', 'proforma'],
      per_page: limit,
    }).then(r => r.data.flatMap(g => g.invoices)),

  exportCsv: (filters: ListFilters = {}) => {
    const params = new URLSearchParams()
    if (filters.q) params.set('q', filters.q)
    if (filters.status)     params.set('filter[status]',  Array.isArray(filters.status) ? filters.status.join(',') : filters.status)
    if (filters.type)       params.set('filter[type]',    Array.isArray(filters.type) ? filters.type.join(',') : filters.type)
    if (filters.client_id)  params.set('filter[client_id]',  String(filters.client_id))
    if (filters.year)       params.set('filter[year]',       String(filters.year))
    if (filters.date_from)  params.set('filter[date_from]',  filters.date_from)
    if (filters.date_to)    params.set('filter[date_to]',    filters.date_to)
    if (filters.currency)   params.set('filter[currency]',   filters.currency)
    return api.get<Blob>('/invoices/export.csv', { params, responseType: 'blob' })
  },

  get:    (id: number) => api.get<Invoice>(`/invoices/${id}`).then(r => r.data),
  /**
   * Vrátí náhled, jaké číslo faktura dostane při Vystavení (BEZ inkrementu counteru).
   * Používá se v editoru jako placeholder „automaticky: JD2026-01".
   */
  previewVarsymbol: (type: 'invoice' | 'proforma' | 'credit_note', issueDate: string, clientId?: number) =>
    api.get<{ varsymbol: string; has_template: boolean }>(
      `/invoices/preview-varsymbol`,
      { params: { type, issue_date: issueDate, ...(clientId ? { client_id: clientId } : {}) } },
    ).then(r => r.data),
  create: (payload: InvoicePayload) => api.post<Invoice>('/invoices', payload).then(r => r.data),
  update: (id: number, payload: InvoicePayload, force = false) =>
    api.put<Invoice>(`/invoices/${id}${force ? '?force=1' : ''}`, payload).then(r => r.data),
  /**
   * Smazání faktury. Pro draft kdokoliv ≥ accountant, pro vystavené/zaplacené/stornované jen admin.
   * Vrací `cascade_deleted` = počet navazujících dokladů (storno, dobropis), které byly
   * smazány zároveň přes ON DELETE CASCADE (migrace 0015).
   */
  delete: (id: number) => api.delete<{ ok: boolean; cascade_deleted: number }>(`/invoices/${id}`).then(r => r.data),

  // Akce nad fakturou
  issue:    (id: number) => api.post<Invoice>(`/invoices/${id}/issue`).then(r => r.data),
  markPaid: (id: number, paidAt?: string, opts?: { sendThanks?: boolean; thanksTrigger?: 'manual' | 'bulk' }) =>
    api.post<Invoice>(`/invoices/${id}/mark-paid`, {
      paid_at: paidAt || new Date().toISOString().slice(0, 10),
      ...(opts?.sendThanks ? { send_payment_thanks: true, thanks_trigger: opts.thanksTrigger || 'manual' } : {}),
    }).then(r => r.data),
  unmarkPaid: (id: number) =>
    api.post<Invoice>(`/invoices/${id}/unmark-paid`, {}).then(r => r.data),
  // Evidence plateb / částečné úhrady (#89)
  listPayments: (id: number) =>
    api.get<InvoicePaymentsResponse>(`/invoices/${id}/payments`).then(r => r.data),
  createPayment: (id: number, payload: {
    amount: number
    paid_on?: string
    variable_symbol?: string | null
    bank_reference?: string | null
    note?: string | null
    send_payment_thanks?: boolean
  }) =>
    api.post<{
      invoice: Invoice
      payments: InvoicePayment[]
      payment: InvoicePayment
      became_paid: boolean
      remaining: number
      payment_thanks?: { status: 'sent' | 'skipped' | 'failed'; reason?: string } | null
    }>(`/invoices/${id}/payments`, payload).then(r => r.data),
  deletePayment: (id: number, paymentId: number) =>
    api.delete<{ invoice: Invoice; payments: InvoicePayment[]; became_unpaid: boolean; remaining: number }>(
      `/invoices/${id}/payments/${paymentId}`,
    ).then(r => r.data),
  // Daňový doklad k přijaté platbě (DUZP = datum platby) — DRAFT, idempotentní
  createPaymentTaxDocument: (id: number, paymentId: number) =>
    api.post<{ tax_document_id: number; payments: InvoicePayment[] }>(
      `/invoices/${id}/payments/${paymentId}/tax-document`,
    ).then(r => r.data),
  cancel: (id: number, mode: 'internal' | 'credit_note', reason: string = '') =>
    api.post<{ cancellation_id?: number; credit_note_id?: number; edit_url?: string; invoice?: Invoice }>(
      `/invoices/${id}/cancel`,
      { mode, reason },
    ).then(r => r.data),
  issueFinal: (proformaId: number, opts?: { tax_date?: string; due_date?: string; advance_paid_amount?: number | null }) =>
    api.post<{ final_invoice_id: number; edit_url: string; invoice: Invoice }>(
      `/invoices/${proformaId}/issue-final`,
      opts || {},
    ).then(r => r.data),
  // Zpětné propojení daňového dokladu se zálohovou fakturou (proforma)
  advanceCandidates: (id: number) =>
    api.get<{ candidates: AdvanceCandidate[] }>(`/invoices/${id}/advance-candidates`).then(r => r.data.candidates),
  // Opačný směr — z detailu zálohy nabídni nepropojené daňové doklady téhož odběratele
  finalCandidates: (id: number) =>
    api.get<{ candidates: AdvanceCandidate[] }>(`/invoices/${id}/final-candidates`).then(r => r.data.candidates),
  linkAdvance: (id: number, advanceId: number) =>
    api.post<Invoice>(`/invoices/${id}/link-advance`, { advance_id: advanceId }).then(r => r.data),
  unlinkAdvance: (id: number) =>
    api.delete<Invoice>(`/invoices/${id}/link-advance`).then(r => r.data),
  clone: (id: number, opts?: { increment_month_in_descriptions?: boolean; issue_date?: string }) =>
    api.post<{ draft_id: number }>(`/invoices/${id}/clone`, opts || {}).then(r => r.data),
  bulkReissue: (invoiceIds: number[], opts?: { increment_month_in_descriptions?: boolean; issue_date?: string }) =>
    api.post<{ created: Array<{ source_id: number; draft_id: number }>; errors: Array<{ source_id: number; error: string }> }>(
      '/invoices/bulk-reissue',
      { invoice_ids: invoiceIds, ...opts },
    ).then(r => r.data),

  pdfUrl: (id: number, download: boolean = false) => {
    // Přímá navigace v prohlížeči neposílá X-Supplier-Id header (na rozdíl od axios) —
    // proto přidáváme supplier_id jako query param. Middleware ho přečte jako fallback.
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (download) params.set('download', '1')
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/invoices/${id}/pdf${qs ? '?' + qs : ''}`
  },

  importedPdfUrl: (id: number, inline: boolean = false) => {
    // Přímá navigace / iframe / <a href> neposílá X-Supplier-Id header (na rozdíl od
    // axios) — proto přidáváme supplier_id jako query param (middleware ho čte jako fallback).
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (inline) params.set('inline', '1')
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/invoices/${id}/imported-pdf${qs ? '?' + qs : ''}`
  },

  listPdfs: (id: number) =>
    api.get<{ items: Array<{
      id: number
      filename: string
      size_bytes: number
      sha256: string
      was_sent: boolean
      sent_to: string[] | null
      reason: string
      archived_at: string
    }> }>(`/invoices/${id}/pdfs`).then(r => r.data.items),

  archivedPdfUrl: (id: number, archiveId: number, download: boolean = false) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (download) params.set('download', '1')
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/invoices/${id}/pdfs/${archiveId}${qs ? '?' + qs : ''}`
  },

  send: (id: number, payload?: { to?: string[]; cc?: string[]; bcc?: string[]; subject_override?: string | null; note?: string }) =>
    api.post<{ sent_to: string[]; cc: string[]; bcc: string[]; sent_at: string; is_test: false }>(
      `/invoices/${id}/send`,
      payload || {},
    ).then(r => r.data),

  /** Vyřešení příjemci dle kontaktů klienta / e-mailů zakázky (#86) — pro prefill modalu s provenancí. */
  recipients: (id: number, type: 'documents' | 'reminders' | 'approvals' = 'documents') =>
    api.get<{
      type: string
      to: string[]
      cc: string[]
      bcc: string[]
      resolved: Array<{ email: string; recipient: 'to' | 'cc' | 'bcc'; source: 'contact' | 'project' | 'main_email'; usage: string | null; label: string | null }>
    }>(`/invoices/${id}/recipients`, { params: { type } }).then(r => r.data),

  sendReminder: (id: number) =>
    api.post<{ invoice: Invoice; sent_to: string[]; days_overdue: number; sent_at: string }>(
      `/invoices/${id}/reminder`,
    ).then(r => r.data),

  bulkSendReminders: (invoiceIds: number[]) =>
    api.post<{
      sent: Array<{ invoice_id: number; sent_to: string[]; days_overdue: number }>;
      errors: Array<{ invoice_id: number; error: string }>;
    }>('/invoices/bulk-reminder', { invoice_ids: invoiceIds }).then(r => r.data),

  activity: (id: number) =>
    api.get<Array<{
      id: number; user_id: number | null; user_email: string | null; user_name: string | null;
      action: string; payload: Record<string, unknown> | null; ip: string | null; created_at: string;
    }>>(`/invoices/${id}/activity`).then(r => r.data),

  sendTest: (id: number) =>
    api.post<{ sent_to: string[]; sent_at: string; is_test: true }>(
      `/invoices/${id}/send-test`,
      {},
    ).then(r => r.data),

  sendTestReminder: (id: number) =>
    api.post<{ sent_to: string[]; sent_at: string; days_overdue: number; is_test: true }>(
      `/invoices/${id}/reminder-test`,
      {},
    ).then(r => r.data),

  // Schvalování výkazu zákazníkem
  requestApproval: (id: number) =>
    api.post<{ sent_to: string[]; sent_at: string; invoice: Invoice }>(
      `/invoices/${id}/request-approval`,
      {},
    ).then(r => r.data),

  requestApprovalTest: (id: number) =>
    api.post<{ sent_to: string[]; sent_at: string; is_test: true }>(
      `/invoices/${id}/request-approval-test`,
      {},
    ).then(r => r.data),

  updateApprovalStatus: (id: number, status: ApprovalStatus, rejectionReason?: string) =>
    api.put<{
      invoice: Invoice
      auto_send?: { issued: boolean; sent_to: string[]; varsymbol: string | null }
      auto_send_error?: string
    }>(
      `/invoices/${id}/approval-status`,
      { status, rejection_reason: rejectionReason || null },
    ).then(r => r.data),

  // Volitelné přílohy k dokladu (přibalí se při odeslání faktury / proformy / dobropisu)
  listAttachments: (invoiceId: number) =>
    api.get<{ items: InvoiceAttachment[] }>(`/invoices/${invoiceId}/attachments`).then(r => r.data.items),
  uploadAttachments: (invoiceId: number, files: File[]) => {
    const fd = new FormData()
    for (const f of files) fd.append('file[]', f, f.name)
    return api.post<{ created: number[]; items: InvoiceAttachment[]; total_size: number }>(
      `/invoices/${invoiceId}/attachments`,
      fd,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data)
  },
  deleteAttachment: (invoiceId: number, attachmentId: number) =>
    api.delete<{ deleted: number }>(`/invoices/${invoiceId}/attachments/${attachmentId}`).then(r => r.data),
  attachmentUrl: (invoiceId: number, attachmentId: number, download: boolean = false) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (download) params.set('download', '1')
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/invoices/${invoiceId}/attachments/${attachmentId}${qs ? '?' + qs : ''}`
  },

  // Work report (výkaz víceprací)
  getWorkReport: (invoiceId: number) =>
    api.get<WorkReport | null>(`/invoices/${invoiceId}/work-report`).then(r => r.data),
  saveWorkReport: (invoiceId: number, payload: WorkReportPayload, force = false) =>
    api.put<WorkReport>(`/invoices/${invoiceId}/work-report`, payload, {
      params: force ? { force: 1 } : undefined,
    }).then(r => r.data),
  saveWorkReportMaterials: (invoiceId: number, payload: WorkReportMaterialsPayload, force = false) =>
    api.put<WorkReport>(`/invoices/${invoiceId}/work-report/materials`, payload, {
      params: force ? { force: 1 } : undefined,
    }).then(r => r.data),
  deleteWorkReport: (invoiceId: number, force = false) =>
    api.delete<{ deleted: true }>(`/invoices/${invoiceId}/work-report`, {
      params: force ? { force: 1 } : undefined,
    }).then(r => r.data),
}

export interface InvoiceAttachment {
  id: number
  invoice_id: number
  filename: string
  original_name: string
  size_bytes: number
  sha256: string
  mime_type: string
  uploaded_by: number | null
  uploaded_at: string
}

export interface WorkReportItem {
  id?: number
  description: string
  work_date?: string | null
  hours: number
  rate: number
  total_amount?: number
  order_index: number
}

export interface WorkReportMaterial {
  id?: number
  description: string
  quantity: number
  unit: string
  /** Cena/MJ v cenové konvenci dokladu (prices_include_vat). */
  unit_price: number
  total_amount?: number
  order_index: number
}

export interface WorkReport {
  id: number
  invoice_id: number
  project_id: number | null
  title: string
  total_hours: number
  total_amount: number
  /** Sazba DPH práce (12/21); null = fallback default faktury. */
  vat_rate_id: number | null
  material_title: string | null
  material_total: number
  material_vat_rate_id: number | null
  items: WorkReportItem[]
  materials: WorkReportMaterial[]
}

export interface WorkReportPayload {
  project_id: number | null
  title: string
  vat_rate_id?: number | null
  items: Array<{
    description: string
    work_date?: string | null
    hours: number
    rate: number
    order_index: number
  }>
}

export interface WorkReportMaterialsPayload {
  project_id: number | null
  material_title: string
  material_vat_rate_id: number | null
  materials: Array<{
    description: string
    quantity: number
    unit: string
    unit_price: number
    order_index: number
  }>
}

import { api } from './client'

export interface BankStatement {
  id: number
  /** Zdroj výpisu: 'gpc' = nahraný/importovaný GPC výpis, 'pdf' = rozparsovaný PDF výpis (banka bez GPC exportu), 'email_notice' = měsíční agregát e-mailových avíz. */
  source?: 'gpc' | 'pdf' | 'email_notice'
  file_name: string
  account_number: string
  /** Kód banky (4místný), pokud je u výpisu evidovaný — pro zobrazení „účet / kód". */
  bank_code?: string | null
  /** Vlastní pojmenování účtu z currencies.label (např. "CZK — Fio Bank"), pokud match. */
  account_label: string | null
  currency: string | null
  statement_date: string
  statement_number: string | null
  prev_balance: number
  curr_balance: number
  transaction_count: number
  matched_count: number
  imported_at: string
  has_file: boolean
  /** Je k výpisu přiložené PDF (bank_statements.pdf_content)? */
  has_pdf: boolean
  /** Původní název nahraného PDF, pokud je. */
  pdf_name?: string | null
}

export type MatchStatus = 'unmatched' | 'auto_exact' | 'auto_partial' | 'manual' | 'ignored'

export interface BankTransaction {
  id: number
  /** 'statement' = z nahraného výpisu, 'email_notice' = z e-mailového avíza. */
  source?: 'statement' | 'email_notice'
  statement_id: number
  posted_at: string
  amount: number
  /** Disponibilní zůstatek účtu z e-mailového avíza (Creditas/Fio/RB); u GPC transakcí null. */
  balance?: number | null
  currency: string | null
  variable_symbol: string | null
  constant_symbol: string | null
  specific_symbol: string | null
  counterparty_account: string | null
  counterparty_bank: string | null
  counterparty_name: string | null
  description: string | null
  bank_ref: string | null
  matched_invoice_id: number | null
  matched_purchase_invoice_id?: number | null
  matched_varsymbol?: string | null
  matched_invoice_amount?: number | null
  matched_client_name?: string | null
  /** Číslo přijaté faktury (vendor_invoice_number, fallback varsymbol), pokud je transakce spárovaná s přijatou. */
  matched_purchase_ref?: string | null
  /** Název dodavatele spárované přijaté faktury. */
  matched_vendor_name?: string | null
  /** Seznam vystavených faktur uhrazených touto transakcí (sloučená úhrada → víc než 1). */
  matched_invoices?: MatchedInvoice[]
  match_status: MatchStatus
  matched_at: string | null
}

/** Jedna vystavená faktura uhrazená bankovní transakcí (z invoice_payments). */
export interface MatchedInvoice {
  invoice_id: number
  varsymbol: string | null
  invoice_type: string
  amount: number
  client_name: string | null
}

/** Kandidát na spárování dle částky + data (±14 dní, fallback ±90 dní) — vystavená i přijatá faktura. */
export interface MatchCandidate {
  type: 'invoice' | 'purchase_invoice'
  id: number
  ref: string | null
  amount: number
  currency: string
  /** Částka přepočtená do měny transakce (jen u cross-currency, jinak null). */
  converted_amount: number | null
  converted_currency: string | null
  issue_date: string
  due_date: string | null
  party: string | null
  /** Faktura je už zaplacená — UI zobrazí varovný štítek (duplicitní/druhá platba). */
  paid: boolean
  /** Fallback kandidát bez FX převodu — syrová částka sedí, ale měna faktury neodpovídá
   *  měně transakce (klient zaplatil "stejné číslo" z cizoměnového účtu). Ověřit ručně. */
  currency_mismatch: boolean
}

/** Jedna faktura v návrhu sloučené úhrady. */
export interface SplitSuggestionInvoice {
  id: number
  ref: string | null
  amount: number
  currency: string
  /** Částka přepočtená do měny platby (jen u cross-currency, jinak null). */
  converted: number | null
  /** Faktura je už zaplacená → spárování = rekonciliace existující platby (ne nová úhrada). */
  is_paid?: boolean
  issue_date: string
  due_date: string | null
}

/** Návrh kombinace faktur jednoho klienta, jejíž součet odpovídá příchozí platbě. */
export interface SplitSuggestion {
  client_id: number
  client_name: string | null
  currency: string
  total: number
  count: number
  invoices: SplitSuggestionInvoice[]
}

export interface BankStatementDetail extends BankStatement {
  credit_total: number
  debit_total: number
  transactions: BankTransaction[]
}

export interface ImportResult {
  statement_id: number
  transactions: number
  matched: number
  duplicate: boolean
}

/**
 * Kandidát bankovního účtu při nejednoznačném sdíleném čísle účtu. Nastane, když
 * jednomu číslu účtu odpovídá víc účtů dodavatele — buď různými měnami (#167),
 * nebo různým kódem banky (#206, stejné číslo u dvou bank). `label` už je
 * server-side složený tak, aby oba případy odlišil (měna + číslo/kód banky).
 */
export interface AmbiguousAccount {
  account_id: number
  code: string
  label: string
  bank_code?: string | null
  account_number?: string
}

/** Účet pro filtr v přehledu výpisů (distinct account_number + jeho label z currencies). */
export interface BankAccountOption {
  account_number: string
  bank_code?: string | null
  label: string | null
}

export interface BankStatementPage {
  items: BankStatement[]
  total: number
  page: number
  limit: number
  /** Roky přítomné ve výpisech (pro filtr rok), descending. */
  years: number[]
  /** Účty přítomné ve výpisech (pro filtr na číslo účtu). */
  accounts: BankAccountOption[]
  /** Je v cfg.php nastavené adresářové skenování (bank_import.scan_root)? Řídí tlačítko „Skenovat adresář". */
  scan_configured: boolean
}

export interface BankListParams {
  page?: number
  year?: number | ''
  month?: number | ''
  account?: string
  bank_code?: string
}

/** Jeden bod měsíční řady zůstatku (nativní měna účtu). */
export interface AccountBalanceMonth {
  /** 'YYYY-MM'. */
  month: string
  /** Závěrečný zůstatek za měsíc (carry-forward), null když účet ještě neexistoval. */
  balance: number | null
}

/** Stav jednoho bankovního účtu dle GPC výpisů a zůstatků z e-mailových avíz. */
export interface AccountBalance {
  /** currencies.id */
  id: number
  code: string
  label: string
  account_number: string
  bank_code: string | null
  is_default: boolean
  /** Aktuální stav = nejnovější známý zůstatek (GPC výpis, nebo čerstvější avízo). */
  current_balance: number
  /** Aktuální stav přepočtený na CZK aktuálním kurzem; null když měna nemá kurz. */
  current_balance_czk: number | null
  /** Datum, ke kterému aktuální stav platí (výpis / avízo). */
  statement_date: string
  /** Odkud aktuální stav pochází: GPC výpis, nebo disponibilní zůstatek z avíza. */
  current_source: 'gpc' | 'email_notice'
  statement_count: number
  months: AccountBalanceMonth[]
}

export interface AccountBalancesResponse {
  base_currency: string
  accounts: AccountBalance[]
  total_czk: {
    current: number
    months: { month: string; balance_czk: number | null }[]
  }
  /** Měny bez jakéhokoli kurzu v cache (nešly přepočíst na CZK). */
  missing_rates: string[]
}

export const bankApi = {
  list: (params: BankListParams = {}) =>
    api.get<BankStatementPage>('/bank-statements', { params: {
      page: params.page ?? 1,
      ...(params.year !== undefined && params.year !== '' ? { 'filter[year]': params.year } : {}),
      ...(params.month !== undefined && params.month !== '' ? { 'filter[month]': params.month } : {}),
      ...(params.account ? { 'filter[account]': params.account } : {}),
      ...(params.bank_code ? { 'filter[bank_code]': params.bank_code } : {}),
    } }).then(r => r.data),
  get: (id: number) => api.get<BankStatementDetail>(`/bank-statements/${id}`).then(r => r.data),
  /** Přehled zůstatků na účtech dle GPC výpisů (tabulka + měsíční vývoj + CZK součet). */
  accountBalances: () =>
    api.get<AccountBalancesResponse>('/bank-statements/account-balances').then(r => r.data),
  /**
   * Nahraje GPC/ABO výpis. `accountId` (currencies.id) je volitelný — povinný jen
   * u víceměnového účtu se sdíleným číslem účtu, kdy server vrátí 409
   * `ambiguous_account_currency` se seznamem kandidátů (#167).
   */
  upload: (file: File, accountId?: number) => {
    const fd = new FormData()
    fd.append('file', file)
    if (accountId !== undefined) fd.append('account_id', String(accountId))
    return api.post<ImportResult>('/bank-statements/upload', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  /**
   * Nahraje a rozparsuje PDF výpis banky bez GPC/ABO exportu (Creditas jako první,
   * rozšiřitelné). Stejná 409 `ambiguous_account_currency` volba účtu jako `upload()`.
   */
  importPdf: (file: File, accountId?: number) => {
    const fd = new FormData()
    fd.append('file', file)
    if (accountId !== undefined) fd.append('account_id', String(accountId))
    return api.post<ImportResult>('/bank-statements/upload-pdf', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  /** `fallback=true` = v ±14 dnech nic nesedělo, vráceny širší (±90 dní) a/nebo cross-currency návrhy. */
  matchCandidates: (txId: number) =>
    api.get<{ candidates: MatchCandidate[]; fallback: boolean }>(`/bank-transactions/${txId}/match-candidates`)
      .then(r => r.data),
  matchManual: (txId: number, ref: { invoiceId?: number; purchaseInvoiceId?: number; varsymbol?: string }) =>
    api.post<{ matched: true; paid_at?: string; purchase_invoice_id?: number }>(`/bank-transactions/${txId}/match`, {
      ...(ref.invoiceId ? { invoice_id: ref.invoiceId } : {}),
      ...(ref.purchaseInvoiceId ? { purchase_invoice_id: ref.purchaseInvoiceId } : {}),
      ...(ref.varsymbol ? { varsymbol: ref.varsymbol } : {}),
    }).then(r => r.data),
  /** Sloučená úhrada: jedna příchozí platba → více vystavených faktur (téhož klienta). */
  matchMultiple: (txId: number, invoiceIds: number[]) =>
    api.post<{ matched: true; split: true; paid_at?: string; invoice_ids: number[]; final_draft_ids?: number[] }>(
      `/bank-transactions/${txId}/match`, { invoice_ids: invoiceIds },
    ).then(r => r.data),
  /** Návrhy sloučené úhrady (kombinace faktur jednoho klienta dle částky + okna dní). */
  splitSuggestions: (txId: number, opts: { invoiceId?: number; window?: number; max?: number } = {}) =>
    api.get<{ suggestions: SplitSuggestion[]; window: number; max: number }>(
      `/bank-transactions/${txId}/split-suggestions`,
      { params: {
        ...(opts.invoiceId ? { invoice_id: opts.invoiceId } : {}),
        ...(opts.window ? { window: opts.window } : {}),
        ...(opts.max ? { max: opts.max } : {}),
      } },
    ).then(r => r.data),
  ignore: (txId: number) =>
    api.post<{ ignored: true }>(`/bank-transactions/${txId}/ignore`, {}).then(r => r.data),
  unmatch: (txId: number) =>
    api.post<{ unmatched: true }>(`/bank-transactions/${txId}/unmatch`, {}).then(r => r.data),
  createPurchaseInvoice: (txId: number, vendorId: number) =>
    api.post<{ purchase_invoice_id: number; vendor_id: number; currency: string }>(
      `/bank-transactions/${txId}/create-purchase-invoice`, { vendor_id: vendorId },
    ).then(r => r.data),
  rematch: (statementId: number) =>
    api.post<{ considered: number; newly_matched: number; newly_partial: number; still_unmatched: number }>(
      `/bank-statements/${statementId}/rematch`, {}).then(r => r.data),
  scan: () => api.post<{ scanned: number; imported: number; duplicate: number; errors: number }>(
    '/bank-statements/scan', {},
  ).then(r => r.data),
  delete: (id: number) =>
    api.delete<{ deleted: true }>(`/bank-statements/${id}`).then(r => r.data),
  /**
   * Build download URL pro originální GPC. Vrací absolutní URL — UI ji použije
   * v `<a href>` (browser stáhne přímo). Auth cookie se posílá automaticky.
   */
  downloadUrl: (id: number): string => {
    const base = api.defaults.baseURL ?? ''
    return `${base.replace(/\/$/, '')}/bank-statements/${id}/download`
  },
  /** Download URL přiloženého PDF výpisu (analogie downloadUrl pro GPC). */
  pdfUrl: (id: number): string => {
    const base = api.defaults.baseURL ?? ''
    return `${base.replace(/\/$/, '')}/bank-statements/${id}/pdf`
  },
  uploadPdf: (id: number, file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post<{ uploaded: true; pdf_name: string }>(`/bank-statements/${id}/pdf`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  deletePdf: (id: number) =>
    api.delete<{ deleted: true }>(`/bank-statements/${id}/pdf`).then(r => r.data),
}

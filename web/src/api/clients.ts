import { api } from './client'

export interface Client {
  id: number
  company_name: string
  first_name?: string | null
  last_name?: string | null
  ic?: string | null
  dic?: string | null
  street: string
  city: string
  zip: string
  country_iso2: string
  main_email: string
  phone?: string | null
  language: 'cs' | 'en'
  currency_default_id: number
  currency_default: string
  reverse_charge: boolean
  is_customer?: boolean
  is_vendor?: boolean
  auto_send_reminders: boolean
  payment_due_default?: number | null
  payment_due_unit?: 'days' | 'month' | null
  hourly_rate: number
  note?: string | null
  default_expense_category_id?: number | null
  // Vrací UpdateClientAction: počet přijatých faktur, do kterých byla doplněna
  // nově nastavená výchozí kategorie nákladu (pro toast po uložení dodavatele).
  expense_category_backfilled?: number
  invoice_number_format?: string | null
  proforma_number_format?: string | null
  credit_note_number_format?: string | null
  invoice_number_period?: 'year' | 'month' | 'none' | null
  archived_at?: string | null
  active_projects_count?: number
  invoices_count?: number
  purchase_invoices_count?: number
  projects?: ProjectSummary[]
  // total_czk fieldy slouží pro multi-currency klienty, kde frontend agreguje obraty z více měn
  // do CZK (přepočet přes i.exchange_rate fixovaný k DUZP). Single-currency klienti je ignorují.
  revenue_by_month?: Array<{ month: string; currency: string; total: number; total_czk: number }>
  revenue_by_year?:  Array<{ year: number; currency: string; total: number; total_czk: number; count: number }>
  revenue_by_project?: Array<{ project_id: number | null; project_name: string | null; currency: string; total: number; total_czk: number; count: number }>
  // Náklady (purchase_invoices) — server-side aggregované, ne závislé na paginaci listu.
  costs_by_month?: Array<{ month: string; currency: string; total: number; total_czk: number }>
  costs_by_year?:  Array<{ year: number; currency: string; total: number; total_czk: number; count: number }>
  unpaid_summary?:   Array<{ currency: string; unpaid_total: number; unpaid_total_czk: number; unpaid_count: number; overdue_total: number; overdue_total_czk: number; overdue_count: number }>
  // Cache stats z client_revenue_cache (per c.currency_default) + live computed costs
  revenue?: number
  costs?: number
  purchase_count?: number
  last_purchase_date?: string | null
  last_invoice_date?: string | null
  invoice_count?: number
  created_at?: string
  updated_at?: string
}

export interface ProjectSummary {
  id: number
  name: string
  status: 'active' | 'paused' | 'closed'
  currency: string
  hourly_rate: number
  payment_due_days: number
  project_number?: string | null
}

export interface AresLookupResult {
  found: boolean
  source: 'cache' | 'fresh'
  data?: {
    company_name: string
    ic: string
    dic: string
    street: string
    city: string
    zip: string
    country_iso2: string
    is_vat_payer: boolean
    date_active?: string
    legal_form?: string
  }
}

export interface ViesLookupResult {
  valid: boolean
  source: 'cache' | 'rest' | 'soap' | 'ares' | 'error'
  name?: string
  address?: string
  parsed?: {
    street: string
    city: string
    zip: string
  } | null
  country?: string
  vat_number?: string
}

export interface ClientPayload {
  company_name: string
  first_name?: string | null
  last_name?: string | null
  ic?: string | null
  dic?: string | null
  street: string
  city: string
  zip: string
  country_iso2: string
  main_email: string
  phone?: string | null
  language: 'cs' | 'en'
  currency_default_id: number
  reverse_charge: boolean
  is_customer?: boolean
  is_vendor?: boolean
  auto_send_reminders: boolean
  payment_due_default?: number | null
  payment_due_unit?: 'days' | 'month' | null
  hourly_rate?: number
  note?: string | null
  default_expense_category_id?: number | null
  invoice_number_format?: string | null
  proforma_number_format?: string | null
  credit_note_number_format?: string | null
  invoice_number_period?: 'year' | 'month' | 'none' | null
}

export interface ListResponse<T> {
  data: T[]
  meta: { total: number; page: number; per_page: number; pages: number }
}

export interface ClientListResponse {
  data: Client[]
  meta: {
    total: number
    page: number
    per_page: number
    pages: number
    role_counts?: { all: number; customers: number; vendors: number }
  }
}

export type ClientRoleFilter = 'all' | 'customers' | 'vendors'

export const clientsApi = {
  list: (params?: { q?: string; page?: number; per_page?: number; archived?: boolean; role?: ClientRoleFilter; sort?: 'name' | 'revenue' | 'last_activity'; expense_category_id?: number | null }) =>
    api
      .get<ClientListResponse>('/clients', {
        params: {
          q: params?.q || undefined,
          page: params?.page,
          per_page: params?.per_page,
          sort: params?.sort,
          role: params?.role && params.role !== 'all' ? params.role : undefined,
          expense_category_id: params?.expense_category_id || undefined,
          ...(params?.archived ? { 'filter[archived]': 1 } : {}),
        },
      })
      .then((r) => r.data),

  get: (id: number) => api.get<Client>(`/clients/${id}`).then((r) => r.data),

  create: (payload: ClientPayload) => api.post<Client>('/clients', payload).then((r) => r.data),
  update: (id: number, payload: ClientPayload) =>
    api.put<Client>(`/clients/${id}`, payload).then((r) => r.data),

  archive:   (id: number) => api.post(`/clients/${id}/archive`),
  unarchive: (id: number) => api.post(`/clients/${id}/unarchive`),
  delete:    (id: number) => api.delete(`/clients/${id}`).then((r) => r.data),

  lookupAres: (ic: string) =>
    api.post<AresLookupResult>('/clients/lookup-ares', { ic }).then((r) => r.data),
  lookupVies: (vatId: string) =>
    api.post<ViesLookupResult>('/clients/lookup-vies', { vat_id: vatId }).then((r) => r.data),
}

import { api } from './client'

export interface SupplierListItem {
  id: number
  company_name: string
  display_name: string | null
  ic: string | null
  dic: string | null
  is_vat_payer: boolean
  email: string
  country_iso: string
  clients_count: number
  invoices_count: number
}

export interface Supplier {
  id: number
  company_name: string
  display_name: string | null
  street: string
  city: string
  zip: string
  country_id: number
  country_iso: string
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
}

export interface SupplierCreatePayload {
  company_name: string
  display_name?: string
  street: string
  city: string
  zip: string
  country_iso2?: string
  ic?: string
  dic?: string
  is_vat_payer?: boolean
  email: string
  phone?: string
  web?: string
  tagline?: string
  default_payment_due_days?: number
  default_payment_due_unit?: 'days' | 'month'
  default_hourly_rate?: number
}

export const suppliersApi = {
  list: () => api.get<SupplierListItem[]>('/suppliers').then(r => r.data),
  get: (id: number) => api.get<Supplier>(`/suppliers/${id}`).then(r => r.data),
  create: (payload: SupplierCreatePayload) =>
    api.post<{ id: number }>('/suppliers', payload).then(r => r.data),
  update: (id: number, payload: Partial<Supplier>) =>
    api.put<Supplier>(`/suppliers/${id}`, payload).then(r => r.data),
  delete: (id: number) =>
    api.delete<{ deleted: true }>(`/suppliers/${id}`).then(r => r.data),
}

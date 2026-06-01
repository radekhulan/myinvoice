import { api } from './client'

export interface ActivityLogEntry {
  id: number
  user_id: number | null
  user_email: string | null
  user_name: string | null
  action: string
  entity_type: string | null
  entity_id: number | null
  payload: Record<string, unknown> | null
  ip: string | null
  created_at: string
}

export interface ActivityLogResponse {
  data: ActivityLogEntry[]
  total: number
  limit: number
  offset: number
  actions: Array<{ action: string; cnt: number }>
}

export interface SentEmail {
  id: number
  action: string
  created_at: string
  user_name: string | null
  user_email: string | null
  invoice_id: number | null
  invoice_varsymbol: string | null
  client_company_name: string | null
  recipients: string[]
  smtp_response: string | null
}

export interface SentEmailsResponse {
  data: SentEmail[]
  total: number
  limit: number
  offset: number
  types: Array<{ action: string; cnt: number }>
}

export interface AdminUser {
  id: number
  email: string
  name: string
  role: 'admin' | 'accountant' | 'readonly'
  locale: 'cs' | 'en'
  is_active: boolean
  created_at: string
  last_login_at: string | null
}

export const adminApi = {
  activityLog: (params: { action?: string; user_id?: number; entity_type?: string; entity_id?: number; limit?: number; offset?: number } = {}) =>
    api.get<ActivityLogResponse>('/admin/activity-log', { params }).then(r => r.data),

  sentEmails: (params: { type?: string; limit?: number; offset?: number } = {}) =>
    api.get<SentEmailsResponse>('/admin/sent-emails', { params }).then(r => r.data),

  // Users
  listUsers: () => api.get<AdminUser[]>('/admin/users').then(r => r.data),
  createUser: (payload: { email: string; name: string; role: AdminUser['role']; locale?: 'cs' | 'en'; password: string }) =>
    api.post<AdminUser>('/admin/users', payload).then(r => r.data),
  updateUser: (id: number, payload: Partial<{ name: string; role: AdminUser['role']; locale: 'cs' | 'en'; is_active: boolean; password: string }>) =>
    api.put<AdminUser>(`/admin/users/${id}`, payload).then(r => r.data),
  deleteUser: (id: number) => api.delete(`/admin/users/${id}`),

  // Approvals inbox
  listApprovals: (params: { status?: 'requested' | 'approved' | 'rejected' | 'all'; overdue_days?: number; page?: number; per_page?: number } = {}) =>
    api.get<ApprovalListResponse>('/admin/approvals', { params }).then(r => r.data),

  // Email templates
  listEmailTemplates: () =>
    api.get<{ data: EmailTemplateListItem[] }>('/admin/email-templates').then(r => r.data.data),
  getEmailTemplate: (code: string, locale: string) =>
    api.get<EmailTemplate>(`/admin/email-templates/${code}/${locale}`).then(r => r.data),
  saveEmailTemplate: (code: string, locale: string, payload: { subject: string; body_html: string; body_text: string }) =>
    api.put(`/admin/email-templates/${code}/${locale}`, payload),
  resetEmailTemplate: (code: string, locale: string) =>
    api.delete(`/admin/email-templates/${code}/${locale}`),

  // Cron jobs (Systém → Plánované úlohy)
  cronJobs: () => api.get<CronJobsResponse>('/admin/cron-jobs').then(r => r.data),
  runCronJob: (script: string) =>
    api.post<{ script: string; started: boolean }>(`/admin/cron-jobs/${encodeURIComponent(script)}/run`).then(r => r.data),
}

export type CronJobHealth = 'ok' | 'overdue' | 'failing' | 'overdue_and_failing' | 'never_ran'

export interface CronJob {
  script: string
  recommended: string
  linux_cron: string
  windows_schtasks: string
  weekdays_only: boolean
  critical: boolean
  max_age_hours: number
  health: CronJobHealth
  last_started_at: string | null
  last_finished_at: string | null
  last_status: 'running' | 'ok' | 'error' | null
  last_duration_ms: number | null
  last_exit_code: number | null
  last_host: string | null
  last_message: string | null
  last_report: Record<string, unknown> | null
  last_ok_started_at: string | null
  last_ok_finished_at: string | null
  age_sec_since_ok: number | null
  counts_24h: { ok: number; error: number; total: number }
}

export interface CronJobsResponse {
  jobs: CronJob[]
  server_time: string
}

export interface ApprovalListMeta {
  total: number
  page: number
  per_page: number
  pages: number
  status_counts?: { all: number; requested: number; approved: number; rejected: number }
}

export interface ApprovalListResponse {
  data: ApprovalInboxItem[]
  meta: ApprovalListMeta
}

export interface ApprovalInboxItem {
  id: number
  varsymbol: string | null
  invoice_type: 'invoice' | 'proforma' | 'credit_note' | 'cancellation'
  status: string
  client_id: number
  project_id: number | null
  client_company_name: string
  client_main_email: string | null
  project_name: string | null
  currency: string
  total_with_vat: number
  amount_to_pay: number
  approval_status: 'none' | 'requested' | 'approved' | 'rejected'
  approval_token: string | null
  approval_token_expires_at: string | null
  approval_requested_at: string | null
  approval_decided_at: string | null
  approval_decided_by_email: string | null
  approval_rejection_reason: string | null
  approval_reminder_at: string | null
  approval_reminder_count: number
}

export interface EmailTemplateListItem {
  code: string
  locale: 'cs' | 'en'
  has_override: boolean
  updated_at: string | null
}

export interface EmailTemplate {
  code: string
  locale: 'cs' | 'en'
  subject: string
  body_html: string
  body_text: string
  has_override: boolean
  updated_at: string | null
  defaults: { subject: string; body_html: string; body_text: string }
}

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
  /** Logický typ e-mailu = success akce (i u failed řádku) — podle něj se vybírá popisek/badge. */
  type: string
  status: 'sent' | 'failed'
  created_at: string
  user_name: string | null
  user_email: string | null
  invoice_id: number | null
  invoice_varsymbol: string | null
  client_company_name: string | null
  recipients: string[]
  smtp_response: string | null
  /** Chybový text u status='failed', jinak null. */
  error: string | null
}

export interface SentEmailsResponse {
  data: SentEmail[]
  total: number
  limit: number
  offset: number
  types: Array<{ action: string; cnt: number; failed: number }>
  failed_total: number
}

/** Jedna jednotná událost z logu poštovního serveru (kind: submission|delivery|notice). */
export interface SmtpLogEvent {
  ts: string
  kind: 'submission' | 'delivery' | 'notice'
  status: 'delivered' | 'queued' | 'deferred' | 'rejected' | 'error' | 'info'
  mail_from: string | null
  recipients: string[]
  remote_host: string | null
  remote_ip: string | null
  code: number | null
  response: string | null
  message_id: string | null
  source_file: string
  session: string
  subject: string | null
  /** Doplněno korelací s activity_log (odeslané e-maily aplikace), jinak null. */
  invoice_id: number | null
  invoice_varsymbol: string | null
}

export interface SmtpLogRecipientRollup {
  recipient: string
  delivered: number
  deferred: number
  rejected: number
  error: number
  last_ts: string
  last_status: string
}

export interface SmtpLogAnalysis {
  enabled: boolean
  reason: string | null
  path?: string
  glob_matched?: number
  connector: { key: string; label: string } | null
  connectors: Array<{ key: string; label: string }>
  window?: { files_total: number; files_parsed: number; limited: boolean }
  scanned: Array<{ file: string; size: number | null; truncated: boolean; events: number }>
  summary: {
    total_events: number
    deliveries: number
    submissions: number
    by_status: Record<string, number>
    by_day: Record<string, Record<string, number>>
    by_host: Record<string, Record<string, number>>
    recipients: SmtpLogRecipientRollup[]
    problems: SmtpLogEvent[]
  }
  events: SmtpLogEvent[]
  total: number
  limit: number
  offset: number
}

/** SMTP analýza vázaná na fakturu (box v detailu faktury). */
export interface InvoiceSmtpLog {
  enabled: boolean
  sent: boolean
  connector: { key: string; label: string } | null
  sends: Array<{ ts: string; recipients: string[]; action: string }>
  recipients: Array<{
    recipient: string
    delivered: number
    deferred: number
    rejected: number
    error: number
    last_status: string | null
    last_ts: string
  }>
  events: SmtpLogEvent[]
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

  sentEmails: (params: { type?: string; status?: 'sent' | 'failed'; limit?: number; offset?: number } = {}) =>
    api.get<SentEmailsResponse>('/admin/sent-emails', { params }).then(r => r.data),

  smtpLogAnalysis: (params: { date_from?: string; date_to?: string; status?: string; kind?: string; search?: string; limit?: number; offset?: number } = {}) =>
    api.get<SmtpLogAnalysis>('/admin/smtp-log-analysis', { params }).then(r => r.data),

  smtpLogStatus: () =>
    api.get<{ enabled: boolean }>('/admin/smtp-log-analysis/status').then(r => r.data),

  invoiceSmtpLog: (id: number) =>
    api.get<InvoiceSmtpLog>(`/admin/invoices/${id}/smtp-log`).then(r => r.data),

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

  // Ukázková (sample) data — stav + odebrání (issue #162)
  sampleDataStatus: () =>
    api.get<SampleDataStatus>('/maintenance/sample-data').then(r => r.data),
  deleteSampleData: () =>
    api.delete<{ deleted: Record<string, number> }>('/maintenance/sample-data').then(r => r.data),
}

export interface SampleDataStatus {
  has: boolean
  total: number
  counts: Partial<Record<'client' | 'vendor' | 'project' | 'invoice' | 'credit_note' | 'purchase_invoice' | 'recurring_template' | 'car', number>>
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

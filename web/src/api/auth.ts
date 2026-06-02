import { api, setCsrfToken } from './client'

export interface User {
  id: number
  email: string
  name: string
  role: 'admin' | 'accountant' | 'readonly'
  locale: 'cs' | 'en'
  totp_enabled?: boolean
  must_setup_totp?: boolean
}

export interface SupplierBrief {
  id: number
  company_name: string
  ic: string | null
  is_vat_payer: boolean
  /** 'fo' = OSVČ (fyzická osoba), 'po' = s.r.o. (právnická osoba), null = nenastaveno. */
  taxpayer_type: 'fo' | 'po' | null
  default_payment_due_days: number
  default_payment_due_unit: 'days' | 'month'
  /** Výchozí režim cen u nových faktur (false = bez DPH, true = ceny s DPH). */
  default_prices_include_vat: boolean
  /** Posílá dodavatel automatické upomínky? Když ne, per-faktura přepínač se v editoru skryje. */
  auto_send_reminders: boolean
  /** Děkovný e-mail za úhradu (issue #57) — řídí checkbox v mark-paid modalu. */
  payment_thanks_enabled: boolean
  payment_thanks_default_checked: boolean
}

export interface SetupStatus {
  needs_setup: boolean
  version: string
  captcha: {
    provider: 'turnstile' | 'none'
    site_key: string
    script_url: string
  }
}

export interface LoginPayload {
  email: string
  password: string
  totp?: string
  email_otp?: string
  remember_device?: boolean
  resend_otp?: boolean
  cf_turnstile_response?: string
}

export interface TotpSetup {
  secret: string
  uri: string
  qr_data_uri: string
  issuer: string
}

export interface SetupPayload {
  admin: { name: string; email: string; password: string }
  /** Volitelné: vynutit 2FA (TOTP) pro všechny uživatele. Zapíše do cfg.local.php. */
  require_totp?: boolean
  supplier?: {
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
    commercial_register?: string
    taxpayer_type?: 'fo' | 'po'
    default_currency?: string
    default_payment_due_days?: number
    default_hourly_rate?: number
    bank_account?: {
      currency: string
      account_number?: string
      bank_code?: string
      bank_name?: string
      iban?: string
      bic?: string
    }
  }
}

export const authApi = {
  setupStatus: () => api.get<SetupStatus>('/auth/setup-status').then((r) => r.data),

  setup: (payload: SetupPayload) =>
    api.post<{ user: User; next: string; csrf_token: string; require_totp?: boolean; cfg_local_written?: boolean }>(
      '/auth/setup',
      payload,
    ).then((r) => {
      // Po setup je session vytvořená (auto-login). Uložit CSRF token, aby další POST volání projely.
      if (r.data.csrf_token) setCsrfToken(r.data.csrf_token)
      return r.data
    }),

  /** ARES lookup pro setup wizard (funguje jen když ještě nemáme admin usera). */
  setupAresLookup: (ic: string) =>
    api.post<import('./clients').AresLookupResult>('/auth/setup-ares-lookup', { ic }).then((r) => r.data),

  /** Účty z registru plátců DPH (CRPDPH) pro setup wizard (jen dokud nemáme admin usera). */
  setupCrpdphLookup: (dic: string) =>
    api.post<import('./clients').BankLookupResult>('/auth/setup-crpdph-lookup', { dic }).then((r) => r.data),

  /** Sample data generator po setup wizardu (jen pokud DB nemá data). */
  setupSample: () =>
    api.post<{ clients: number; projects: number; invoices: number; credit_notes: number }>(
      '/auth/setup-sample',
    ).then((r) => r.data),

  login: (payload: LoginPayload) =>
    api.post<{ user: User; csrf_token: string }>('/auth/login', payload).then((r) => {
      setCsrfToken(r.data.csrf_token)
      return r.data
    }),

  logout: () =>
    api.post('/auth/logout').then(() => {
      setCsrfToken(null)
    }),

  me: () =>
    api.get<{
      user: User
      csrf_token: string
      current_supplier_id: number
      suppliers: SupplierBrief[]
      require_totp?: boolean
    }>('/auth/me').then((r) => {
      setCsrfToken(r.data.csrf_token)
      return r.data
    }),

  changePassword: (current: string, next: string) =>
    api.post('/auth/change-password', {
      current_password: current,
      new_password: next,
      new_password_confirm: next,
    }),

  forgot: (email: string, turnstileToken?: string) =>
    api.post('/auth/forgot', {
      email,
      ...(turnstileToken ? { cf_turnstile_response: turnstileToken } : {}),
    }),

  reset: (token: string, password: string) =>
    api.post('/auth/reset', { token, password, password_confirm: password }),

  // TOTP / 2FA
  totpStatus: () => api.get<{ enabled: boolean }>('/auth/totp/status').then(r => r.data),
  totpSetup:  () => api.post<TotpSetup>('/auth/totp/setup').then(r => r.data),
  totpEnable: (code: string) =>
    api.post<{ enabled: boolean }>('/auth/totp/enable', { code }).then(r => r.data),
}

import axios from 'axios'

export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
})

// CSRF token interceptor — token žije v Pinia auth store
let csrfToken: string | null = null
export function setCsrfToken(token: string | null) {
  csrfToken = token
}

api.interceptors.request.use((config) => {
  if (csrfToken && config.method && config.method.toUpperCase() !== 'GET') {
    config.headers.set('X-CSRF-Token', csrfToken)
  }
  // Pošli aktuální UI locale, aby backend hlášky chodily ve správném jazyce.
  // Auth middleware ji přepíše user.locale (pokud přihlášen).
  const locale = localStorage.getItem('locale') || 'cs'
  config.headers.set('Accept-Language', locale)

  // Multi-supplier — aktuální supplier z localStorage (Pinia persist).
  // Server fallbackuje na MIN(supplier.id) když chybí/neplatný.
  const sid = localStorage.getItem('myinvoice.current_supplier_id')
  if (sid && /^\d+$/.test(sid)) {
    config.headers.set('X-Supplier-Id', sid)
  }
  return config
})

// 401 → redirect na /login (kromě situace kdy už jsme na /login nebo /setup)
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const code = error.response?.data?.error?.code

    if (status === 401) {
      const path = window.location.pathname
      if (!path.startsWith('/login') && !path.startsWith('/setup')) {
        window.location.href = '/login'
      }
    }
    if (status === 423 && code === 'setup_required') {
      window.location.href = '/setup'
    }

    // 503 config_missing / bootstrap_failed = backend není nakonfigurovaný
    // (chybí cfg.php nebo nelze do DB). Zobrazíme fullscreen overlay s návodem,
    // ať uživatel nedostane jen prázdný login form bez vysvětlení.
    if (status === 503 && (code === 'config_missing' || code === 'bootstrap_failed')) {
      showBootstrapErrorOverlay(
        code === 'config_missing'
          ? 'Backend není nakonfigurovaný (chybí cfg.php).'
          : 'Backend selhal při startu (typicky nedostupná databáze).',
        error.response?.data?.error?.message || '',
        error.response?.data?.error?.hint || '',
      )
    }

    return Promise.reject(error)
  },
)

function showBootstrapErrorOverlay(title: string, detail: string, hint: string): void {
  if (document.getElementById('bootstrap-error-overlay')) return  // už zobrazený
  const div = document.createElement('div')
  div.id = 'bootstrap-error-overlay'
  div.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(21,19,29,0.92);' +
    'display:flex;align-items:center;justify-content:center;font:14px/1.5 system-ui,sans-serif;'
  div.innerHTML = `
    <div style="background:#fff;max-width:560px;width:90%;padding:32px;border-radius:12px;
                box-shadow:0 8px 32px rgba(0,0,0,0.3);">
      <h2 style="margin:0 0 12px;color:#3B2D83;font-size:24px;">⚠ MyInvoice.cz</h2>
      <p style="margin:0 0 16px;color:#15131D;font-weight:600;">${escapeHtml(title)}</p>
      ${detail ? `<p style="margin:0 0 12px;color:#5A5470;font-family:monospace;
        background:#F4F2F8;padding:8px 12px;border-radius:6px;font-size:13px;">${escapeHtml(detail)}</p>` : ''}
      ${hint ? `<p style="margin:0 0 16px;color:#5A5470;">💡 ${escapeHtml(hint)}</p>` : ''}
      <p style="margin:0;color:#5A5470;font-size:13px;">
        Kontaktuj administrátora a pošli mu tuhle hlášku.
        Detail v <code>log/bootstrap-error.log</code>.
      </p>
    </div>`
  document.body.appendChild(div)
}

function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!))
}

export interface HealthResponse {
  status: 'ok'
  version: string
  env: string
  db: boolean
  redis: boolean
  warnings: Array<{ code: string; message: string }>
  time: string
}

export const systemApi = {
  health: () => api.get<HealthResponse>('/health').then((r) => r.data),
}

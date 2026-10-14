import { api } from './client'

export interface UpdateStatus {
  current: string
  latest: string | null
  has_update: boolean
  release_notes_md: string | null
  release_url: string | null
  published_at: string | null
  last_check_at: string | null
  last_check_error: string | null
  cache_stale: boolean
  environment: 'docker' | 'native'
  /** false = nasazení řídí platforma (Coolify, Portainer…), UI upgrade nespouští. */
  in_app_upgrade: boolean
  upgrade_in_progress: boolean
  /** Krok běžícího nativního upgradu; Docker flow ho neplní. */
  upgrade_progress: {
    mode: string
    step: string
    step_index: number
    step_count: number
    step_message: string
  } | null
  last_upgrade_result: {
    status: 'applied' | 'failed' | string
    target_version?: string
    applied_at?: string
    message?: string
    log_path?: string
    backup_path?: string
  } | null
}

export interface UpdateTriggerResponse {
  status: 'queued' | 'manual_required' | 'error' | string
  environment?: 'docker' | 'native'
  target_version?: string
  message?: string
  instructions?: string[]
  /** Proč automatická cesta nešla (u `manual_required`). */
  blockers?: string[]
  warnings?: string[]
}

/** Umí tahle instalace nativní auto-update z release bundlu? */
export interface UpdatePreflight {
  ok: boolean
  supported: boolean
  blockers: string[]
  warnings: string[]
}

export interface PublicVersion {
  current: string
  latest: string | null
  has_update: boolean
  release_url: string | null
}

export const updateApi = {
  /** Public — pro footer / about page (bez auth). */
  publicVersion: () => api.get<PublicVersion>('/version').then((r) => r.data),

  /** Admin — kompletní status včetně release notes. */
  status: (signal?: AbortSignal) =>
    api.get<UpdateStatus>('/admin/update/status', { signal }).then((r) => r.data),

  /** Admin — vynucený fresh fetch z GitHubu. */
  refresh: () => api.post<UpdateStatus>('/admin/update/refresh').then((r) => r.data),

  /** Admin — může nativní instalace updatovat sama sebe? (zapisuje probe soubory) */
  preflight: (target_version?: string, signal?: AbortSignal) =>
    api
      .get<UpdatePreflight>('/admin/update/preflight', {
        params: target_version ? { target_version } : undefined,
        signal,
      })
      .then((r) => r.data),

  /** Admin — spustit upgrade: Docker fronta, nebo nativní worker na pozadí. */
  trigger: (target_version?: string) =>
    api
      .post<UpdateTriggerResponse>('/admin/update/trigger', { target_version: target_version ?? null })
      .then((r) => r.data),

  /** Admin — zrušit zaseknutý „upgrade probíhá" flag (watcher neběží / upgrade proběhl ručně). */
  cancel: () => api.post<{ status: string; cleared: boolean }>('/admin/update/cancel').then((r) => r.data),
}

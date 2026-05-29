<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { updateApi, type UpdateStatus } from '@/api/update'
import { systemApi, type HealthResponse } from '@/api/client'

const { t } = useI18n()
const auth = useAuthStore()

const status = ref<UpdateStatus | null>(null)
const health = ref<HealthResponse | null>(null)
const checking = ref(false)
const triggering = ref(false)
const cancelling = ref(false)
const triggerResult = ref<{ status: string; message?: string; instructions?: string[] } | null>(null)
const errorMsg = ref<string | null>(null)

let pollHandle: number | null = null

const isAdmin = computed(() => auth.user?.role === 'admin')

async function load() {
  errorMsg.value = null
  try {
    const [nextStatus, nextHealth] = await Promise.all([
      updateApi.status(),
      systemApi.health(),
    ])
    status.value = nextStatus
    health.value = nextHealth
    // Cache je stale (>24h, prázdná, nebo `latest < current`) → background refresh,
    // aby uživatel nemusel mačkat "Zkontrolovat nyní" sám. Tichý fail je OK.
    if (nextStatus.cache_stale && !checking.value) {
      void backgroundRefresh()
    }
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? 'Failed to load status'
  }
}

async function backgroundRefresh() {
  if (checking.value) return
  checking.value = true
  try {
    status.value = await updateApi.refresh()
  } catch {
    // tiché selhání — UI dál ukazuje cache + tlačítko "Zkontrolovat nyní"
  } finally {
    checking.value = false
  }
}

async function refresh() {
  if (checking.value) return
  checking.value = true
  errorMsg.value = null
  try {
    status.value = await updateApi.refresh()
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? 'Refresh failed'
  } finally {
    checking.value = false
  }
}

async function triggerUpgrade() {
  if (!status.value?.latest || triggering.value) return
  if (!confirm(t('updates.trigger_update', { version: status.value.latest }) + '?')) return
  triggering.value = true
  triggerResult.value = null
  try {
    const r = await updateApi.trigger(status.value.latest)
    triggerResult.value = r
    // Pro Docker: po queue startuj polling, ať vidíme result.json až watcher dojede
    if (r.status === 'queued') {
      startPolling()
    }
    await load()
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? 'Trigger failed'
  } finally {
    triggering.value = false
  }
}

async function cancelStuckUpgrade() {
  if (cancelling.value) return
  if (!confirm(t('updates.cancel_stuck_confirm'))) return
  cancelling.value = true
  errorMsg.value = null
  try {
    await updateApi.cancel()
    stopPolling()
    triggerResult.value = null
    await load()
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? 'Cancel failed'
  } finally {
    cancelling.value = false
  }
}

function startPolling() {
  if (pollHandle !== null) return
  pollHandle = window.setInterval(async () => {
    await load()
    if (status.value && !status.value.upgrade_in_progress) {
      stopPolling()
    }
  }, 5000)
}

function stopPolling() {
  if (pollHandle !== null) {
    window.clearInterval(pollHandle)
    pollHandle = null
  }
}

onMounted(async () => {
  await load()
  // Pokud je při otevření stránky upgrade „v běhu", spusť polling — backend se navíc
  // sám uzdraví (prošlý flag / cílová verze už nasazená), takže se to nezasekne.
  if (status.value?.upgrade_in_progress) {
    startPolling()
  }
})
onUnmounted(stopPolling)

// Mini markdown renderer pro release notes (GitHub release body).
// Žádný HTML injection — escape všechno, pak inline tagy + bloky.
function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!))
}

function renderMarkdown(md: string): string {
  if (!md) return ''
  const lines = md.replace(/\r\n/g, '\n').split('\n')
  const out: string[] = []
  let listType: 'ul' | 'ol' | null = null
  let para: string[] = []
  let inFence = false
  let fenceBuf: string[] = []

  const flushPara = () => {
    if (para.length) {
      out.push('<p>' + inline(para.join(' ')) + '</p>')
      para = []
    }
  }
  const closeList = () => {
    if (listType) {
      out.push(`</${listType}>`)
      listType = null
    }
  }
  const ensureList = (t: 'ul' | 'ol') => {
    if (listType !== t) {
      closeList()
      out.push(`<${t}>`)
      listType = t
    }
  }

  function inline(s: string): string {
    let r = escapeHtml(s)
    r = r.replace(/`([^`]+)`/g, '<code>$1</code>')
    r = r.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    r = r.replace(/(?<!\w)\*([^*\n]+)\*(?!\w)/g, '<em>$1</em>')
    // URL je už HTML-escapovaná (inline() escapuje celý řetězec výše). Povol jen bezpečná
    // schémata — `javascript:`/`data:` apod. zahoď a vykresli jen text (XSS guard).
    r = r.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_m, text: string, url: string) =>
      /^(https?:\/\/|mailto:|\/|#)/i.test(url)
        ? `<a href="${url}" target="_blank" rel="noopener">${text}</a>`
        : text,
    )
    return r
  }

  for (const line of lines) {
    if (/^```/.test(line.trim())) {
      if (!inFence) {
        flushPara()
        closeList()
        inFence = true
        fenceBuf = []
      } else {
        out.push('<pre><code>' + escapeHtml(fenceBuf.join('\n')) + '</code></pre>')
        inFence = false
      }
      continue
    }
    if (inFence) {
      fenceBuf.push(line)
      continue
    }

    const trim = line.trim()
    if (trim === '') {
      flushPara()
      closeList()
      continue
    }
    const heading = trim.match(/^(#{1,6})\s+(.+)$/)
    if (heading) {
      flushPara()
      closeList()
      const lvl = heading[1].length
      out.push(`<h${lvl}>${inline(heading[2])}</h${lvl}>`)
      continue
    }
    if (/^[-*]\s+/.test(trim)) {
      flushPara()
      ensureList('ul')
      out.push('<li>' + inline(trim.replace(/^[-*]\s+/, '')) + '</li>')
      continue
    }
    if (/^\d+\.\s+/.test(trim)) {
      flushPara()
      ensureList('ol')
      out.push('<li>' + inline(trim.replace(/^\d+\.\s+/, '')) + '</li>')
      continue
    }
    closeList()
    para.push(trim)
  }
  flushPara()
  closeList()
  if (inFence) {
    out.push('<pre><code>' + escapeHtml(fenceBuf.join('\n')) + '</code></pre>')
  }
  return out.join('\n')
}

const renderedNotes = computed(() => {
  const md = status.value?.release_notes_md ?? ''
  return renderMarkdown(md)
})

const healthWarnings = computed(() => health.value?.warnings ?? [])

function warningTitle(code: string): string {
  if (code === 'secret_encryption_key') return t('updates.warning_secret_key_title')
  return t('updates.warning_generic_title')
}

function warningText(code: string): string {
  if (code === 'secret_encryption_key') return t('updates.warning_secret_key_text')
  return t('updates.warning_generic_text')
}

function fmtDate(s?: string | null): string {
  if (!s) return '—'
  try {
    return new Date(s).toLocaleString()
  } catch {
    return s
  }
}
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <header class="mb-6">
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('updates.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('updates.subtitle') }}</p>
    </header>

    <div v-if="!isAdmin" class="rounded-md bg-warning-50 border border-warning-200 p-4 text-sm text-warning-800">
      {{ t('updates.no_admin') }}
    </div>

    <div v-else-if="status" class="space-y-6">
      <!-- Stav: aktuální vs. poslední -->
      <section v-if="healthWarnings.length" class="rounded-lg border border-warning-300 bg-warning-50/40 p-5">
        <h2 class="text-lg font-semibold text-warning-900">{{ t('updates.warnings_title') }}</h2>
        <ul class="mt-2 space-y-2">
          <li
            v-for="warning in healthWarnings"
            :key="`${warning.code}:${warning.message}`"
            class="rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-900"
          >
            <div class="font-medium">{{ warningTitle(warning.code) }}</div>
            <div class="text-warning-800">{{ warningText(warning.code) }}</div>
          </li>
        </ul>
      </section>

      <section
        class="rounded-lg border p-5"
        :class="status.has_update
          ? 'border-primary-300 bg-primary-50/40'
          : 'border-neutral-200 bg-surface'"
      >
        <div class="flex flex-wrap items-baseline justify-between gap-4">
          <div>
            <div class="text-xs uppercase tracking-wider text-neutral-500">{{ t('updates.current_version') }}</div>
            <div class="text-3xl font-semibold text-neutral-900 leading-tight mt-0.5">v{{ status.current }}</div>
          </div>
          <div class="text-right">
            <div class="text-xs uppercase tracking-wider text-neutral-500">{{ t('updates.latest_version') }}</div>
            <div class="text-3xl font-semibold leading-tight mt-0.5"
              :class="status.has_update ? 'text-primary-700' : 'text-neutral-900'">
              {{ status.latest ? 'v' + status.latest : t('updates.no_check_yet') }}
            </div>
          </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
          <span v-if="status.has_update"
            class="inline-flex items-center gap-1.5 rounded-full bg-primary-100 text-primary-700 px-3 py-1 text-xs font-medium">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('updates.update_available') }}
          </span>
          <span v-else-if="status.latest"
            class="inline-flex items-center gap-1.5 rounded-full bg-success-50 text-success-700 px-3 py-1 text-xs font-medium">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            {{ t('updates.up_to_date') }}
          </span>
          <span class="text-neutral-500">
            {{ t('updates.environment') }}:
            <strong class="text-neutral-700 font-medium">
              {{ status.environment === 'docker' ? t('updates.env_docker') : t('updates.env_native') }}
            </strong>
          </span>
          <span class="text-neutral-500">
            {{ t('updates.last_check') }}: <span class="text-neutral-700">{{ fmtDate(status.last_check_at) }}</span>
          </span>
        </div>

        <div v-if="status.last_check_error"
          class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 p-3 text-xs text-danger-600">
          {{ t('updates.last_check_error') }}: <span class="font-mono">{{ status.last_check_error }}</span>
        </div>

        <div class="mt-5 flex flex-wrap gap-2">
          <button
            type="button"
            @click="refresh"
            :disabled="checking"
            class="inline-flex items-center gap-1.5 rounded-md border border-neutral-300 bg-surface px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-60 disabled:cursor-not-allowed"
          >
            <svg class="w-4 h-4" :class="{ 'animate-spin': checking }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/></svg>
            {{ checking ? t('updates.checking') : t('updates.check_now') }}
          </button>
          <button
            v-if="status.has_update && !status.upgrade_in_progress"
            type="button"
            @click="triggerUpgrade"
            :disabled="triggering"
            class="inline-flex items-center gap-1.5 rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-60 disabled:cursor-not-allowed"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
            {{ triggering ? t('updates.triggering') : t('updates.trigger_update', { version: status.latest }) }}
          </button>
          <a v-if="status.release_url" :href="status.release_url" target="_blank" rel="noopener"
            class="inline-flex items-center gap-1.5 rounded-md border border-neutral-300 bg-surface px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            {{ t('updates.release_on_github') }}
          </a>
        </div>
      </section>

      <!-- Trigger result -->
      <section v-if="triggerResult" class="rounded-lg border p-5"
        :class="triggerResult.status === 'queued'
          ? 'border-primary-300 bg-primary-50/40'
          : triggerResult.status === 'manual_required'
            ? 'border-neutral-300 bg-neutral-50'
            : 'border-danger-500/40 bg-danger-50/40'">
        <h2 class="text-lg font-semibold text-neutral-900">
          <template v-if="triggerResult.status === 'queued'">{{ t('updates.queued_title') }}</template>
          <template v-else-if="triggerResult.status === 'manual_required'">{{ t('updates.manual_required_title') }}</template>
          <template v-else>{{ triggerResult.status }}</template>
        </h2>
        <p v-if="triggerResult.message" class="text-sm text-neutral-700 mt-1.5">{{ triggerResult.message }}</p>
        <div v-if="triggerResult.status === 'queued'"
          class="text-xs text-neutral-600 mt-2">{{ t('updates.queued_desc') }}</div>
        <pre v-if="triggerResult.instructions?.length"
          class="mt-3 rounded-md bg-neutral-900 text-neutral-100 p-3 text-xs leading-relaxed overflow-x-auto"><code>{{ triggerResult.instructions.join('\n') }}</code></pre>
      </section>

      <!-- In-progress -->
      <section v-if="status.upgrade_in_progress"
        class="rounded-lg border border-primary-300 bg-primary-50/40 p-5">
        <h2 class="text-lg font-semibold text-neutral-900 flex items-center gap-2">
          <svg class="w-5 h-5 animate-spin text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/></svg>
          {{ t('updates.in_progress_title') }}
        </h2>
        <p class="text-sm text-neutral-600 mt-1.5">{{ t('updates.in_progress_desc') }}</p>
        <div class="mt-3 pt-3 border-t border-primary-200/60 flex items-center gap-3 flex-wrap">
          <button type="button" @click="cancelStuckUpgrade" :disabled="cancelling"
            class="cursor-pointer h-8 px-3 text-sm border border-neutral-300 bg-surface hover:bg-neutral-50 rounded-md inline-flex items-center gap-1.5 disabled:opacity-50">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            {{ cancelling ? '…' : t('updates.cancel_stuck') }}
          </button>
          <span class="text-xs text-neutral-500">{{ t('updates.cancel_stuck_hint') }}</span>
        </div>
      </section>

      <!-- Last result -->
      <section v-if="status.last_upgrade_result && !status.upgrade_in_progress"
        class="rounded-lg border p-5"
        :class="['applied','success'].includes(status.last_upgrade_result.status)
          ? 'border-success-300 bg-success-50/40'
          : status.last_upgrade_result.status === 'failed'
            ? 'border-danger-500/40 bg-danger-50/40'
            : 'border-warning-300 bg-warning-50/40'">
        <h2 class="text-lg font-semibold text-neutral-900">
          {{ ['applied','success'].includes(status.last_upgrade_result.status)
            ? t('updates.result_applied')
            : status.last_upgrade_result.status === 'failed'
              ? t('updates.result_failed')
              : t('updates.result_unknown') }}
        </h2>
        <div class="text-sm text-neutral-700 mt-1.5 space-y-0.5">
          <div v-if="status.last_upgrade_result.target_version">
            <span class="text-neutral-500">{{ t('updates.latest_version') }}:</span>
            v{{ status.last_upgrade_result.target_version }}
          </div>
          <div v-if="status.last_upgrade_result.applied_at">
            <span class="text-neutral-500">{{ t('updates.applied_at') }}:</span>
            {{ fmtDate(status.last_upgrade_result.applied_at) }}
          </div>
          <div v-if="status.last_upgrade_result.message" class="text-neutral-600 mt-1">
            {{ status.last_upgrade_result.message }}
          </div>
        </div>
      </section>

      <!-- Release notes -->
      <section v-if="status.release_notes_md"
        class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900 mb-3">{{ t('updates.release_notes') }} (v{{ status.latest }})</h2>
        <div class="release-notes prose prose-sm max-w-none" v-html="renderedNotes"></div>
      </section>

      <!-- How upgrade works — vždy viditelné, environment-specific instrukce -->
      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900 mb-3">{{ t('updates.how_it_works') }}</h2>

        <template v-if="status.environment === 'docker'">
          <h3 class="text-sm font-semibold text-neutral-800 flex items-center gap-2 mt-1">
            <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7L9 18l-5-5"/></svg>
            {{ t('updates.how_docker_title') }}
          </h3>
          <p class="text-sm text-neutral-600 mt-1.5 leading-relaxed">{{ t('updates.how_docker_desc') }}</p>
          <p class="text-sm text-neutral-600 mt-3 leading-relaxed">{{ t('updates.how_docker_setup') }}</p>
          <pre class="mt-2 rounded-md bg-neutral-900 text-neutral-100 p-3 text-xs leading-relaxed overflow-x-auto"><code># Linux / macOS
cd /opt/myinvoice
bash cmd/docker-update.sh

# Windows (PowerShell)
cd C:\inetpub\myinvoice
powershell -NoProfile -ExecutionPolicy Bypass -File cmd\docker-update.ps1</code></pre>
        </template>

        <template v-else>
          <h3 class="text-sm font-semibold text-neutral-800 flex items-center gap-2 mt-1">
            <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            {{ t('updates.how_native_title') }}
          </h3>
          <p class="text-sm text-neutral-600 mt-1.5 leading-relaxed">{{ t('updates.how_native_desc') }}</p>
          <pre class="mt-2 rounded-md bg-neutral-900 text-neutral-100 p-3 text-xs leading-relaxed overflow-x-auto"><code># Klasický postup (vyžaduje Composer + Node + pnpm na hostu)
git fetch --tags
git checkout v{{ status.latest ?? 'X.Y.Z' }}
cd api && composer install --no-dev && cd ..
cd web && pnpm install && pnpm build && cd ..
php tools/generateManualHtml.php
php tools/exportManualToPdf.php
php api/bin/migrate.php

# Alternativa: production bundle (bez Composer / Node)
# https://github.com/radekhulan/myinvoice/releases/latest
curl -LO https://github.com/radekhulan/myinvoice/releases/download/v{{ status.latest ?? 'X.Y.Z' }}/myinvoice-{{ status.latest ?? 'X.Y.Z' }}.tar.gz
tar -xzf myinvoice-{{ status.latest ?? 'X.Y.Z' }}.tar.gz --strip-components=1 \
  --exclude='cfg.php' --exclude='cfg.local.php' \
  --exclude='storage' --exclude='private' --exclude='log'
php api/bin/migrate.php</code></pre>
        </template>

        <p class="text-xs text-neutral-500 mt-3">
          <a href="/manual?ch=21_Aktualizace" target="_blank" rel="noopener" class="text-primary-600 hover:text-primary-800 hover:underline">
            {{ t('updates.manual_link') }} →
          </a>
        </p>
      </section>
    </div>

    <div v-if="errorMsg" class="rounded-md bg-danger-50 border border-danger-500/40 p-4 text-sm text-danger-600">
      {{ errorMsg }}
    </div>
  </div>
</template>

<style scoped>
/* Minimální styling pro mini-markdown rendered release notes (žádný @tailwindcss/typography). */
.release-notes :deep(h1),
.release-notes :deep(h2),
.release-notes :deep(h3) {
  font-weight: 600;
  color: var(--color-neutral-900);
  margin: 1em 0 0.4em;
  line-height: 1.3;
}
.release-notes :deep(h1) { font-size: 1.25rem; }
.release-notes :deep(h2) { font-size: 1.1rem; }
.release-notes :deep(h3) { font-size: 1rem; }
.release-notes :deep(p) { margin: 0.4em 0; line-height: 1.55; color: var(--color-neutral-700); }
.release-notes :deep(ul),
.release-notes :deep(ol) {
  margin: 0.4em 0;
  padding-left: 1.5em;
  color: var(--color-neutral-700);
}
.release-notes :deep(li) { margin: 0.15em 0; }
.release-notes :deep(code) {
  background: var(--color-neutral-100);
  color: var(--color-danger-600);
  padding: 0 4px;
  border-radius: 3px;
  font-size: 0.85em;
  font-family: "JetBrains Mono", Consolas, monospace;
}
.release-notes :deep(pre) {
  background: #1e1e2e;
  color: #cdd6f4;
  padding: 0.75em 1em;
  border-radius: 6px;
  overflow-x: auto;
  margin: 0.6em 0;
  font-size: 0.85em;
  line-height: 1.5;
}
.release-notes :deep(pre code) { background: transparent; color: inherit; padding: 0; }
.release-notes :deep(strong) { font-weight: 600; color: var(--color-neutral-900); }
.release-notes :deep(em) { font-style: italic; }
.release-notes :deep(a) { color: var(--color-primary-600); text-decoration: underline; }
.release-notes :deep(a:hover) { color: var(--color-primary-700); }
</style>

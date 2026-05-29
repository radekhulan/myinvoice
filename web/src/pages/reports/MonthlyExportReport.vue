<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi, type MonthlyExportPreview, type MonthlyExportPart, type MonthlyExportJob } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { useYearOptions } from '@/composables/useYearOptions'

const { t, locale } = useI18n()
const toast = useToast()

const now = new Date()
const year = ref(now.getFullYear())
const month = ref(now.getMonth() + 1)

// Pořadí částí v UI = pořadí, ve kterém je uživatel zmínil.
const ALL_PARTS: MonthlyExportPart[] = [
  'sales_pdf', 'sales_isdoc',
  'purchase_pdf', 'purchase_isdoc',
  'bank_pdf', 'bank_gpc',
  'dph_book',
]
const selected = ref<Set<MonthlyExportPart>>(new Set(ALL_PARTS))

const preview = ref<MonthlyExportPreview | null>(null)
const loading = ref(false)
const starting = ref(false)
const error = ref('')

const jobs = ref<MonthlyExportJob[]>([])
let pollTimer: ReturnType<typeof setInterval> | null = null

function countFor(part: MonthlyExportPart): number {
  return preview.value?.counts[part] ?? 0
}

async function loadPreview() {
  loading.value = true
  error.value = ''
  try {
    preview.value = await reportsApi.monthlyExportPreview(year.value, month.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

const anyActive = computed(() => jobs.value.some(j => ['queued', 'running'].includes(j.status)))

async function loadJobs() {
  try {
    const prev = jobs.value
    jobs.value = await reportsApi.monthlyExportJobs()
    // Toast při přechodu running → completed/failed (jen za běhu polling).
    for (const j of jobs.value) {
      const old = prev.find(p => p.id === j.id)
      if (old && ['queued', 'running'].includes(old.status) && old.status !== j.status) {
        if (j.status === 'completed') toast.success(t('reports.monthly_export.job.done'))
        else if (j.status === 'failed') toast.error(j.last_error || t('reports.monthly_export.download_failed'))
      }
    }
  } catch { /* ponech předchozí stav */ }
  syncPolling()
}

function syncPolling() {
  if (anyActive.value && !pollTimer) {
    pollTimer = setInterval(loadJobs, 2000)
  } else if (!anyActive.value && pollTimer) {
    clearInterval(pollTimer); pollTimer = null
  }
}

function toggle(part: MonthlyExportPart) {
  const next = new Set(selected.value)
  next.has(part) ? next.delete(part) : next.add(part)
  selected.value = next
}
function selectAll() { selected.value = new Set(ALL_PARTS) }
function selectNone() { selected.value = new Set() }

const selectedList = computed(() => ALL_PARTS.filter(p => selected.value.has(p)))
const hasDownloadableSelection = computed(() => selectedList.value.some(p => countFor(p) > 0))

function progressPct(j: MonthlyExportJob): number {
  if (!j.total_items || j.total_items <= 0) return 0
  return Math.min(100, Math.round((j.processed / j.total_items) * 100))
}
function isActive(j: MonthlyExportJob): boolean {
  return ['queued', 'running'].includes(j.status)
}

async function startExport() {
  if (!hasDownloadableSelection.value || anyActive.value) return
  starting.value = true
  error.value = ''
  try {
    const r = await reportsApi.monthlyExportStart(year.value, month.value, selectedList.value)
    toast.success(t('reports.monthly_export.job.started', { jobId: r.job_id }))
    await loadJobs()
  } catch (e) {
    error.value = apiErrorMessage(e)
    toast.error(error.value)
  } finally {
    starting.value = false
  }
}

async function cancelJob(j: MonthlyExportJob) {
  try {
    await reportsApi.monthlyExportCancel(j.id)
    toast.success(t('reports.monthly_export.job.cancel_requested'))
    await loadJobs()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function deleteJob(j: MonthlyExportJob) {
  try {
    await reportsApi.monthlyExportDeleteJob(j.id)
  } catch (e) {
    toast.error(apiErrorMessage(e)); return
  }
  await loadJobs()
}

function downloadJob(j: MonthlyExportJob) {
  if (j.status !== 'completed') return
  window.open(reportsApi.monthlyExportDownloadUrl(j.id), '_blank')
}

function periodLabel(j: MonthlyExportJob): string {
  const p = j.params as { year?: number; month?: number } | null
  if (!p?.year || !p?.month) return ''
  return new Date(p.year, p.month - 1, 1).toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long', year: 'numeric' })
}
function fmtSize(bytes: number | null): string {
  if (!bytes) return ''
  if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MB`
  if (bytes >= 1024) return `${Math.round(bytes / 1024)} kB`
  return `${bytes} B`
}
function statusClass(j: MonthlyExportJob): string {
  switch (j.status) {
    case 'completed': return 'bg-success-100 text-success-700'
    case 'failed':    return 'bg-danger-100 text-danger-700'
    case 'cancelled': return 'bg-neutral-200 text-neutral-600'
    case 'running':   return 'bg-primary-100 text-primary-700'
    default:          return 'bg-warning-100 text-warning-700'
  }
}

const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long' })
  )
)
const yearOptions = useYearOptions('combined', year)

const groups = computed(() => [
  { key: 'invoices', parts: ['sales_pdf', 'sales_isdoc', 'purchase_pdf', 'purchase_isdoc'] as MonthlyExportPart[] },
  { key: 'bank',     parts: ['bank_pdf', 'bank_gpc'] as MonthlyExportPart[] },
  { key: 'dph',      parts: ['dph_book'] as MonthlyExportPart[] },
])

watch([year, month], loadPreview)
onMounted(() => { loadPreview(); loadJobs() })
onUnmounted(() => { if (pollTimer) clearInterval(pollTimer) })
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <!-- Topbar -->
    <div class="flex items-center justify-between mb-1 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.monthly_export.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.monthly_export.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <select v-model.number="month" :disabled="anyActive" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:bg-neutral-100">
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <select v-model.number="year" :disabled="anyActive" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:bg-neutral-100">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
      </div>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-600 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-5">
      <!-- Daňově korektní zařazení dokladů do období -->
      <div class="flex items-start gap-2 rounded-md bg-primary-50 border border-primary-200 px-3 py-2.5 text-sm text-primary-800">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
        <span>{{ t('reports.monthly_export.period_rule_note') }}</span>
      </div>

      <!-- Výběr částí -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="block text-sm font-medium text-neutral-700">{{ t('reports.monthly_export.parts_label') }}</label>
          <div class="flex items-center gap-3 text-xs">
            <button type="button" @click="selectAll" :disabled="anyActive" class="cursor-pointer text-primary-600 hover:text-primary-700 disabled:text-neutral-300">{{ t('reports.monthly_export.select_all') }}</button>
            <span class="text-neutral-300">|</span>
            <button type="button" @click="selectNone" :disabled="anyActive" class="cursor-pointer text-neutral-500 hover:text-neutral-700 disabled:text-neutral-300">{{ t('reports.monthly_export.select_none') }}</button>
          </div>
        </div>

        <div class="space-y-2">
          <template v-for="group in groups" :key="group.key">
            <label
              v-for="part in group.parts"
              :key="part"
              class="flex items-center gap-3 p-3 border rounded-md transition"
              :class="[
                countFor(part) === 0 || anyActive ? 'opacity-60' : 'cursor-pointer hover:bg-neutral-50',
                selected.has(part) && countFor(part) > 0 ? 'border-primary-400 bg-primary-50/60' : 'border-neutral-200',
              ]"
            >
              <input
                type="checkbox"
                class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                :checked="selected.has(part)"
                :disabled="countFor(part) === 0 || anyActive"
                @change="toggle(part)"
              />
              <span class="text-sm font-medium text-neutral-800 flex-1">{{ t('reports.monthly_export.parts.' + part) }}</span>
              <span v-if="!loading"
                class="text-xs font-mono px-2 py-0.5 rounded"
                :class="countFor(part) > 0 ? 'bg-neutral-100 text-neutral-600' : 'bg-neutral-50 text-neutral-400'">
                {{ countFor(part) > 0 ? t('reports.monthly_export.available_count', { count: countFor(part) }) : '—' }}
              </span>
              <span v-else class="text-xs text-neutral-300">…</span>
            </label>
          </template>
        </div>
      </div>

      <!-- Start -->
      <div class="flex items-center justify-end gap-3 pt-1">
        <span v-if="anyActive" class="text-xs text-neutral-500">{{ t('reports.monthly_export.job.in_progress') }}</span>
        <span v-else-if="!hasDownloadableSelection && !loading" class="text-xs text-neutral-500">
          {{ selectedList.length === 0 ? t('reports.monthly_export.no_selection') : t('reports.monthly_export.empty_hint') }}
        </span>
        <button
          type="button"
          @click="startExport"
          :disabled="starting || loading || anyActive || !hasDownloadableSelection"
          class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 disabled:cursor-not-allowed text-white text-sm font-medium rounded-md inline-flex items-center gap-2"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('reports.monthly_export.job.start') }}
        </button>
      </div>
    </div>

    <!-- Historie exportů (zůstávají ke stažení) -->
    <div v-if="jobs.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-5 py-3 border-b border-neutral-200 text-sm font-medium text-neutral-700">
        {{ t('reports.monthly_export.job.history') }}
      </div>
      <ul class="divide-y divide-neutral-100">
        <li v-for="j in jobs" :key="j.id" class="px-5 py-3">
          <div class="flex items-center gap-3 flex-wrap">
            <span class="text-xs font-semibold px-2 py-0.5 rounded" :class="statusClass(j)">
              {{ t('reports.monthly_export.job.status.' + j.status) }}
            </span>
            <span class="text-sm font-medium text-neutral-800 capitalize">{{ periodLabel(j) }}</span>
            <span v-if="j.status === 'completed'" class="text-xs text-neutral-500">
              {{ t('reports.monthly_export.job.ready', { count: j.created_count }) }}<template v-if="j.result_size"> · {{ fmtSize(j.result_size) }}</template>
            </span>
            <span v-else-if="isActive(j) && j.current_step" class="text-xs text-neutral-500">{{ j.current_step }}</span>

            <div class="ml-auto flex items-center gap-2">
              <button v-if="isActive(j)" type="button" @click="cancelJob(j)" :disabled="j.cancel_requested"
                class="cursor-pointer px-2.5 h-8 text-xs border border-neutral-300 text-neutral-700 rounded-md hover:bg-neutral-100 disabled:opacity-50">
                {{ t('reports.monthly_export.job.cancel') }}
              </button>
              <button v-if="j.status === 'completed'" type="button" @click="downloadJob(j)"
                class="cursor-pointer px-3 h-8 text-xs bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                {{ t('reports.monthly_export.download') }}
              </button>
              <button v-if="!isActive(j)" type="button" @click="deleteJob(j)" :title="t('common.delete')"
                class="cursor-pointer px-2 h-8 text-neutral-400 hover:text-danger-600 rounded-md">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </button>
            </div>
          </div>

          <!-- Progress (aktivní) -->
          <div v-if="isActive(j)" class="mt-2">
            <div class="h-1.5 w-full rounded-full bg-neutral-200 overflow-hidden">
              <div class="h-full bg-primary-500 transition-all" :style="{ width: progressPct(j) + '%' }"></div>
            </div>
            <div class="text-[11px] text-neutral-400 mt-1 font-mono">{{ j.processed }}<span v-if="j.total_items"> / {{ j.total_items }}</span></div>
          </div>
          <!-- Chyba -->
          <div v-else-if="j.status === 'failed' && j.last_error" class="mt-1.5 text-xs text-danger-600">{{ j.last_error }}</div>
        </li>
      </ul>
    </div>
  </div>
</template>

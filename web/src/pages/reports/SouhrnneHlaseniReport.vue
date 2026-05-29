<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import { useYearOptions } from '@/composables/useYearOptions'

const { t, locale } = useI18n()

const now = new Date()
const year = ref(now.getFullYear())
const month = ref(now.getMonth() + 1)

const preview = ref<Awaited<ReturnType<typeof reportsApi.shvPreview>> | null>(null)
const loading = ref(false)
const error = ref('')

async function loadPreview() {
  loading.value = true
  error.value = ''
  try {
    preview.value = await reportsApi.shvPreview(year.value, month.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function downloadXml() {
  window.open(reportsApi.shvDownloadUrl(year.value, month.value), '_blank')
}

const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long' })
  )
)
// Distinct roky z dat (issue #33).
const yearOptions = useYearOptions('combined', year)

const daysToDeadline = computed(() => {
  if (!preview.value?.summary.submission_deadline) return null
  const d = new Date(preview.value.summary.submission_deadline)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.ceil((d.getTime() - today.getTime()) / (1000 * 60 * 60 * 24))
})

function shTypeLabel(t: string): string {
  switch (t) {
    case '0': return 'Dodání zboží do EU'
    case '1': return 'Trojstranný obchod (prostředník)'
    case '2': return 'Poskytnutí služby do EU'
    case '3': return 'Přemístění zboží'
    default:  return t
  }
}

watch([year, month], loadPreview)
onMounted(loadPreview)
</script>

<template>
  <div class="max-w-5xl">
    <!-- ⚠️ Disclaimer -->
    <div class="bg-danger-50 border-2 border-danger-500 rounded-lg p-4 mb-4">
      <div class="flex items-start gap-3">
        <svg class="w-6 h-6 text-danger-600 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-1-8a1 1 0 0 0-1 1v3a1 1 0 0 0 2 0V6a1 1 0 0 0-1-1z" clip-rule="evenodd"/>
        </svg>
        <div class="text-sm text-danger-700">
          <p class="font-semibold mb-1">{{ t('reports.disclaimer_title') }}</p>
          <p>{{ t('reports.disclaimer_body') }}</p>
        </div>
      </div>
    </div>

    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.shv.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.shv.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <select v-model.number="month" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <button type="button" @click="downloadXml" :disabled="loading || !preview"
          class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('reports.shv.download_xml') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">{{ t('common.loading') }}…</div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">{{ error }}</div>

    <div v-else-if="preview" class="space-y-4">
      <!-- Warnings -->
      <div v-if="preview.warnings.length > 0" class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700">
        <strong>{{ t('reports.dph.warnings') }}:</strong>
        <ul class="mt-1 list-disc list-inside">
          <li v-for="w in preview.warnings" :key="w">{{ w }}</li>
        </ul>
      </div>

      <!-- KPI -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.shv.rows_count') }}</div>
          <div class="text-2xl font-bold font-mono text-neutral-900">{{ preview.summary.rows_count }}</div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.shv.rows_hint') }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.shv.total_amount') }}</div>
          <div class="text-2xl font-bold font-mono text-neutral-900">
            {{ formatMoney(preview.summary.total_amount, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.shv.total_hint') }}</div>
        </div>
        <div v-if="preview.summary.submission_deadline" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.dph.deadline') }}</div>
          <div class="text-xl font-bold font-mono"
            :class="(daysToDeadline ?? 999) < 0 ? 'text-danger-500' : (daysToDeadline ?? 999) <= 7 ? 'text-warning-600' : 'text-neutral-900'">
            {{ preview.summary.submission_deadline }}
          </div>
          <div class="text-xs mt-1"
            :class="(daysToDeadline ?? 999) < 0 ? 'text-danger-500' : (daysToDeadline ?? 999) <= 7 ? 'text-warning-600' : 'text-neutral-500'">
            <template v-if="daysToDeadline !== null && daysToDeadline >= 0">{{ t('reports.dph.deadline_in', { n: daysToDeadline }) }}</template>
            <template v-else-if="daysToDeadline !== null">{{ t('reports.dph.deadline_passed', { n: Math.abs(daysToDeadline) }) }}</template>
          </div>
        </div>
      </div>

      <!-- Rows table -->
      <div v-if="preview.summary.rows.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('reports.shv.rows_title') }}</h3>
        </header>
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2 w-16">{{ t('reports.shv.country') }}</th>
              <th class="text-left px-3 py-2">{{ t('reports.shv.vat_id') }} / {{ t('reports.shv.counterparty') }}</th>
              <th class="text-center px-3 py-2 w-16">{{ t('reports.shv.code') }}</th>
              <th class="text-left px-3 py-2">{{ t('reports.shv.type') }}</th>
              <th class="text-right px-3 py-2">{{ t('reports.shv.count') }}</th>
              <th class="text-right px-5 py-2">{{ t('reports.shv.amount') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in preview.summary.rows" :key="r.country_iso2 + r.vat_id + r.sh_type" class="hover:bg-neutral-50">
              <td class="px-5 py-2.5 font-mono text-xs font-medium">{{ r.country_iso2 }}</td>
              <td class="px-3 py-2.5">
                <div class="font-mono text-xs">{{ r.vat_id }}</div>
                <div class="text-xs text-neutral-500 mt-0.5">{{ r.counterparty_name }}</div>
              </td>
              <td class="px-3 py-2.5 text-center font-mono text-xs">{{ r.sh_type }}</td>
              <td class="px-3 py-2.5 text-xs text-neutral-700">{{ shTypeLabel(r.sh_type) }}</td>
              <td class="px-3 py-2.5 text-right font-mono">{{ r.count }}</td>
              <td class="px-5 py-2.5 text-right font-mono">{{ formatMoney(r.amount, 'CZK') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else class="bg-surface border border-dashed border-neutral-300 rounded-md p-6 text-center text-sm text-neutral-500">
        {{ t('reports.shv.no_data') }}
      </div>

      <!-- Tip -->
      <div class="bg-primary-50 border border-primary-200 rounded-md p-3 text-sm text-primary-700">
        💡 {{ t('reports.shv.note') }}
      </div>
    </div>
  </div>
</template>

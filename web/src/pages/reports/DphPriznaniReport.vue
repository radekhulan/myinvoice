<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi, type DphPriznaniPreview, type DphSettings, type DphTrendRow, type DphDraftsPrediction } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import { useYearOptions } from '@/composables/useYearOptions'

const { t, locale } = useI18n()

const now = new Date()
const year = ref(now.getFullYear())
const month = ref(now.getMonth() + 1)

const settings = ref<DphSettings | null>(null)
const preview = ref<DphPriznaniPreview | null>(null)
const trend = ref<DphTrendRow[]>([])
const draftsPrediction = ref<DphDraftsPrediction | null>(null)
const loading = ref(false)
const error = ref('')

// Period override (jinak default ze settings.vat_period)
const periodOverride = ref<'monthly' | 'quarterly' | ''>('')
const effectivePeriod = computed<'monthly' | 'quarterly'>(() => {
  if (periodOverride.value) return periodOverride.value
  return (settings.value?.vat_period as 'monthly' | 'quarterly') || 'monthly'
})

const isQuarterly = computed(() => effectivePeriod.value === 'quarterly')

// Quarter 1-4 z měsíce
const currentQuarter = computed(() => Math.ceil(month.value / 3))

async function loadAll() {
  loading.value = true
  error.value = ''
  try {
    const [s, p, tr, dp] = await Promise.all([
      reportsApi.dphSettings(),
      reportsApi.dphPreview(year.value, month.value, periodOverride.value || undefined),
      reportsApi.dphTrend(12),
      reportsApi.dphDraftsPrediction(year.value, month.value, periodOverride.value || undefined).catch(() => null),
    ])
    settings.value = s
    preview.value = p
    trend.value = tr
    draftsPrediction.value = dp
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function downloadXml() {
  if (!preview.value) return
  window.open(reportsApi.dphDownloadUrl(year.value, month.value, periodOverride.value || undefined), '_blank')
}

const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long' })
  )
)

// Distinct roky z dat (issue #33).
const yearOptions = useYearOptions('combined', year)

const quarterOptions = [1, 2, 3, 4]

// Pro quarterly: month input = poslední měsíc kvartálu (3/6/9/12)
function setQuarter(q: number) {
  month.value = q * 3
}

const linesSorted = computed(() => {
  if (!preview.value) return []
  return Object.entries(preview.value.summary.lines)
    .map(([line, data]) => ({ line, ...data }))
    .sort((a, b) => Number(a.line) - Number(b.line))
})

const outputLines = computed(() => linesSorted.value.filter(l => Number(l.line) < 40))
const inputLines = computed(() => linesSorted.value.filter(l => Number(l.line) >= 40))

// Vývoj DPH řazený sestupně dle data — nejnovější měsíc nahoře.
const trendSorted = computed(() => [...trend.value].sort((a, b) => b.period.localeCompare(a.period)))

// Trend chart helpers
const trendMaxVat = computed(() => {
  let max = 0
  for (const t of trend.value) {
    if (t.vat_output > max) max = t.vat_output
    if (t.vat_input > max) max = t.vat_input
    if (Math.abs(t.vat_due) > max) max = Math.abs(t.vat_due)
  }
  return max
})
function trendBarPct(value: number): number {
  if (trendMaxVat.value === 0) return 0
  return Math.round((Math.abs(value) / trendMaxVat.value) * 100)
}
function formatMonthLabel(period: string): string {
  const [y, m] = period.split('-')
  if (!y || !m) return period
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('cs-CZ', { month: 'short', year: '2-digit' })
}

// Deadline countdown
const daysToDeadline = computed(() => {
  if (!preview.value?.summary.submission_deadline) return null
  const deadline = new Date(preview.value.summary.submission_deadline)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.ceil((deadline.getTime() - today.getTime()) / (1000 * 60 * 60 * 24))
})

watch([year, month, periodOverride], loadAll)
onMounted(loadAll)
</script>

<template>
  <div class="max-w-5xl">
    <!-- ⚠️ Prominent disclaimer -->
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
        <h1 class="text-2xl font-semibold">{{ t('reports.dph.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">
          {{ t('reports.dph.subtitle') }}
          <span v-if="settings?.vat_period" class="ml-2 px-2 py-0.5 text-xs rounded border bg-primary-50 text-primary-700 border-primary-500/40">
            {{ t('reports.dph.you_are_' + settings.vat_period) }}
          </span>
        </p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Period toggle (override settings.vat_period) -->
        <div class="flex rounded-md border border-neutral-300 overflow-hidden text-sm">
          <button type="button" @click="periodOverride = 'monthly'"
            :class="effectivePeriod === 'monthly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="px-3 h-9 cursor-pointer">
            {{ t('reports.dph.monthly') }}
          </button>
          <button type="button" @click="periodOverride = 'quarterly'"
            :class="effectivePeriod === 'quarterly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="px-3 h-9 cursor-pointer border-l border-neutral-300">
            {{ t('reports.dph.quarterly') }}
          </button>
        </div>

        <!-- Quarter picker pokud quarterly, jinak month -->
        <select v-if="isQuarterly" :value="currentQuarter" @change="setQuarter(Number(($event.target as HTMLSelectElement).value))"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="q in quarterOptions" :key="q" :value="q">Q{{ q }}</option>
        </select>
        <select v-else v-model.number="month" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <button type="button" @click="downloadXml" :disabled="loading || !preview"
          class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('reports.dph.download_xml') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-else-if="preview" class="space-y-4">
      <!-- Warnings -->
      <div v-if="preview.warnings.length > 0" class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700">
        <strong>{{ t('reports.dph.warnings') }}:</strong>
        <ul class="mt-1 list-disc list-inside">
          <li v-for="w in preview.warnings" :key="w">{{ w }}</li>
        </ul>
      </div>

      <!-- Rekapitulace KPI cards (4 — přidán Termín) -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.dph.vat_output') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ formatMoney(preview.summary.total_vat_output, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.dph.vat_output_hint') }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.dph.vat_input') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ formatMoney(preview.summary.total_vat_input, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.dph.vat_input_hint') }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ preview.summary.is_excess_deduction ? t('reports.dph.excess_deduction') : t('reports.dph.tax_due') }}
          </div>
          <div class="text-xl font-bold font-mono"
            :class="preview.summary.is_excess_deduction ? 'text-success-600' : 'text-danger-500'">
            {{ formatMoney(Math.abs(preview.summary.tax_due), 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ preview.summary.is_excess_deduction ? t('reports.dph.excess_deduction_hint') : t('reports.dph.tax_due_hint') }}
          </div>
        </div>
        <!-- Deadline countdown -->
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

      <!-- Predikce DPH pro zvolené období (zahrnuje vystavené i koncepty) -->
      <div v-if="draftsPrediction && (draftsPrediction.sale_count + draftsPrediction.purchase_count) > 0"
        class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-warning-50 border border-warning-500/40 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-warning-700 font-medium mb-1">{{ t('reports.dph.prediction_vat_output') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ formatMoney(draftsPrediction.vat_output, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('reports.dph.prediction_n_sale', { n: draftsPrediction.sale_count, d: draftsPrediction.sale_draft_count }) }}
          </div>
        </div>
        <div class="bg-warning-50 border border-warning-500/40 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-warning-700 font-medium mb-1">{{ t('reports.dph.prediction_vat_input') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ formatMoney(draftsPrediction.vat_input, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('reports.dph.prediction_n_purchase', { n: draftsPrediction.purchase_count, d: draftsPrediction.purchase_draft_count }) }}
          </div>
        </div>
        <div class="bg-warning-50 border border-warning-500/40 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-warning-700 font-medium mb-1">
            {{ draftsPrediction.tax_due >= 0 ? t('reports.dph.prediction_tax_due') : t('reports.dph.prediction_excess_deduction') }}
          </div>
          <div class="text-xl font-bold font-mono"
            :class="draftsPrediction.tax_due >= 0 ? 'text-danger-500' : 'text-success-600'">
            {{ formatMoney(Math.abs(draftsPrediction.tax_due), 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.dph.prediction_tax_due_hint') }}</div>
        </div>
        <div class="bg-warning-100 border border-warning-500/50 rounded-lg shadow-sm p-5 flex flex-col justify-center">
          <div class="text-xs uppercase tracking-wide text-warning-700 font-semibold mb-1 flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 0 0 1.74-3l-6.93-12a2 2 0 0 0-3.48 0l-6.93 12a2 2 0 0 0 1.74 3z"/></svg>
            {{ t('reports.dph.prediction_label') }}
          </div>
          <div class="text-xs text-neutral-700 leading-snug">{{ t('reports.dph.prediction_explanation') }}</div>
        </div>
      </div>

      <!-- Monthly DPH trend chart — tabulkový layout, čísla zarovnaná doprava -->
      <div v-if="trend.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('reports.dph.monthly_trend') }}</h3>
          <div class="flex items-center gap-3 text-xs">
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-danger-400"></span>{{ t('reports.dph.vat_output') }}</span>
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-success-500"></span>{{ t('reports.dph.vat_input') }}</span>
          </div>
        </header>
        <table class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2 w-20">{{ t('reports.dph.line') }}</th>
              <th class="text-right px-3 py-2 w-32">{{ t('reports.dph.vat_output') }}</th>
              <th class="px-3 py-2">&nbsp;</th>
              <th class="text-right px-3 py-2 w-32">{{ t('reports.dph.vat_input') }}</th>
              <th class="px-3 py-2">&nbsp;</th>
              <th class="text-right px-5 py-2 w-32">{{ t('reports.dph.net_due') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="m in trendSorted" :key="m.period">
              <td class="px-5 py-2 font-medium text-neutral-700">{{ formatMonthLabel(m.period) }}</td>
              <td class="px-3 py-2 text-right font-mono text-neutral-700">{{ formatMoney(m.vat_output, 'CZK') }}</td>
              <td class="px-1 py-2 w-32">
                <div class="bg-danger-400 h-2 rounded-sm" :style="{ width: trendBarPct(m.vat_output) + '%' }"></div>
              </td>
              <td class="px-3 py-2 text-right font-mono text-neutral-700">{{ formatMoney(m.vat_input, 'CZK') }}</td>
              <td class="px-1 py-2 w-32">
                <div class="bg-success-500 h-2 rounded-sm" :style="{ width: trendBarPct(m.vat_input) + '%' }"></div>
              </td>
              <td class="px-5 py-2 text-right font-mono"
                :class="m.vat_due >= 0 ? 'text-danger-500' : 'text-success-600'">
                {{ m.vat_due >= 0 ? '↑' : '↓' }} {{ formatMoney(Math.abs(m.vat_due), 'CZK') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- DPH na výstupu -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('reports.dph.output_section') }}</h3>
        </header>
        <div v-if="outputLines.length === 0" class="p-8 text-center text-neutral-500 text-sm">
          {{ t('reports.dph.no_output_lines') }}
        </div>
        <table v-else class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2 w-16">{{ t('reports.dph.line') }}</th>
              <th class="text-left px-3 py-2">{{ t('reports.dph.description') }}</th>
              <th class="text-right px-3 py-2">{{ t('reports.dph.base') }}</th>
              <th class="text-right px-5 py-2">{{ t('reports.dph.vat') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="l in outputLines" :key="l.line" class="hover:bg-neutral-50">
              <td class="px-5 py-2.5 font-mono text-neutral-700 font-medium">{{ l.line }}</td>
              <td class="px-3 py-2.5 text-neutral-700">{{ l.label }}</td>
              <td class="px-3 py-2.5 text-right font-mono">{{ formatMoney(l.base, 'CZK') }}</td>
              <td class="px-5 py-2.5 text-right font-mono">{{ formatMoney(l.vat, 'CZK') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- DPH na vstupu -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('reports.dph.input_section') }}</h3>
        </header>
        <div v-if="inputLines.length === 0" class="p-8 text-center text-neutral-500 text-sm">
          {{ t('reports.dph.no_input_lines') }}
        </div>
        <table v-else class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2 w-16">{{ t('reports.dph.line') }}</th>
              <th class="text-left px-3 py-2">{{ t('reports.dph.description') }}</th>
              <th class="text-right px-3 py-2">{{ t('reports.dph.base') }}</th>
              <th class="text-right px-5 py-2">{{ t('reports.dph.vat') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="l in inputLines" :key="l.line" class="hover:bg-neutral-50">
              <td class="px-5 py-2.5 font-mono text-neutral-700 font-medium">{{ l.line }}</td>
              <td class="px-3 py-2.5 text-neutral-700">{{ l.label }}</td>
              <td class="px-3 py-2.5 text-right font-mono">{{ formatMoney(l.base, 'CZK') }}</td>
              <td class="px-5 py-2.5 text-right font-mono">{{ formatMoney(l.vat, 'CZK') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Tip -->
      <div v-if="outputLines.length === 0 && inputLines.length === 0" class="bg-primary-50 border border-primary-200 rounded-md p-3 text-sm text-primary-700">
        💡 {{ t('reports.dph.no_data_hint') }}
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import { useYearOptions } from '@/composables/useYearOptions'

const { t } = useI18n()

const now = new Date()
const year = ref(now.getFullYear() - 1) // typicky podáváme za uplynulý rok
const taxpayerType = ref<'fo' | 'po'>('fo')

const preview = ref<Awaited<ReturnType<typeof reportsApi.incomeTaxPreview>> | null>(null)
const loading = ref(false)
const error = ref('')

async function loadPreview() {
  loading.value = true
  error.value = ''
  try {
    preview.value = await reportsApi.incomeTaxPreview(year.value, taxpayerType.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function downloadXml() {
  window.open(reportsApi.incomeTaxDownloadUrl(year.value, taxpayerType.value), '_blank')
}

// Distinct roky z dat (issue #33) — typicky se podává za uplynulý rok, ale
// uživatel může chtít zpětně sestavit přiznání za starší roky (kdy přiznání
// zpoždil / kontroluje archiv).
const yearOptions = useYearOptions('combined', year)

watch([year, taxpayerType], loadPreview)
onMounted(loadPreview)
</script>

<template>
  <div class="max-w-5xl">
    <!-- ⚠️ MEGA disclaimer — DPFO/DPPO jsou MVP, NE production -->
    <div class="bg-danger-50 border-2 border-danger-500 rounded-lg p-4 mb-4">
      <div class="flex items-start gap-3">
        <svg class="w-6 h-6 text-danger-600 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-1-8a1 1 0 0 0-1 1v3a1 1 0 0 0 2 0V6a1 1 0 0 0-1-1z" clip-rule="evenodd"/>
        </svg>
        <div class="text-sm text-danger-700">
          <p class="font-semibold mb-1">{{ t('reports.income_tax.mvp_disclaimer_title') }}</p>
          <p>{{ t('reports.income_tax.mvp_disclaimer_body') }}</p>
        </div>
      </div>
    </div>

    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.income_tax.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.income_tax.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <div class="flex rounded-md border border-neutral-300 overflow-hidden text-sm">
          <button type="button" @click="taxpayerType = 'fo'"
            :class="taxpayerType === 'fo' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="px-3 h-9 cursor-pointer">DPFO</button>
          <button type="button" @click="taxpayerType = 'po'"
            :class="taxpayerType === 'po' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="px-3 h-9 cursor-pointer border-l border-neutral-300">DPPO</button>
        </div>
        <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <button type="button" @click="downloadXml" :disabled="loading || !preview"
          class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('reports.income_tax.download_xml') }}
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

      <!-- Orientační čísla -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.income_tax.revenue_orientacni') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ formatMoney(preview.summary.revenue_orientacni, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('reports.income_tax.revenue_hint') }} ·
            {{ preview.summary.is_vat_payer ? t('reports.income_tax.vat_base_excl') : t('reports.income_tax.vat_base_incl') }}
          </div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.income_tax.costs_orientacni') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ formatMoney(preview.summary.costs_orientacni, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('reports.income_tax.costs_hint') }} ·
            {{ preview.summary.is_vat_payer ? t('reports.income_tax.vat_base_excl') : t('reports.income_tax.vat_base_incl') }}
          </div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.income_tax.profit_orientacni') }}</div>
          <div class="text-xl font-bold font-mono"
            :class="preview.summary.profit_orientacni >= 0 ? 'text-success-600' : 'text-danger-500'">
            {{ formatMoney(preview.summary.profit_orientacni, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.income_tax.profit_hint') }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.dph.deadline') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ preview.summary.submission_deadline }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.income_tax.deadline_hint') }}</div>
        </div>
      </div>

      <!-- Co XML chybí -->
      <div class="bg-warning-50 border border-warning-500/40 rounded-lg p-5">
        <h3 class="text-sm font-semibold text-warning-700 mb-2">{{ t('reports.income_tax.missing_data_title') }}</h3>
        <ul class="text-sm text-warning-700 space-y-1 list-disc list-inside">
          <li>{{ t('reports.income_tax.missing_odpisy') }}</li>
          <li>{{ t('reports.income_tax.missing_mzdy') }}</li>
          <li>{{ t('reports.income_tax.missing_socsec') }}</li>
          <li>{{ t('reports.income_tax.missing_zaloha') }}</li>
          <li>{{ t('reports.income_tax.missing_slevy') }}</li>
        </ul>
        <p class="text-sm text-warning-700 mt-3">{{ t('reports.income_tax.missing_advice') }}</p>
      </div>
    </div>
  </div>
</template>

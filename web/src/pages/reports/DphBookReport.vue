<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi, type DphBookPreview } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'

const { t, locale } = useI18n()

const now = new Date()
const year = ref(now.getFullYear())
const month = ref(now.getMonth() + 1)

const preview = ref<DphBookPreview | null>(null)
const loading = ref(false)
const error = ref('')

async function loadPreview() {
  loading.value = true
  error.value = ''
  try {
    preview.value = await reportsApi.dphBookPreview(year.value, month.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function downloadPdf() {
  window.open(reportsApi.dphBookPdfUrl(year.value, month.value), '_blank')
}

const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long' })
  )
)
// Distinct roky z dat (issue #33).
const yearOptions = useYearOptions('combined', year)

function fmtMoney(v: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(v) || 0)
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (isNaN(d.getTime())) return ''
  return d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

watch([year, month], loadPreview)
onMounted(loadPreview)
</script>

<template>
  <div class="max-w-full">
    <!-- Disclaimer banner — sjednoceno s DPH přiznání (červený, vyznění "informativní, ne EPO podání") -->
    <div class="bg-danger-50 border-2 border-danger-500 rounded-lg p-4 mb-4">
      <div class="flex items-start gap-3">
        <svg class="w-6 h-6 text-danger-600 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-1-8a1 1 0 0 0-1 1v3a1 1 0 0 0 2 0V6a1 1 0 0 0-1-1z" clip-rule="evenodd"/>
        </svg>
        <div class="text-sm text-danger-700">
          <p class="font-semibold mb-1">{{ t('reports.dph_book.disclaimer_title') }}</p>
          <p>{{ t('reports.dph_book.disclaimer_body') }}</p>
        </div>
      </div>
    </div>

    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.dph_book.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.dph_book.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <select v-model.number="month" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <button type="button" @click="downloadPdf" :disabled="loading || !preview"
          class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          {{ t('reports.dph_book.download_pdf') }}
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
      <!-- Period info -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
          {{ t('reports.dph_book.period_label') }}
        </div>
        <div class="text-lg font-semibold font-mono">{{ preview.period.label }}</div>
      </div>

      <!-- No data -->
      <div v-if="preview.sections.length === 0"
        class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-500">
        {{ t('reports.dph_book.no_data') }}
      </div>

      <!-- Sections -->
      <div v-for="section in preview.sections" :key="section.key"
        class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="sticky top-0 px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold text-neutral-800">
            <span class="font-mono">{{ section.key }}</span>
            — {{ section.direction }}:
            <span class="text-neutral-600">{{ section.label }}</span>
          </h3>
        </header>
        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.dph_book.col.tax_date') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.dph_book.col.accounting_date') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.dph_book.col.doc_number') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.dph_book.col.description') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.dph_book.col.base_czk') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.dph_book.col.vat_czk') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.dph_book.col.total_czk') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.dph_book.col.partner') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.dph_book.col.partner_dic') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.dph_book.col.original_doc_number') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.dph_book.col.original_tax_date') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.dph_book.col.kh_code') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(row, idx) in section.rows" :key="idx"
                :class="row.is_draft ? 'bg-neutral-50 text-neutral-500 italic' : ''">
                <td class="px-2 py-1.5 whitespace-nowrap font-mono">{{ fmtDate(row.tax_date) }}</td>
                <td class="px-2 py-1.5 whitespace-nowrap font-mono">{{ fmtDate(row.accounting_date) }}</td>
                <td class="px-2 py-1.5 whitespace-nowrap">
                  <span v-if="row.is_draft"
                    class="inline-block bg-warning-100 text-warning-700 text-[10px] font-bold px-1 py-px rounded mr-1">
                    {{ t('reports.dph_book.draft_badge') }}
                  </span>
                  <span class="font-mono">{{ row.direction === 'issued' ? 'VF' : 'PF' }} {{ row.doc_number }}</span>
                </td>
                <td class="px-2 py-1.5">{{ row.description }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.base) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.vat) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.total) }}</td>
                <td class="px-2 py-1.5">{{ row.counterparty_name }}</td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ row.counterparty_dic }}</td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ row.original_doc_number || '' }}</td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ fmtDate(row.tax_date) }}</td>
                <td class="px-2 py-1.5">{{ row.kh_section || '' }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 font-semibold">
              <tr>
                <td colspan="4" class="px-2 py-2 text-xs">
                  {{ t('reports.dph_book.subtotal') }} {{ section.key }}
                </td>
                <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(section.subtotal_base) }}</td>
                <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(section.subtotal_vat) }}</td>
                <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(section.subtotal_total) }}</td>
                <td colspan="5"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Total summary — odděleně uskutečněná (daň na výstupu) a přijatá (odpočet) -->
      <div v-if="preview.sections.length > 0" class="grid gap-4 md:grid-cols-2">
        <!-- Uskutečněná plnění -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-3">
            {{ t('reports.dph_book.summary_issued') }}
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <div class="text-[11px] uppercase text-neutral-400">{{ t('reports.dph_book.col.base_czk') }}</div>
              <div class="text-base font-bold font-mono">{{ fmtMoney(preview.totals.issued.base) }}</div>
            </div>
            <div>
              <div class="text-[11px] uppercase text-neutral-400">{{ t('reports.dph_book.col.vat_czk') }}</div>
              <div class="text-base font-bold font-mono">{{ fmtMoney(preview.totals.issued.vat) }}</div>
            </div>
            <div>
              <div class="text-[11px] uppercase text-neutral-400">{{ t('reports.dph_book.col.total_czk') }}</div>
              <div class="text-base font-bold font-mono">{{ fmtMoney(preview.totals.issued.total) }}</div>
            </div>
          </div>
        </div>
        <!-- Přijatá plnění -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-3">
            {{ t('reports.dph_book.summary_received') }}
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <div class="text-[11px] uppercase text-neutral-400">{{ t('reports.dph_book.col.base_czk') }}</div>
              <div class="text-base font-bold font-mono">{{ fmtMoney(preview.totals.received.base) }}</div>
            </div>
            <div>
              <div class="text-[11px] uppercase text-neutral-400">{{ t('reports.dph_book.col.vat_czk') }}</div>
              <div class="text-base font-bold font-mono">{{ fmtMoney(preview.totals.received.vat) }}</div>
            </div>
            <div>
              <div class="text-[11px] uppercase text-neutral-400">{{ t('reports.dph_book.col.total_czk') }}</div>
              <div class="text-base font-bold font-mono">{{ fmtMoney(preview.totals.received.total) }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Výsledná DPH = na výstupu − odpočet (kladná = povinnost, záporná = nadměrný odpočet) -->
      <div v-if="preview.sections.length > 0"
        class="border rounded-lg p-4 flex items-center justify-between"
        :class="preview.totals.vat_balance >= 0
          ? 'bg-primary-50 border-primary-200'
          : 'bg-success-50 border-success-200'">
        <div>
          <div class="text-xs uppercase tracking-wide font-medium"
            :class="preview.totals.vat_balance >= 0 ? 'text-primary-700' : 'text-success-700'">
            {{ t('reports.dph_book.vat_balance') }}
          </div>
          <div class="text-sm text-neutral-600 mt-0.5">
            {{ preview.totals.vat_balance >= 0
              ? t('reports.dph_book.vat_balance_due')
              : t('reports.dph_book.vat_balance_refund') }}
          </div>
        </div>
        <div class="text-2xl font-bold font-mono"
          :class="preview.totals.vat_balance >= 0 ? 'text-primary-700' : 'text-success-700'">
          {{ fmtMoney(Math.abs(preview.totals.vat_balance)) }}
        </div>
      </div>

      <p class="text-xs text-neutral-400 italic text-center">{{ t('reports.dph_book.note_d_marker') }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { purchaseInvoicesApi } from '@/api/purchaseInvoices'

const { t } = useI18n()

type Format = 'pdf-zip' | 'pohoda' | 'isdoc'
type DateBy = 'tax' | 'issue' | 'received'

const format = ref<Format>('pdf-zip')
// Default "issue" (datum vystavení) — odpovídá tomu, co je na fakturách napsané a co
// uživatel typicky používá k organizaci archivu. tax (DUZP) má smysl pro DPH výkazy
// a received (přijetí) jen pro audit kdy faktura přišla.
const dateBy = ref<DateBy>('issue')
const month = ref<string>((() => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
})())

function download() {
  window.open(
    purchaseInvoicesApi.exportUrl(month.value, dateBy.value, format.value),
    '_blank',
  )
}

// All 3 formats live (pdf-zip + isdoc + pohoda)
const isComingSoon = computed(() => false)
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('purchase_invoice.export.page_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('purchase_invoice.export.page_subtitle') }}</p>
    </div>

    <!-- Box: nastavení exportu -->
    <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
      <!-- Formát výběr -->
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-2">{{ t('purchase_invoice.export.format_label') }}</label>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
          <label
            v-for="opt in [
              { val: 'pdf-zip' as Format, label: t('purchase_invoice.export.fmt_pdf'),    hint: t('purchase_invoice.export.fmt_pdf_hint') },
              { val: 'pohoda' as Format,  label: t('purchase_invoice.export.fmt_pohoda'), hint: t('purchase_invoice.export.fmt_pohoda_hint') },
              { val: 'isdoc' as Format,   label: t('purchase_invoice.export.fmt_isdoc'),  hint: t('purchase_invoice.export.fmt_isdoc_hint') },
            ]"
            :key="opt.val"
            class="cursor-pointer block p-3 border rounded-md transition"
            :class="format === opt.val
              ? 'border-primary-500 bg-primary-50 ring-2 ring-primary-500/20'
              : 'border-neutral-200 hover:border-neutral-300 hover:bg-neutral-50'"
          >
            <input type="radio" :value="opt.val" v-model="format" class="sr-only" />
            <div class="flex items-center gap-2">
              <svg v-if="opt.val === 'pdf-zip'" class="w-6 h-7" viewBox="0 0 32 36" xmlns="http://www.w3.org/2000/svg">
                <path fill="#dc2626" d="M4 2h16l8 8v22a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>
                <path fill="#ffffff" opacity="0.35" d="M20 2v8h8z"/>
                <text x="16" y="26" fill="#ffffff" font-family="Arial,Helvetica,sans-serif" font-size="8" font-weight="700" text-anchor="middle" letter-spacing="0.3">PDF</text>
              </svg>
              <svg v-else-if="opt.val === 'pohoda'" class="w-5 h-5 text-warning-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414"/></svg>
              <svg v-else class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2"/></svg>
              <span class="text-sm font-medium">{{ opt.label }}</span>
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ opt.hint }}</p>
          </label>
        </div>
      </div>

      <!-- Měsíc -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.export.month_label') }}</label>
          <input v-model="month" type="month" required
                 class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.export.date_by_label') }}</label>
          <select v-model="dateBy" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option value="tax">{{ t('purchase_invoice.fields.tax_date') }}</option>
            <option value="issue">{{ t('purchase_invoice.fields.issue_date') }}</option>
            <option value="received">{{ t('purchase_invoice.fields.received_at') }}</option>
          </select>
        </div>
      </div>

      <!-- Info per format -->
      <div v-if="format === 'pdf-zip'" class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">
        <strong>{{ t('purchase_invoice.export.pdf_priority_title') }}:</strong>
        {{ t('purchase_invoice.export.pdf_priority_hint') }}
      </div>
      <div v-else-if="format === 'isdoc'" class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">
        <strong>{{ t('purchase_invoice.export.bulk_isdoc_title') }}:</strong>
        {{ t('purchase_invoice.export.bulk_isdoc_hint') }}
      </div>
      <div v-else-if="format === 'pohoda'" class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">
        <strong>{{ t('purchase_invoice.export.bulk_pohoda_title') }}:</strong>
        {{ t('purchase_invoice.export.bulk_pohoda_hint') }}
      </div>

      <div class="flex items-center justify-end gap-2 pt-2">
        <button
          type="button"
          @click="download"
          :disabled="isComingSoon"
          class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 disabled:cursor-not-allowed text-white text-sm font-medium rounded-md inline-flex items-center gap-2"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('purchase_invoice.export.download') }}
        </button>
      </div>
    </div>
  </div>
</template>

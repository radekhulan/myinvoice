<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Format = 'pdf-zip' | 'isdoc' | 'pohoda'
const format = ref<Format>('pdf-zip')
const month = ref(new Date().toISOString().slice(0, 7)) // YYYY-MM
const type = ref<'' | 'invoice' | 'proforma' | 'credit_note'>('')
const dateBy = ref<'issue' | 'tax'>('issue')
const downloading = ref(false)
const error = ref('')

async function downloadExport() {
  downloading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({
      format: format.value,
      month: month.value,
      date_by: dateBy.value,
    })
    if (type.value) params.set('type', type.value)
    const url = `/api/admin/export?${params.toString()}`

    // fetch + Blob → trigger download. Posíláme X-Supplier-Id explicitně (není to axios).
    const sid = localStorage.getItem('myinvoice.current_supplier_id') || ''
    const headers: Record<string, string> = {}
    if (/^\d+$/.test(sid)) headers['X-Supplier-Id'] = sid
    const resp = await fetch(url, { credentials: 'include', headers })
    if (!resp.ok) {
      const j = await resp.json().catch(() => null)
      error.value = j?.error?.message || `HTTP ${resp.status}`
      return
    }
    const blob = await resp.blob()
    const dispo = resp.headers.get('Content-Disposition') || ''
    const m = dispo.match(/filename="?([^"]+)"?/)
    const ext = format.value === 'pohoda' ? 'xml' : (format.value === 'isdoc' ? 'isdoc' : 'zip')
    const filename = m ? m[1] : `myinvoice-${month.value}.${ext}`

    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = filename
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(a.href)
  } catch (e: any) {
    error.value = e?.message || t('export.download_failed')
  } finally {
    downloading.value = false
  }
}

const monthLabel = (m: string): string => {
  const [y, mm] = m.split('-')
  const months = t('common.months_long') as unknown as string[]
  return `${months[Number(mm) - 1]} ${y}`
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('export.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('export.subtitle') }}</p>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm max-w-md">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-2">{{ t('export.format') }} *</label>
          <div class="space-y-2">
            <label v-for="f in (['pdf-zip', 'isdoc', 'pohoda'] as const)" :key="f"
              class="flex items-start gap-3 p-3 border rounded-md cursor-pointer transition"
              :class="format === f ? 'border-primary-500 bg-primary-50 ring-2 ring-primary-500/20' : 'border-neutral-200 hover:border-neutral-300 hover:bg-neutral-50'">
              <input v-model="format" type="radio" :value="f" class="sr-only" />
              <!-- Barevné ikony per formát — sjednoceno s /purchase-invoices/export -->
              <svg v-if="f === 'pdf-zip'" class="w-6 h-7 shrink-0" viewBox="0 0 32 36" xmlns="http://www.w3.org/2000/svg">
                <path fill="#dc2626" d="M4 2h16l8 8v22a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>
                <path fill="#ffffff" opacity="0.35" d="M20 2v8h8z"/>
                <text x="16" y="26" fill="#ffffff" font-family="Arial,Helvetica,sans-serif" font-size="8" font-weight="700" text-anchor="middle" letter-spacing="0.3">PDF</text>
              </svg>
              <svg v-else-if="f === 'pohoda'" class="w-6 h-7 shrink-0 text-warning-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414"/>
              </svg>
              <svg v-else class="w-6 h-7 shrink-0 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2"/>
              </svg>
              <div class="flex-1">
                <div class="text-sm font-medium">{{ t('export.format_' + f.replace('-', '_')) }}</div>
                <div class="text-xs text-neutral-500">{{ t('export.format_' + f.replace('-', '_') + '_hint') }}</div>
              </div>
            </label>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('export.month') }} *</label>
          <input v-model="month" type="month" required
            class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          <p class="text-xs text-neutral-500 mt-1">{{ monthLabel(month) }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-2">{{ t('export.filter_by') }}</label>
          <div class="space-y-2">
            <label class="flex items-start gap-2 p-2.5 border rounded-md cursor-pointer"
              :class="dateBy === 'issue' ? 'border-primary-500 bg-primary-50' : 'border-neutral-200'">
              <input v-model="dateBy" type="radio" value="issue" class="mt-0.5" />
              <div>
                <div class="text-sm font-medium">{{ t('export.by_issue') }}</div>
                <div class="text-xs text-neutral-500"><i18n-t keypath="export.by_issue_hint"><template #field><span class="font-mono">issue_date</span></template></i18n-t></div>
              </div>
            </label>
            <label class="flex items-start gap-2 p-2.5 border rounded-md cursor-pointer"
              :class="dateBy === 'tax' ? 'border-primary-500 bg-primary-50' : 'border-neutral-200'">
              <input v-model="dateBy" type="radio" value="tax" class="mt-0.5" />
              <div>
                <div class="text-sm font-medium">{{ t('export.by_tax') }}</div>
                <div class="text-xs text-neutral-500">
                  <i18n-t keypath="export.by_tax_hint">
                    <template #field><span class="font-mono">tax_date</span></template>
                    <template #fallback><span class="font-mono">issue_date</span></template>
                  </i18n-t>
                </div>
              </div>
            </label>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('export.type_optional') }}</label>
          <select v-model="type" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option value="">{{ t('export.type_all') }}</option>
            <option value="invoice">{{ t('export.type_invoice_only') }}</option>
            <option value="proforma">{{ t('export.type_proforma_only') }}</option>
            <option value="credit_note">{{ t('export.type_credit_only') }}</option>
          </select>
        </div>

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </div>

        <button @click="downloadExport" :disabled="downloading"
          class="cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center justify-center gap-2">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ downloading ? t('export.preparing') : t('export.download') }}
        </button>
        <p class="text-xs text-neutral-500">{{ t('export.hint') }}</p>
      </div>
    </div>
  </div>
</template>

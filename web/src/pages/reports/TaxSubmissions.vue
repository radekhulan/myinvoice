<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'

interface TaxSubmission {
  id: number
  form_code: string
  period_year: number
  period_month: number | null
  period_quarter: number | null
  xml_size_bytes: number
  xml_sha256: string
  validation_status: 'passed' | 'failed' | 'skipped'
  validation_errors: string[]
  summary: Record<string, unknown> | null
  generated_at: string
  notes: string | null
}

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const items = ref<TaxSubmission[]>([])
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const r = await api.get<TaxSubmission[]>('/reports/submissions')
    items.value = r.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function formCodeLabel(c: string): string {
  return ({
    dphdp3: t('reports.submissions.form_dphdp3'),
    dphkh1: t('reports.submissions.form_dphkh1'),
    dphshv: t('reports.submissions.form_dphshv'),
    dpfdp5: t('reports.submissions.form_dpfdp5'),
    dppdp9: t('reports.submissions.form_dppdp9'),
  } as Record<string, string>)[c] || c
}

function periodLabel(s: TaxSubmission): string {
  if (s.period_month !== null) return `${s.period_year}-${String(s.period_month).padStart(2, '0')}`
  if (s.period_quarter !== null) return `${s.period_year} Q${s.period_quarter}`
  return String(s.period_year)
}

function statusBadgeClass(s: string): string {
  if (s === 'passed') return 'bg-success-50 text-success-600'
  if (s === 'failed') return 'bg-danger-50 text-danger-500'
  return 'bg-neutral-100 text-neutral-500'
}

function downloadXml(id: number) {
  const sid = localStorage.getItem('myinvoice.current_supplier_id')
  const params = new URLSearchParams()
  if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
  const qs = params.toString()
  window.open(`/api/reports/submissions/${id}/xml${qs ? '?' + qs : ''}`, '_blank')
}

async function deleteItem(id: number) {
  if (!confirm(t('reports.submissions.delete_confirm'))) return
  try {
    await api.delete(`/reports/submissions/${id}`)
    items.value = items.value.filter(x => x.id !== id)
    toast.success(t('common.deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

const totalSize = computed(() => items.value.reduce((s, x) => s + x.xml_size_bytes, 0))
const isAdmin = computed(() => auth.user?.role === 'admin')

onMounted(load)
</script>

<template>
  <div class="max-w-6xl">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.submissions.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.submissions.subtitle') }}</p>
      </div>
      <button type="button" @click="load"
        class="cursor-pointer h-9 px-3 border border-neutral-300 hover:bg-neutral-50 text-sm rounded-md inline-flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/></svg>
        {{ t('common.refresh') }}
      </button>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg p-8 text-center text-sm text-neutral-400">{{ t('common.loading') }}…</div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">{{ error }}</div>

    <div v-else-if="items.length === 0" class="bg-surface border border-dashed border-neutral-300 rounded-lg p-8 text-center text-sm text-neutral-500">
      {{ t('reports.submissions.empty') }}
    </div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5">{{ t('reports.submissions.form') }}</th>
            <th class="text-left px-3 py-2.5">{{ t('reports.submissions.period') }}</th>
            <th class="text-left px-3 py-2.5">{{ t('reports.submissions.generated_at') }}</th>
            <th class="text-right px-3 py-2.5">{{ t('reports.submissions.size') }}</th>
            <th class="text-center px-3 py-2.5">XSD</th>
            <th class="px-4 py-2.5"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="s in items" :key="s.id" class="hover:bg-neutral-50">
            <td class="px-4 py-2.5 font-medium">{{ formCodeLabel(s.form_code) }}</td>
            <td class="px-3 py-2.5 font-mono text-xs">{{ periodLabel(s) }}</td>
            <td class="px-3 py-2.5 text-xs text-neutral-500">{{ new Date(s.generated_at).toLocaleString() }}</td>
            <td class="px-3 py-2.5 text-right font-mono text-xs">{{ Math.round(s.xml_size_bytes / 1024) }} KiB</td>
            <td class="px-3 py-2.5 text-center">
              <span :class="['inline-block px-2 py-0.5 rounded text-xs font-medium', statusBadgeClass(s.validation_status)]"
                :title="s.validation_errors.length > 0 ? s.validation_errors.join('\n') : ''">
                {{ s.validation_status === 'passed' ? '✓' : s.validation_status === 'failed' ? '✗' : '−' }}
                {{ t('reports.submissions.status_' + s.validation_status) }}
              </span>
              <span v-if="s.validation_errors.length > 0" class="ml-1 text-xs text-danger-500">({{ s.validation_errors.length }})</span>
            </td>
            <td class="px-4 py-2.5 text-right text-xs">
              <button @click="downloadXml(s.id)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">
                {{ t('reports.submissions.download_xml') }}
              </button>
              <button v-if="isAdmin" @click="deleteItem(s.id)" class="cursor-pointer text-danger-500 hover:text-danger-600">
                {{ t('common.delete') }}
              </button>
            </td>
          </tr>
        </tbody>
        <tfoot class="bg-neutral-50">
          <tr>
            <td colspan="3" class="px-4 py-2 text-xs text-neutral-500">
              {{ items.length }} {{ t('reports.submissions.records') }}
            </td>
            <td class="px-3 py-2 text-right font-mono text-xs text-neutral-500">{{ Math.round(totalSize / 1024) }} KiB</td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</template>

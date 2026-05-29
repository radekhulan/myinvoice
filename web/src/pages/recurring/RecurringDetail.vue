<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  recurringApi,
  type RecurringTemplate,
  type GeneratedInvoiceRow,
  type RecurringStatus,
} from '@/api/recurring'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const id = computed(() => Number(route.params.id))
const loading = ref(false)
const busy = ref(false)
const tpl = ref<RecurringTemplate | null>(null)
const invoices = ref<GeneratedInvoiceRow[]>([])

async function load() {
  loading.value = true
  try {
    const [t1, inv] = await Promise.all([
      recurringApi.get(id.value),
      recurringApi.invoices(id.value),
    ])
    tpl.value = t1
    invoices.value = inv
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
    router.push({ name: 'recurring' })
  } finally {
    loading.value = false
  }
}

onMounted(load)

function freqLabel(f: string): string {
  return t(`recurring.frequency_${f}`)
}

function formatDate(d: string | null): string {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('cs-CZ')
}

function formatMoney(n: number, ccy: string): string {
  return n.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + ccy
}

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

const totals = computed(() => {
  if (!tpl.value?.items?.length) return { base: 0, vat: 0, total: 0 }
  const reverseCharge = tpl.value.reverse_charge
  let base = 0
  let vat = 0
  for (const it of tpl.value.items) {
    const lineBase = round2((Number(it.quantity) || 0) * (Number(it.unit_price_without_vat) || 0))
    const ratePct = reverseCharge ? 0 : (Number(it.vat_rate_percent) || 0)
    base += lineBase
    vat += round2(lineBase * (ratePct / 100))
  }
  return { base: round2(base), vat: round2(vat), total: round2(base + vat) }
})

function statusBadgeClass(s: RecurringStatus) {
  return {
    active:  'bg-success-50 text-success-700 border-success-200',
    paused:  'bg-warning-50 text-warning-700 border-warning-200',
    expired: 'bg-neutral-100 text-neutral-500 border-neutral-200',
  }[s]
}

function invoiceBadgeClass(s: string): string {
  if (s === 'paid')      return 'bg-success-50 text-success-700'
  if (s === 'sent')      return 'bg-primary-50 text-primary-700'
  if (s === 'reminded')  return 'bg-warning-50 text-warning-700'
  if (s === 'cancelled') return 'bg-neutral-100 text-neutral-500'
  if (s === 'issued')    return 'bg-neutral-100 text-neutral-700'
  return 'bg-neutral-50 text-neutral-500'
}

async function pauseAction() {
  if (!tpl.value) return
  if (!confirm(t('recurring.pause_confirm', { name: tpl.value.name }))) return
  busy.value = true
  try {
    tpl.value = await recurringApi.pause(id.value)
    toast.success(t('recurring.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
  } finally { busy.value = false }
}

async function resumeAction() {
  if (!tpl.value) return
  if (!confirm(t('recurring.resume_confirm', { name: tpl.value.name, date: formatDate(tpl.value.next_run_date) }))) return
  busy.value = true
  try {
    tpl.value = await recurringApi.resume(id.value)
    toast.success(t('recurring.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
  } finally { busy.value = false }
}

const runNowModal = ref(false)
const runNowDate = ref('')
const runNowMode = ref<'issue' | 'draft'>('issue')
// period_start koncept se vytvoří přes openDraft k plánovanému datu (next_run_date) —
// datum se nevybírá a varování o budoucím datu nedává smysl.
const isPeriodDraft = computed(() => runNowMode.value === 'draft' && tpl.value?.draft_open_mode === 'period_start')

function todayIso(): string {
  return new Date().toISOString().slice(0, 10)
}

function openRunNow(mode: 'issue' | 'draft' = 'issue') {
  if (!tpl.value) return
  runNowMode.value = mode
  runNowDate.value = todayIso()
  runNowModal.value = true
}

function closeRunNow() {
  runNowModal.value = false
}

async function submitRunNow() {
  if (!tpl.value) return
  if (!runNowDate.value) return
  const draft = runNowMode.value === 'draft'
  runNowModal.value = false
  busy.value = true
  try {
    const r = await recurringApi.runNow(id.value, runNowDate.value, draft)
    if (draft) {
      toast.success(t('recurring.run_now_draft_done', { id: r.invoice_id }))
    } else if (r.sent_to.length > 0) {
      toast.success(t('recurring.run_now_with_send', { varsymbol: r.varsymbol ?? `#${r.invoice_id}`, recipients: r.sent_to.join(', ') }))
    } else {
      toast.success(t('recurring.run_now_done', { id: r.invoice_id, varsymbol: r.varsymbol ? ` (${r.varsymbol})` : '' }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
  } finally { busy.value = false }
}

async function removeAction() {
  if (!tpl.value) return
  if (!confirm(t('recurring.delete_confirm', { name: tpl.value.name }))) return
  busy.value = true
  try {
    await recurringApi.delete(id.value)
    toast.success(t('recurring.deleted'))
    router.push({ name: 'recurring' })
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
  } finally { busy.value = false }
}
</script>

<template>
  <div class="max-w-5xl">
    <div v-if="loading" class="text-center text-neutral-400 py-12">…</div>

    <div v-else-if="tpl" class="space-y-4">
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
        <div class="min-w-0">
          <RouterLink :to="{ name: 'recurring' }" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('recurring.back_to_list') }}</RouterLink>
          <h1 class="text-2xl font-semibold flex items-center gap-3 flex-wrap mt-1">
            {{ tpl.name }}
            <span class="text-xs px-2 py-0.5 rounded border whitespace-nowrap" :class="statusBadgeClass(tpl.status)">
              {{ t('recurring.status.' + tpl.status) }}
            </span>
            <span class="text-xs px-2 py-0.5 rounded font-normal bg-primary-50 text-primary-700 border border-primary-200 whitespace-nowrap">
              ↻ {{ t('recurring.title') }}
            </span>
          </h1>
        </div>
        <div class="flex flex-wrap gap-2">
          <button v-if="tpl.status === 'active' && auth.canWrite" @click="openRunNow('issue')" :disabled="busy"
            class="cursor-pointer inline-flex items-center gap-1.5 px-3 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0 0 10 9.87v4.263a1 1 0 0 0 1.555.832l3.197-2.132a1 1 0 0 0 0-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
            {{ t('recurring.actions.run_now') }}
          </button>
          <button v-if="tpl.status === 'active' && auth.canWrite" @click="openRunNow('draft')" :disabled="busy"
            class="cursor-pointer inline-flex items-center gap-1.5 px-3 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z"/></svg>
            {{ t('recurring.actions.run_now_draft') }}
          </button>
          <button v-if="tpl.status === 'active' && auth.canWrite" @click="pauseAction" :disabled="busy"
            class="cursor-pointer inline-flex items-center gap-1.5 px-3 h-9 text-sm border border-warning-500/40 rounded-md text-warning-700 hover:bg-warning-50">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
            {{ t('recurring.actions.pause') }}
          </button>
          <button v-if="tpl.status === 'paused' && auth.canWrite" @click="resumeAction" :disabled="busy"
            class="cursor-pointer inline-flex items-center gap-1.5 px-3 h-9 text-sm border border-success-500/40 rounded-md text-success-700 hover:bg-success-50">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0 0 10 9.87v4.263a1 1 0 0 0 1.555.832l3.197-2.132a1 1 0 0 0 0-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
            {{ t('recurring.actions.resume') }}
          </button>
          <RouterLink v-if="auth.canWrite" :to="{ name: 'recurring-edit', params: { id: tpl.id } }"
            class="cursor-pointer inline-flex items-center gap-1.5 px-3 h-9 text-sm border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            {{ t('recurring.actions.edit') }}
          </RouterLink>
          <button v-if="auth.canWrite" @click="removeAction" :disabled="busy"
            class="cursor-pointer inline-flex items-center gap-1.5 px-3 h-9 text-sm border border-danger-500/40 rounded-md text-danger-700 hover:bg-danger-50">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
            {{ t('recurring.actions.delete') }}
          </button>
        </div>
      </div>

      <!-- Banner: poslední generování (typicky cronem) selhalo -->
      <div v-if="tpl.last_error" class="rounded-lg border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700">
        <div class="font-semibold">⚠ {{ t('recurring.last_error_title') }}</div>
        <div class="mt-0.5">{{ tpl.last_error }}</div>
        <div v-if="tpl.last_error_at" class="mt-0.5 text-xs text-danger-600/80">{{ formatDate(tpl.last_error_at) }}</div>
      </div>

      <!-- Konfigurace -->
      <div class="grid md:grid-cols-3 gap-4">
        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('recurring.section_periodicity') }}</h3>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.frequency') }}</dt><dd>{{ freqLabel(tpl.frequency) }}</dd></div>
            <div v-if="tpl.end_of_month" class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.day_of_month') }}</dt><dd>{{ t('recurring.end_of_month') }}</dd></div>
            <div v-else class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.day_of_month') }}</dt><dd>{{ tpl.day_of_month ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.anchor_date') }}</dt><dd class="font-mono text-xs">{{ formatDate(tpl.anchor_date) }}</dd></div>
            <div v-if="tpl.end_date" class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.end_date') }}</dt><dd class="font-mono text-xs">{{ formatDate(tpl.end_date) }}</dd></div>
            <div class="flex justify-between pt-1 mt-1 border-t border-neutral-100"><dt class="text-neutral-500">{{ t('recurring.next_run_date') }}</dt><dd class="font-mono text-xs font-medium">{{ formatDate(tpl.next_run_date) }}</dd></div>
            <div v-if="tpl.last_run_date" class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.last_run_date') }}</dt><dd class="font-mono text-xs">{{ formatDate(tpl.last_run_date) }}</dd></div>
          </dl>
        </div>

        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('recurring.section_invoice_meta') }}</h3>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between">
              <dt class="text-neutral-500">{{ t('recurring.client') }}</dt>
              <dd>
                <RouterLink :to="{ name: 'client-detail', params: { id: tpl.client_id } }" class="text-primary-700 hover:underline">
                  {{ tpl.client_company_name }}
                </RouterLink>
              </dd>
            </div>
            <div v-if="tpl.project_name" class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.project') }}</dt><dd>{{ tpl.project_name }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.invoice_type') }}</dt><dd>{{ t('type.' + tpl.invoice_type) }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.currency') }}</dt><dd class="font-mono">{{ tpl.currency }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.language') }}</dt><dd>{{ tpl.language.toUpperCase() }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('payment_method.label') }}</dt><dd>{{ t('payment_method.' + tpl.payment_method) }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.payment_due_days') }}</dt><dd>{{ tpl.payment_due_days }}</dd></div>
            <div v-if="tpl.discount_percent > 0" class="flex justify-between"><dt class="text-neutral-500">{{ t('invoice.discount.label') }}</dt><dd>{{ tpl.discount_percent }} %</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.tax_date_mode') }}</dt><dd>{{ t('recurring.tax_date_mode_' + (tpl.tax_date_mode ?? 'same_as_issue')) }}</dd></div>
          </dl>
        </div>

        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('recurring.section_automation') }}</h3>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between gap-2">
              <dt class="text-neutral-500">{{ t('recurring.draft_open_mode') }}</dt>
              <dd class="text-right">{{ t('recurring.draft_open_mode_' + (tpl.draft_open_mode ?? 'at_issue')) }}</dd>
            </div>
            <div v-if="tpl.draft_open_mode === 'period_start'" class="flex justify-between">
              <dt class="text-neutral-500">{{ t('recurring.reminder_days_before') }}</dt>
              <dd>{{ (tpl.reminder_days_before ?? 0) > 0 ? tpl.reminder_days_before : '—' }}</dd>
            </div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.auto_issue') }}</dt><dd>{{ tpl.auto_issue ? '✓' : '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.auto_send_email') }}</dt><dd>{{ tpl.auto_send_email ? '✓' : '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('recurring.increment_month') }}</dt><dd>{{ tpl.increment_month_in_descriptions ? '✓' : '—' }}</dd></div>
          </dl>
        </div>
      </div>

      <!-- Položky šablony -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-neutral-200">
          <h3 class="font-semibold">{{ t('recurring.items') }}</h3>
        </div>
        <div v-if="!tpl.items?.length" class="px-5 py-6 text-sm text-neutral-500 text-center">{{ t('recurring.items_required') }}</div>
        <table v-else class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
            <tr>
              <th class="text-left px-4 py-2 font-medium">{{ t('invoice.items_table.description') }}</th>
              <th class="text-right px-4 py-2 font-medium">{{ t('invoice.items_table.qty') }}</th>
              <th class="text-left px-4 py-2 font-medium">{{ t('invoice.items_table.unit') }}</th>
              <th class="text-right px-4 py-2 font-medium">{{ t('invoice.items_table.unit_price') }}</th>
              <th class="text-center px-4 py-2 font-medium">DPH</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="it in tpl.items" :key="it.id">
              <td class="px-4 py-2">{{ it.description }}</td>
              <td class="px-4 py-2 text-right font-mono">{{ it.quantity }}</td>
              <td class="px-4 py-2">{{ it.unit }}</td>
              <td class="px-4 py-2 text-right font-mono">{{ formatMoney(it.unit_price_without_vat, tpl.currency ?? '') }}</td>
              <td class="px-4 py-2 text-center text-neutral-600">{{ Number(it.vat_rate_percent) > 0 ? it.vat_rate_percent + ' %' : '—' }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="tpl.items?.length" class="px-5 py-3 bg-neutral-50 border-t border-neutral-200">
          <dl class="ml-auto max-w-xs space-y-1 text-sm">
            <div class="flex justify-between">
              <dt class="text-neutral-500">{{ t('invoice.totals.without_vat') }}</dt>
              <dd class="font-mono">{{ formatMoney(totals.base, tpl.currency ?? '') }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-neutral-500">
                {{ t('invoice.totals.vat') }}
                <span v-if="tpl.reverse_charge" class="text-xs text-neutral-400">({{ t('invoice.reverse_charge') }})</span>
              </dt>
              <dd class="font-mono">{{ formatMoney(totals.vat, tpl.currency ?? '') }}</dd>
            </div>
            <div class="flex justify-between pt-1 mt-1 border-t border-neutral-200 font-medium">
              <dt>{{ t('invoice.totals.total') }}</dt>
              <dd class="font-mono">{{ formatMoney(totals.total, tpl.currency ?? '') }}</dd>
            </div>
          </dl>
        </div>
      </div>

      <!-- Vygenerované faktury -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="font-semibold">
            {{ t('recurring.generated_invoices') }}
            <span class="text-neutral-400 font-normal">({{ invoices.length }})</span>
          </h3>
        </div>

        <div v-if="invoices.length === 0" class="px-5 py-8 text-sm text-neutral-500 text-center">
          {{ t('recurring.empty') }}
        </div>

        <!-- Desktop -->
        <div v-else class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
              <tr>
                <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.varsymbol_label') }}</th>
                <th class="text-left px-4 py-2.5 font-medium">Status</th>
                <th class="text-left px-4 py-2.5 font-medium">{{ t('recurring.anchor_date') }}</th>
                <th class="text-left px-4 py-2.5 font-medium">Splatnost</th>
                <th class="text-right px-4 py-2.5 font-medium">Celkem</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="inv in invoices" :key="inv.id"
                @click="router.push({ name: 'invoice-detail', params: { id: inv.id } })"
                class="cursor-pointer hover:bg-neutral-50">
                <td class="px-4 py-3 font-mono text-primary-700">{{ inv.varsymbol ?? `#${inv.id}` }}</td>
                <td class="px-4 py-3">
                  <span class="text-xs px-2 py-0.5 rounded" :class="invoiceBadgeClass(inv.status)">
                    {{ t('status.' + inv.status) }}
                  </span>
                </td>
                <td class="px-4 py-3 font-mono text-xs">{{ formatDate(inv.issue_date) }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ formatDate(inv.due_date) }}</td>
                <td class="px-4 py-3 text-right font-mono">{{ formatMoney(inv.total_with_vat, inv.currency) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile -->
        <div v-if="invoices.length" class="md:hidden divide-y divide-neutral-100">
          <div v-for="inv in invoices" :key="`m-${inv.id}`"
            @click="router.push({ name: 'invoice-detail', params: { id: inv.id } })"
            class="cursor-pointer hover:bg-neutral-50 px-4 py-3">
            <div class="flex items-baseline justify-between gap-2">
              <span class="font-mono text-primary-700 font-medium">{{ inv.varsymbol ?? `#${inv.id}` }}</span>
              <span class="font-mono text-sm">{{ formatMoney(inv.total_with_vat, inv.currency) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
              <span class="text-xs px-2 py-0.5 rounded" :class="invoiceBadgeClass(inv.status)">
                {{ t('status.' + inv.status) }}
              </span>
              <span class="font-mono">{{ formatDate(inv.issue_date) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Run Now modal — date picker s defaultem dnes; varování pokud uživatel zvolí
         budoucí datum (issue_date = budoucnost je daňově problematické). -->
    <div v-if="runNowModal && tpl" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-surface rounded-lg shadow-xl max-w-md w-full p-6">
        <h2 class="text-lg font-semibold mb-1">{{ runNowMode === 'draft' ? t('recurring.run_now_draft_title') : t('recurring.run_now_title') }}</h2>
        <p class="text-sm text-neutral-600 mb-4">{{ tpl.name }}</p>
        <p v-if="runNowMode === 'draft'" class="text-xs text-neutral-500 mb-3 -mt-2">{{ t('recurring.run_now_draft_hint') }}</p>

        <label v-if="!isPeriodDraft" class="block text-sm">
          <span class="text-neutral-700 font-medium">{{ t('recurring.run_now_issue_date_label') }}</span>
          <input v-model="runNowDate" type="date"
            class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md" />
        </label>

        <p v-if="isPeriodDraft" class="text-sm text-neutral-700">
          {{ t('recurring.run_now_draft_period_note', { date: formatDate(tpl.next_run_date) }) }}
        </p>

        <p v-else class="mt-2 text-xs text-neutral-500">
          {{ t('recurring.run_now_next_scheduled', { date: formatDate(tpl.next_run_date) }) }}
        </p>

        <!-- Varování o budoucím datu jen pro „Vygenerovat teď" (vystavení) — u konceptu
             je budoucí issue_date záměr (edituje se celý měsíc), takže se nezobrazuje. -->
        <div v-if="runNowDate > todayIso() && runNowMode !== 'draft'" class="mt-3 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-xs text-warning-700">
          {{ t('recurring.run_now_future_warning') }}
        </div>

        <div class="mt-5 flex justify-end gap-2">
          <button @click="closeRunNow" type="button"
            class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button @click="submitRunNow" type="button" :disabled="!runNowDate || busy"
            class="cursor-pointer h-9 px-3 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ runNowMode === 'draft' ? t('recurring.run_now_draft_submit') : t('recurring.run_now_submit') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

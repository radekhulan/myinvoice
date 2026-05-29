<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { invoicesApi, type MonthGroup, type InvoiceListItem } from '@/api/invoices'
import { formatMoney, formatDate, formatMonth, statusLabel, typeLabel, statusBadgeClass, isOverdue, invoiceRowClass } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { clientsApi, type Client } from '@/api/clients'
import { codebooksApi, type Currency } from '@/api/codebooks'
import { useYearOptions } from '@/composables/useYearOptions'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import WorkReportModal from '@/components/modals/WorkReportModal.vue'

const { t, tm, rt } = useI18n()
const toast = useToast()
const auth = useAuthStore()

useHotkey('ctrl+n', (e) => { e.preventDefault(); router.push('/invoices/new') })

const router = useRouter()
const route = useRoute()

const groups = ref<MonthGroup[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const statusFilter = ref<string>('')
const typeFilter = ref<string>('')
const clientFilter = ref<number | ''>('')
const yearFilter = ref<number | ''>(new Date().getFullYear())
const monthFilter = ref<number | ''>('')
const dateFrom = ref<string>('')
const dateTo = ref<string>('')
const overdueOnly = ref(false)
const unpaidOnly = ref(false)
const currencyFilter = ref<string>('')
const clients = ref<Client[]>([])
const currencies = ref<Currency[]>([])

const selectedIds = ref<number[]>([])
const bulkBusy = ref(false)

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function hasPositiveAmountToPay(inv: InvoiceListItem): boolean {
  if (!['invoice', 'proforma'].includes(inv.invoice_type)) return true
  return Number(inv.amount_to_pay ?? 0) > 0
}

function toggleSelected(id: number) {
  const i = selectedIds.value.indexOf(id)
  if (i === -1) selectedIds.value.push(id)
  else selectedIds.value.splice(i, 1)
}

async function bulkReissue() {
  if (selectedIds.value.length === 0) return
  if (!confirm(t('invoice.bulk_clone_confirm', { n: selectedIds.value.length }))) return
  bulkBusy.value = true
  try {
    const r = await invoicesApi.bulkReissue(selectedIds.value, { increment_month_in_descriptions: true })
    selectedIds.value = []
    if (r.errors.length) {
      toast.warning(t('invoice.bulk_reissue_partial', { ok: r.created.length, err: r.errors.length }))
    } else {
      toast.success(t('invoice.bulk_send_success', { n: r.created.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.bulk_reissue_failed'))
  } finally {
    bulkBusy.value = false
  }
}

// Hromadné odeslání klientům — pouze faktury se status issued/sent/reminded/paid + ne cancellation
const sendableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv =>
      ids.has(inv.id)
      && ['issued', 'sent', 'reminded', 'paid'].includes(inv.status)
      && inv.invoice_type !== 'cancellation'
    )
})

// Hromadné vystavení — jen drafty. Řadíme podle issue_date asc, pak id asc, aby varsymboly šly sekvenčně.
const issuableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv => ids.has(inv.id) && inv.status === 'draft')
    .sort((a, b) => (a.issue_date || '').localeCompare(b.issue_date || '') || (a.id - b.id))
})

// Hromadné označení za zaplacené — jen issued/sent/reminded (ne paid, ne cancelled, ne draft, ne cancellation)
const markPayableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv =>
      ids.has(inv.id)
      && ['issued', 'sent', 'reminded'].includes(inv.status)
      && inv.invoice_type !== 'cancellation'
      && hasPositiveAmountToPay(inv)
    )
})

// Hromadná upomínka — jen běžné faktury (ne proforma/dobropis/storno) ve stavu issued/sent/reminded,
// po splatnosti a placené bankovním převodem (kartové/hotovostní úhrady se neupomínají).
const reminderSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv => {
      if (!ids.has(inv.id)) return false
      if (inv.invoice_type !== 'invoice') return false
      if (!['issued', 'sent', 'reminded'].includes(inv.status)) return false
      if (!hasPositiveAmountToPay(inv)) return false
      if ((inv.payment_method ?? 'bank_transfer') !== 'bank_transfer') return false
      const due = new Date(inv.due_date)
      return due < today
    })
})

async function bulkSendReminders() {
  const list = reminderSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_reminder_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_reminder_confirm', { n: list.length }))) return
  bulkBusy.value = true
  try {
    const r = await invoicesApi.bulkSendReminders(list.map(i => i.id))
    selectedIds.value = []
    if (r.errors.length) {
      const detail = r.errors.map(e => `#${e.invoice_id}: ${e.error}`).join('\n')
      toast.warning(t('invoice.bulk_reminder_partial', { ok: r.sent.length, err: r.errors.length }) + '\n' + detail)
    } else {
      toast.success(t('invoice.bulk_reminder_success', { n: r.sent.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.bulk_reminder_failed'))
  } finally {
    bulkBusy.value = false
  }
}

async function bulkMarkPaid() {
  const list = markPayableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_mark_paid_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_mark_paid_confirm', { n: list.length }))) return
  const today = new Date().toISOString().slice(0, 10)
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        await invoicesApi.markPaid(inv.id, today)
        okCount++
      } catch (e: any) {
        errors.push(`${inv.varsymbol || `#${inv.id}`}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('invoice.bulk_mark_paid_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('invoice.bulk_mark_paid_success', { n: okCount }))
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

async function bulkIssue() {
  const list = issuableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_issue_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_issue_confirm', { n: list.length }))) return
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        await invoicesApi.issue(inv.id)
        okCount++
      } catch (e: any) {
        errors.push(`#${inv.id}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('invoice.bulk_issue_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('invoice.bulk_issue_success', { n: okCount }))
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

async function bulkSend() {
  const list = sendableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_send_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_send_confirm', { n: list.length }))) return
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        await invoicesApi.send(inv.id)
        okCount++
      } catch (e: any) {
        errors.push(`${inv.varsymbol || `#${inv.id}`}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('invoice.bulk_send_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('invoice.bulk_send_success', { n: okCount }))
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

async function exportCsv() {
  try {
    const r = await invoicesApi.exportCsv({
      q: search.value || undefined,
      status: statusFilter.value || undefined,
      type: typeFilter.value || undefined,
      year: dateFrom.value || dateTo.value ? undefined : (yearFilter.value === '' ? undefined : Number(yearFilter.value)),
      month: dateFrom.value || dateTo.value || yearFilter.value === '' || monthFilter.value === '' ? undefined : Number(monthFilter.value),
      date_from: dateFrom.value || undefined,
      date_to:   dateTo.value || undefined,
      currency:  currencyFilter.value || undefined,
    })
    const url = URL.createObjectURL(r.data as unknown as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `invoices-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.csv_export_failed'))
  }
}

function mergeGroups(existing: MonthGroup[], incoming: MonthGroup[]): MonthGroup[] {
  const byMonth = new Map<string, MonthGroup>()
  for (const g of existing) byMonth.set(g.month, g)
  for (const g of incoming) {
    const cur = byMonth.get(g.month)
    if (!cur) {
      byMonth.set(g.month, g)
      continue
    }
    cur.invoices.push(...g.invoices)
    cur.count += g.count
    // Merge totals_per_currency
    for (const t of g.totals_per_currency) {
      const found = cur.totals_per_currency.find(x => x.currency === t.currency)
      if (found) {
        found.without_vat = Math.round((found.without_vat + t.without_vat) * 100) / 100
        found.vat         = Math.round((found.vat         + t.vat)         * 100) / 100
        found.with_vat    = Math.round((found.with_vat    + t.with_vat)    * 100) / 100
      } else {
        cur.totals_per_currency.push({ ...t })
      }
    }
  }
  return Array.from(byMonth.values()).sort((a, b) => b.month.localeCompare(a.month))
}

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const result = await invoicesApi.listGrouped({
      q: search.value || undefined,
      status: statusFilter.value || undefined,
      type: typeFilter.value || undefined,
      client_id: clientFilter.value === '' ? undefined : Number(clientFilter.value),
      year: dateFrom.value || dateTo.value ? undefined : (yearFilter.value === '' ? undefined : Number(yearFilter.value)),
      month: dateFrom.value || dateTo.value || yearFilter.value === '' || monthFilter.value === '' ? undefined : Number(monthFilter.value),
      date_from: dateFrom.value || undefined,
      date_to:   dateTo.value || undefined,
      currency:  currencyFilter.value || undefined,
      overdue: overdueOnly.value || undefined,
      unpaid_only: unpaidOnly.value || undefined,
      page: page.value,
    })
    if (reset) {
      groups.value = result.data
    } else {
      groups.value = mergeGroups(groups.value, result.data)
    }
    total.value = result.meta.total
    pages.value = result.meta.pages ?? 1
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

// Sync filtrů s URL query (stejný pattern jako PurchaseInvoiceList) — detekuje menu
// link click přes route.query change z !empty na empty → reset.
const DEFAULT_YEAR = new Date().getFullYear()

onMounted(async () => {
  loadFiltersFromQuery(route.query)
  // Načti seznam klientů + měn pro select (paralelně s prvním load)
  clientsApi.list({ archived: false, per_page: 200, role: 'customers' }).then(r => { clients.value = r.data }).catch(() => {})
  codebooksApi.currencies().then(r => {
    const seen = new Set<string>()
    currencies.value = r.filter(c => c.is_active && !seen.has(c.code) && seen.add(c.code))
  }).catch(() => {})
  await load(true)
})

function loadFiltersFromQuery(q: typeof route.query) {
  statusFilter.value = typeof q.status === 'string' ? q.status : ''
  typeFilter.value   = typeof q.type === 'string' ? q.type : ''
  clientFilter.value = typeof q.client_id === 'string' && q.client_id !== '' ? Number(q.client_id) : ''
  overdueOnly.value  = q.overdue === '1' || q.overdue === 'true'
  unpaidOnly.value   = q.unpaid === '1' || q.unpaid === 'true'
  yearFilter.value   = typeof q.year === 'string' && q.year !== ''
    ? (q.year === 'all' ? '' : Number(q.year))
    : ((overdueOnly.value || unpaidOnly.value) ? '' : DEFAULT_YEAR)
  monthFilter.value  = typeof q.month === 'string' && q.month !== '' ? Number(q.month) : ''
  dateFrom.value     = typeof q.from === 'string' ? q.from : ''
  dateTo.value       = typeof q.to === 'string' ? q.to : ''
  currencyFilter.value = typeof q.currency === 'string' ? q.currency : ''
  search.value       = typeof q.q === 'string' ? q.q : ''
}

let suppressUrlSync = false
function syncFiltersToUrl() {
  if (suppressUrlSync) return
  const q: Record<string, string> = {}
  if (statusFilter.value) q.status = statusFilter.value
  if (typeFilter.value) q.type = typeFilter.value
  if (clientFilter.value !== '') q.client_id = String(clientFilter.value)
  if (yearFilter.value === '') q.year = 'all'
  else if (yearFilter.value !== DEFAULT_YEAR) q.year = String(yearFilter.value)
  if (monthFilter.value !== '') q.month = String(monthFilter.value)
  if (dateFrom.value) q.from = dateFrom.value
  if (dateTo.value) q.to = dateTo.value
  if (currencyFilter.value) q.currency = currencyFilter.value
  if (overdueOnly.value) q.overdue = '1'
  if (unpaidOnly.value) q.unpaid = '1'
  if (search.value) q.q = search.value
  router.replace({ query: q })
}

watch([statusFilter, typeFilter, clientFilter, yearFilter, monthFilter, dateFrom, dateTo,
       overdueOnly, unpaidOnly, currencyFilter], () => {
  syncFiltersToUrl()
  load(true)
})
// Když se vyčistí rok (vše/range), automaticky zrušit i měsíční filtr.
watch(yearFilter, (y) => { if (y === '') monthFilter.value = '' })
watch([dateFrom, dateTo], ([f, to]) => { if (f || to) monthFilter.value = '' })
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => { syncFiltersToUrl(); load(true) }, 300)
})

// Reset filtrů při menu link click (route.query je prázdná).
watch(() => route.query, (newQ) => {
  if (Object.keys(newQ).length === 0) {
    suppressUrlSync = true
    statusFilter.value = ''
    typeFilter.value = ''
    clientFilter.value = ''
    yearFilter.value = DEFAULT_YEAR
    monthFilter.value = ''
    dateFrom.value = ''
    dateTo.value = ''
    overdueOnly.value = false
    unpaidOnly.value = false
    currencyFilter.value = ''
    search.value = ''
    setTimeout(() => { suppressUrlSync = false }, 0)
  }
})

const loadedCount = computed(() => groups.value.reduce((s, g) => s + g.count, 0))

function openInvoice(inv: InvoiceListItem) {
  router.push(`/invoices/${inv.id}`)
}

// Work Report modal: otevíráno z buttonu "Výkaz" v sloupci Stav.
const wrModalOpen = ref(false)
const wrModalInvoiceId = ref(0)
function openWorkReport(id: number) {
  wrModalInvoiceId.value = id
  wrModalOpen.value = true
}

// Year dropdown — distinct roky z `invoices` aktuálního supplier (issue #33).
// Composable doplňuje aktuální + minulý rok + aktuálně zvolený rok z URL.
const yearOptions = useYearOptions('invoices', yearFilter)

// `tm()` vrací raw translation message (pole), kdežto `t()` na poli vrátí stringified verzi.
// `rt()` zformátuje jednotlivé položky pole (pro případnou interpolaci).
const monthOptions = computed(() => (tm('common.months_short') as unknown as string[]).map(m => rt(m)))
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('invoice.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('invoice.subtitle_grouping') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button v-if="(issuableSelected.length > 0) && auth.canWrite"
          @click="bulkIssue"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_issue', { n: issuableSelected.length }) }}
        </button>
        <button v-if="(selectedIds.length > 0) && auth.canWrite"
          @click="bulkReissue"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-primary-500 text-primary-700 hover:bg-primary-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2m-6 12h8a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-8a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z"/></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_reissue', { n: selectedIds.length }) }}
        </button>
        <button v-if="(markPayableSelected.length > 0) && auth.canWrite"
          @click="bulkMarkPaid"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-success-500 text-success-600 hover:bg-success-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_mark_paid', { n: markPayableSelected.length }) }}
        </button>
        <button v-if="(sendableSelected.length > 0) && auth.canWrite"
          @click="bulkSend"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z"/></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_send', { n: sendableSelected.length }) }}
        </button>
        <button v-if="(reminderSelected.length > 0) && auth.canWrite"
          @click="bulkSendReminders"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-warning-500 hover:bg-warning-600 disabled:opacity-50 text-white text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_reminder', { n: reminderSelected.length }) }}
        </button>
        <RouterLink
          v-if="auth.canWrite"
          to="/invoices/new"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
        >
          {{ t('invoice.new') }}
        </RouterLink>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3">
      <div class="flex flex-wrap items-center gap-2">
        <input
          v-model="search"
          type="search"
          :placeholder="t('invoice.search_placeholder')"
          class="flex-1 min-w-48 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        />
        <select v-model="statusFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('invoice.all_statuses') }}</option>
          <option value="draft">{{ t('status.draft') }}</option>
          <option value="issued">{{ t('status.issued') }}</option>
          <option value="sent">{{ t('status.sent') }}</option>
          <option value="reminded">{{ t('status.reminded') }}</option>
          <option value="paid">{{ t('status.paid') }}</option>
          <option value="cancelled">{{ t('status.cancelled') }}</option>
        </select>
        <select v-model="typeFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('invoice.all_types') }}</option>
          <option value="invoice">{{ t('type.invoice') }}</option>
          <option value="proforma">{{ t('type.proforma') }}</option>
          <option value="credit_note">{{ t('type.credit_note') }}</option>
        </select>
        <div class="min-w-48 flex-1 max-w-xs">
          <SearchableSelect
            :model-value="clientFilter === '' ? null : clientFilter"
            @update:model-value="(v) => clientFilter = v === null ? '' : v"
            :options="clients.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))"
            :placeholder="t('project.all_clients')"
          />
        </div>
        <select v-model="currencyFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('invoice.all_currencies') }}</option>
          <option v-for="c in currencies" :key="c.id" :value="c.code">{{ c.code }}</option>
        </select>
        <select v-model="yearFilter" :disabled="!!dateFrom || !!dateTo"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50">
          <option value="">{{ t('invoice.all_years') }}</option>
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <select v-model="monthFilter" :disabled="!!dateFrom || !!dateTo || yearFilter === ''"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50"
          :title="t('invoice.month_filter')">
          <option :value="''">{{ t('invoice.all_months') }}</option>
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <input v-model="dateFrom" type="date" placeholder="Od"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" title="Datum od" />
        <input v-model="dateTo" type="date" placeholder="Do"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" title="Datum do" />
        <button v-if="dateFrom || dateTo" @click="dateFrom = ''; dateTo = ''"
          class="cursor-pointer h-9 px-2 text-xs text-neutral-500 hover:text-neutral-700">{{ t('invoice.clear_date_filter') }}</button>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="overdueOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('invoice.overdue_only') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="unpaidOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('invoice.unpaid_only') }}
        </label>
        <button @click="exportCsv"
          class="cursor-pointer ml-auto h-9 px-3 border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md text-sm inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 0 1 2-2h11l5 5v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
          {{ t('invoice.csv_export') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <TableSkeleton :rows="8" :cols="7" />
    </div>

    <div v-else-if="!groups.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState :title="t('invoice.no_data')" :cta="t('invoice.issue_first')" to="/invoices/new" />
    </div>

    <div v-else>
      <div class="text-xs text-neutral-500 mb-3 flex items-center justify-between">
        <span>{{ t('invoice.summary_count', { n: total, m: groups.length }) }}</span>
        <span v-if="total > loadedCount">{{ t('common.loaded_count', { loaded: loadedCount, total }) }}</span>
      </div>

      <!-- Skupiny po měsících -->
      <section v-for="g in groups" :key="g.month" class="mb-5">
        <header class="sticky top-16 z-[5] flex items-center justify-between bg-neutral-50/95 backdrop-blur border border-neutral-200 rounded-t-lg px-4 py-2.5 mb-0">
          <div class="flex items-center gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ formatMonth(g.month) }}</h2>
            <span class="text-xs text-neutral-500">{{ g.count }} {{ g.count === 1 ? t('invoice.doc_1') : (g.count < 5 ? t('invoice.doc_2_4') : t('invoice.doc_5plus')) }}</span>
          </div>
          <div class="flex items-center gap-3 text-xs">
            <span v-for="t in g.totals_per_currency" :key="t.currency" class="font-mono">
              <span class="text-neutral-500">{{ t.currency }}:</span>
              <span class="font-semibold text-neutral-900 ml-1">{{ formatMoney(t.with_vat, t.currency) }}</span>
            </span>
          </div>
        </header>

        <!-- Desktop: tabulka -->
        <div class="hidden md:block bg-surface border border-t-0 border-neutral-200 rounded-b-lg overflow-hidden">
          <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
              <tr>
                <th class="px-2 py-2 w-10"></th>
                <th class="text-left px-4 py-2 font-medium w-32">Var. symbol</th>
                <th class="text-left px-4 py-2 font-medium">{{ t('invoice.client_project') }}</th>
                <th class="text-center px-4 py-2 font-medium">Typ</th>
                <th class="text-center px-4 py-2 font-medium">DUZP / Vystaveno</th>
                <th class="text-center px-4 py-2 font-medium">Splatnost</th>
                <th class="text-right px-4 py-2 font-medium">{{ t('invoice.amount_to_pay') }}</th>
                <th class="text-center px-4 py-2 font-medium">Stav</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr
                v-for="inv in g.invoices"
                :key="inv.id"
                @click="openInvoice(inv)"
                class="cursor-pointer hover:bg-neutral-50 transition"
                :class="invoiceRowClass(inv.due_date, inv.status)"
              >
                <td class="px-2 py-2.5 text-center" @click.stop>
                  <input
                    type="checkbox"
                    :checked="selectedIds.includes(inv.id)"
                    @change="toggleSelected(inv.id)"
                    class="w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                  />
                </td>
                <td class="px-4 py-2.5 font-mono text-xs">
                  <span v-if="inv.varsymbol">{{ inv.varsymbol }}</span>
                  <span v-else class="text-neutral-400">{{ t('invoice.draft_id_short', { id: inv.id }) }}</span>
                </td>
                <td class="px-4 py-2.5">
                  <div class="font-medium text-neutral-900">{{ inv.client_company_name }}</div>
                  <div v-if="inv.project_name" class="text-xs text-neutral-500 truncate max-w-md">{{ inv.project_name }}</div>
                </td>
                <td class="px-4 py-2.5 text-center text-xs text-neutral-600">{{ typeLabel(inv.invoice_type) }}</td>
                <td class="px-4 py-2.5 text-center text-xs text-neutral-600">
                  {{ formatDate(inv.tax_date || inv.issue_date) }}
                </td>
                <td class="px-4 py-2.5 text-center text-xs">
                  <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : 'text-neutral-600'">
                    {{ formatDate(inv.due_date) }}
                  </span>
                </td>
                <td class="px-4 py-2.5 text-right font-mono">
                  {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                </td>
                <td class="px-4 py-2.5 text-center" @click.stop>
                  <!-- Pro koncepty s workflow projektem (nebo s již vytvořeným výkazem)
                       zobraz tlačítko "Výkaz" místo "KONCEPT" badge — rychlý přístup k modalu. -->
                  <button v-if="inv.status === 'draft' && (inv.project_requires_approval || inv.has_work_report || inv.recurring_template_id)"
                    @click="openWorkReport(inv.id)"
                    class="cursor-pointer text-xs px-2 py-0.5 rounded border border-primary-500/40 text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1"
                    :title="t('invoice.wr_btn')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6m3 6v-4m3 4v-2"/></svg>
                    {{ t('invoice.wr_btn') }}
                  </button>
                  <span v-else class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                    {{ statusLabel(inv.status) }}
                  </span>
                  <span v-if="inv.sent_at" class="ml-1 text-xs px-1 py-0.5 rounded bg-success-50 text-success-600"
                    :title="t('invoice.sent_at', { date: formatDate(inv.sent_at) })">✉</span>
                  <span v-if="inv.reminder_count > 0" class="ml-1 text-xs px-1 py-0.5 rounded bg-warning-50 text-warning-600 font-semibold"
                    :title="t('invoice.reminder_at', { count: inv.reminder_count, date: formatDate(inv.last_reminder_at) })">⚠ {{ inv.reminder_count }}</span>
                </td>
              </tr>
            </tbody>
          </table>
          </div>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden bg-surface border border-t-0 border-neutral-200 rounded-b-lg divide-y divide-neutral-100 overflow-hidden">
          <div
            v-for="inv in g.invoices"
            :key="`m-${inv.id}`"
            @click="openInvoice(inv)"
            class="cursor-pointer hover:bg-neutral-50 transition px-3 py-3"
            :class="invoiceRowClass(inv.due_date, inv.status)"
          >
            <div class="flex items-start gap-3">
              <input
                type="checkbox"
                :checked="selectedIds.includes(inv.id)"
                @change="toggleSelected(inv.id)"
                @click.stop
                class="mt-0.5 w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
              />
              <div class="flex-1 min-w-0">
                <div class="flex items-baseline justify-between gap-2">
                  <div class="font-medium text-neutral-900 truncate">{{ inv.client_company_name }}</div>
                  <div class="font-mono text-sm font-semibold whitespace-nowrap">
                    {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                  </div>
                </div>
                <div class="flex items-baseline justify-between gap-2 mt-0.5 text-xs text-neutral-500">
                  <div class="truncate">
                    <span class="font-mono">
                      <span v-if="inv.varsymbol">{{ inv.varsymbol }}</span>
                      <span v-else class="text-neutral-400">{{ t('invoice.draft_id_short', { id: inv.id }) }}</span>
                    </span>
                    <span class="text-neutral-400"> · </span>
                    <span>{{ typeLabel(inv.invoice_type) }}</span>
                    <span v-if="inv.project_name" class="text-neutral-400"> · </span>
                    <span v-if="inv.project_name" class="truncate">{{ inv.project_name }}</span>
                  </div>
                </div>
                <div class="flex items-center justify-between gap-2 mt-2">
                  <div class="text-xs text-neutral-600 whitespace-nowrap">
                    {{ formatDate(inv.tax_date || inv.issue_date) }}
                    <span class="text-neutral-400"> → </span>
                    <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''">
                      {{ formatDate(inv.due_date) }}
                    </span>
                  </div>
                  <div class="flex items-center gap-1 flex-wrap justify-end" @click.stop>
                    <span v-if="inv.sent_at" class="text-xs px-1 py-0.5 rounded bg-success-50 text-success-600"
                      :title="t('invoice.sent_at', { date: formatDate(inv.sent_at) })">✉</span>
                    <span v-if="inv.reminder_count > 0" class="text-xs px-1 py-0.5 rounded bg-warning-50 text-warning-600 font-semibold"
                      :title="t('invoice.reminder_at', { count: inv.reminder_count, date: formatDate(inv.last_reminder_at) })">⚠ {{ inv.reminder_count }}</span>
                    <!-- Pro koncepty s workflow projektem (nebo s již vytvořeným výkazem)
                         zobraz tlačítko "Výkaz" místo "KONCEPT" badge — stejně jako v desktop tabulce. -->
                    <button v-if="inv.status === 'draft' && (inv.project_requires_approval || inv.has_work_report || inv.recurring_template_id)"
                      @click="openWorkReport(inv.id)"
                      class="cursor-pointer text-xs px-2 py-0.5 rounded border border-primary-500/40 text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1"
                      :title="t('invoice.wr_btn')">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6m3 6v-4m3 4v-2"/></svg>
                      {{ t('invoice.wr_btn') }}
                    </button>
                    <span v-else class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                      {{ statusLabel(inv.status) }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div v-if="page < pages" class="text-center mt-3">
        <button @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-2 shadow-sm">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>

    <!-- Work report modal — otevřený z buttonu "Výkaz" v sloupci Stav. -->
    <WorkReportModal v-if="wrModalInvoiceId > 0"
      v-model="wrModalOpen"
      :invoice-id="wrModalInvoiceId"
      @saved="load(true)" />
  </div>
</template>

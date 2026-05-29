<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { RouterLink, useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import {
  purchaseInvoicesApi,
  type PurchaseMonthGroup,
  type PurchaseInvoiceListItem,
  type PurchaseInvoiceStatus,
  type PurchaseDocumentKind,
} from '@/api/purchaseInvoices'
import { formatMoney, formatDate, formatMonth } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { clientsApi, type Client } from '@/api/clients'

const { t, locale } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const toast = useToast()

useHotkey('ctrl+n', (e) => { e.preventDefault(); router.push('/purchase-invoices/new') })

const groups = ref<PurchaseMonthGroup[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')

// Filtry
const search = ref('')
const statusFilter = ref<PurchaseInvoiceStatus | ''>('')
const kindFilter = ref<PurchaseDocumentKind | ''>('')
const yearFilter = ref<number | ''>(new Date().getFullYear())
const monthFilter = ref<number | ''>('')
const dateFrom = ref('')
const dateTo = ref('')
const overdueOnly = ref(false)
const unpaidOnly = ref(false)
const needsReviewOnly = ref(false)
const currencyFilter = ref('')
const vendorFilter = ref<number | ''>('')
const vendors = ref<Client[]>([])

// Hromadné akce
const selectedIds = ref<number[]>([])
const bulkBusy = ref(false)

let searchTimeout: ReturnType<typeof setTimeout> | null = null

// Sync filtrů s URL query: filter se zapíše do ?param=value formy, abychom mohli
// detekovat menu click (= URL bez query → reset). Pro klika na menu link
// "Přijaté faktury" už když je na této stránce a má aktivní filtr (např. overdue=1)
// se URL změní zpět na čistou — watch fires reset všech ref.
const DEFAULT_YEAR = new Date().getFullYear()

onMounted(() => {
  loadFiltersFromQuery(route.query)
  load()
  // Dodavatelé pro filtr (jen dodavatelé — přijaté faktury chodí od nich).
  clientsApi.list({ archived: false, per_page: 200, role: 'vendors' })
    .then(r => { vendors.value = r.data }).catch(() => {})
})

function loadFiltersFromQuery(q: typeof route.query) {
  overdueOnly.value = q.overdue === '1' || q.overdue === 'true'
  unpaidOnly.value  = q.unpaid === '1' || q.unpaid === 'true'
  needsReviewOnly.value = q.needs_review === '1' || q.needs_review === 'true'
  statusFilter.value = typeof q.status === 'string' ? (q.status as PurchaseInvoiceStatus) : ''
  kindFilter.value   = typeof q.kind === 'string' ? (q.kind as PurchaseDocumentKind) : ''
  yearFilter.value   = typeof q.year === 'string' && q.year !== ''
    ? (q.year === 'all' ? '' : Number(q.year))
    : ((overdueOnly.value || unpaidOnly.value) ? '' : DEFAULT_YEAR)
  monthFilter.value  = typeof q.month === 'string' && q.month !== '' ? Number(q.month) : ''
  dateFrom.value     = typeof q.from === 'string' ? q.from : ''
  dateTo.value       = typeof q.to === 'string' ? q.to : ''
  currencyFilter.value = typeof q.currency === 'string' ? q.currency : ''
  vendorFilter.value = typeof q.vendor === 'string' && q.vendor !== '' ? Number(q.vendor) : ''
  search.value       = typeof q.q === 'string' ? q.q : ''
}

let suppressUrlSync = false
function syncFiltersToUrl() {
  if (suppressUrlSync) return
  const q: Record<string, string> = {}
  if (statusFilter.value) q.status = statusFilter.value
  if (kindFilter.value) q.kind = kindFilter.value
  // year=DEFAULT_YEAR je default a nepatří do URL; explicit "" (Vše) ano (jako 'all').
  if (yearFilter.value === '') q.year = 'all'
  else if (yearFilter.value !== DEFAULT_YEAR) q.year = String(yearFilter.value)
  if (monthFilter.value !== '') q.month = String(monthFilter.value)
  if (dateFrom.value) q.from = dateFrom.value
  if (dateTo.value) q.to = dateTo.value
  if (currencyFilter.value) q.currency = currencyFilter.value
  if (vendorFilter.value !== '') q.vendor = String(vendorFilter.value)
  if (overdueOnly.value) q.overdue = '1'
  if (unpaidOnly.value) q.unpaid = '1'
  if (needsReviewOnly.value) q.needs_review = '1'
  if (search.value) q.q = search.value
  router.replace({ query: q })
}

watch([statusFilter, kindFilter, yearFilter, monthFilter, dateFrom, dateTo,
       overdueOnly, unpaidOnly, needsReviewOnly, currencyFilter, vendorFilter], () => {
  syncFiltersToUrl()
  load()
})
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => { syncFiltersToUrl(); load() }, 300)
})

// Reset filtrů při menu link click (= route.query je prázdná).
watch(() => route.query, (newQ) => {
  if (Object.keys(newQ).length === 0) {
    // Reset bez triggering URL sync zpět (suppressUrlSync) — refs se nastaví, watch fires,
    // ale URL update se přeskočí. Pak ručně syncneme (no-op pro prázdné).
    suppressUrlSync = true
    statusFilter.value = ''
    kindFilter.value = ''
    yearFilter.value = DEFAULT_YEAR
    monthFilter.value = ''
    dateFrom.value = ''
    dateTo.value = ''
    overdueOnly.value = false
    unpaidOnly.value = false
    needsReviewOnly.value = false
    currencyFilter.value = ''
    vendorFilter.value = ''
    search.value = ''
    // Uvolnit po flush (watch effects)
    setTimeout(() => { suppressUrlSync = false }, 0)
  }
})

function mergeGroups(existing: PurchaseMonthGroup[], incoming: PurchaseMonthGroup[]): PurchaseMonthGroup[] {
  const byMonth = new Map<string, PurchaseMonthGroup>()
  for (const g of existing) byMonth.set(g.month, { ...g, invoices: [...g.invoices], totals_per_currency: [...g.totals_per_currency] })
  for (const g of incoming) {
    const cur = byMonth.get(g.month)
    if (!cur) {
      byMonth.set(g.month, { ...g, invoices: [...g.invoices], totals_per_currency: [...g.totals_per_currency] })
      continue
    }
    const seenIds = new Set(cur.invoices.map(i => i.id))
    for (const inv of g.invoices) if (!seenIds.has(inv.id)) cur.invoices.push(inv)
    cur.count = cur.invoices.length
    for (const t of g.totals_per_currency) {
      const found = cur.totals_per_currency.find(x => x.currency === t.currency)
      if (found) {
        found.without_vat = (found.without_vat ?? 0) + (t.without_vat ?? 0)
        found.vat = (found.vat ?? 0) + (t.vat ?? 0)
        found.with_vat = (found.with_vat ?? 0) + (t.with_vat ?? 0)
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
    selectedIds.value = []
  } else {
    loadingMore.value = true
    page.value++
  }
  error.value = ''
  try {
    const res = await purchaseInvoicesApi.listGrouped({
      status:        statusFilter.value || undefined,
      document_kind: kindFilter.value   || undefined,
      year:          yearFilter.value   || undefined,
      month:         monthFilter.value  || undefined,
      date_from:     dateFrom.value     || undefined,
      date_to:       dateTo.value       || undefined,
      currency:      currencyFilter.value || undefined,
      vendor_id:     vendorFilter.value   || undefined,
      unpaid_only:   unpaidOnly.value   || undefined,
      overdue:       overdueOnly.value  || undefined,
      needs_review:  needsReviewOnly.value || undefined,
      q:             search.value       || undefined,
      page: page.value,
    })
    if (reset) {
      groups.value = res.data
    } else {
      groups.value = mergeGroups(groups.value, res.data)
    }
    total.value = res.meta.total
    pages.value = res.meta.pages ?? 1
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function openInvoice(inv: PurchaseInvoiceListItem) {
  router.push(`/purchase-invoices/${inv.id}`)
}

// Year picker — distinct roky z `purchase_invoices` (issue #33).
const yearOptions = useYearOptions('purchase_invoices', yearFilter)
const monthOptions = computed(() => {
  const locStr = locale.value === 'en' ? 'en-US' : 'cs-CZ'
  return Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString(locStr, { month: 'long' })
  )
})

const loadedCount = computed(() =>
  groups.value.reduce((sum, g) => sum + g.invoices.length, 0)
)

const isOverdue = (dueDate: string, status: PurchaseInvoiceStatus): boolean => {
  if (status !== 'received' && status !== 'booked') return false
  return new Date(dueDate) < new Date(new Date().toISOString().slice(0, 10))
}

// Status badge ve stejných tokenech jako Detail (sjednoceno s vystavenou)
const statusBadgeClass = (s: PurchaseInvoiceStatus): string => ({
  draft:     'bg-neutral-100 text-neutral-600 border border-neutral-200',
  received:  'bg-primary-50 text-primary-700 border border-primary-500/40',
  booked:    'bg-warning-50 text-warning-600 border border-warning-500/40',
  paid:      'bg-success-50 text-success-600 border border-success-500/40',
  cancelled: 'bg-danger-50 text-danger-500 border border-danger-500/40',
}[s])

// Row class — soft red background pro overdue, soft gray pro cancelled,
// soft yellow pro faktury s AI extraction_warning (vyžadují kontrolu)
const rowClass = (inv: PurchaseInvoiceListItem): string => {
  if (inv.status === 'cancelled') return 'opacity-60'
  if (isOverdue(inv.due_date, inv.status)) return 'bg-danger-50/30'
  if (inv.extraction_warning) return 'bg-warning-50/50'
  return ''
}

// ── Hromadné akce ─────────────────────────────────────────────────────
function toggleSelected(id: number) {
  const idx = selectedIds.value.indexOf(id)
  if (idx >= 0) selectedIds.value.splice(idx, 1)
  else selectedIds.value.push(id)
}

function allRowIds(): number[] {
  return groups.value.flatMap(g => g.invoices.map(i => i.id))
}

const allSelected = computed(() => {
  const ids = allRowIds()
  return ids.length > 0 && ids.every(id => selectedIds.value.includes(id))
})

function toggleAll() {
  if (allSelected.value) {
    selectedIds.value = []
  } else {
    selectedIds.value = allRowIds()
  }
}

// Helpers per row
function statusOf(id: number): PurchaseInvoiceStatus | null {
  for (const g of groups.value) {
    const f = g.invoices.find(i => i.id === id)
    if (f) return f.status
  }
  return null
}

const draftsSelected     = computed(() => selectedIds.value.filter(id => statusOf(id) === 'draft'))
const markReceivedSelected = computed(() => selectedIds.value.filter(id => statusOf(id) === 'draft'))
const markPayableSelected = computed(() => selectedIds.value.filter(id => {
  const s = statusOf(id); return s === 'received' || s === 'booked'
}))
const markBookableSelected = computed(() => selectedIds.value.filter(id => statusOf(id) === 'received'))
const cancellableSelected = computed(() => selectedIds.value.filter(id => {
  const s = statusOf(id); return s && s !== 'cancelled'
}))

async function bulkTransition(target: PurchaseInvoiceStatus, ids: number[]) {
  if (ids.length === 0 || bulkBusy.value) return
  if (target === 'cancelled' && !confirm(t('purchase_invoice.bulk.confirm_cancel', { n: ids.length }))) return
  bulkBusy.value = true
  let ok = 0, fail = 0
  for (const id of ids) {
    try { await purchaseInvoicesApi.transition(id, target); ok++ } catch { fail++ }
  }
  bulkBusy.value = false
  if (fail === 0) toast.success(t('purchase_invoice.bulk.success', { n: ok }))
  else            toast.error(t('purchase_invoice.bulk.partial', { ok, fail }))
  await load()
}

async function bulkDelete() {
  const ids = draftsSelected.value
  if (ids.length === 0 || bulkBusy.value) return
  if (!confirm(t('purchase_invoice.bulk.confirm_delete', { n: ids.length }))) return
  bulkBusy.value = true
  let ok = 0, fail = 0
  for (const id of ids) {
    try { await purchaseInvoicesApi.delete(id); ok++ } catch { fail++ }
  }
  bulkBusy.value = false
  if (fail === 0) toast.success(t('purchase_invoice.bulk.delete_success', { n: ok }))
  else            toast.error(t('purchase_invoice.bulk.partial', { ok, fail }))
  await load()
}
</script>

<template>
  <div>
    <!-- ═══ Topbar: title + bulk actions + new ═══ -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('purchase_invoice.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('purchase_invoice.subtitle') }}</p>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <!-- Bulk actions — viditelné jen pokud něco vybráno -->
        <button v-if="(markReceivedSelected.length > 0) && auth.canWrite"
          @click="bulkTransition('received', markReceivedSelected)"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-primary-500 text-primary-700 hover:bg-primary-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-8l-4-4m0 0l-4 4m4-4v12"/></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.mark_received', { n: markReceivedSelected.length }) }}
        </button>
        <button v-if="(markBookableSelected.length > 0) && auth.canWrite"
          @click="bulkTransition('booked', markBookableSelected)"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-warning-500 text-warning-600 hover:bg-warning-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.mark_booked', { n: markBookableSelected.length }) }}
        </button>
        <button v-if="(markPayableSelected.length > 0) && auth.canWrite"
          @click="bulkTransition('paid', markPayableSelected)"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-success-500 text-success-600 hover:bg-success-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.mark_paid', { n: markPayableSelected.length }) }}
        </button>
        <button v-if="(cancellableSelected.length > 0) && auth.canWrite"
          @click="bulkTransition('cancelled', cancellableSelected)"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-danger-500/50 text-danger-500 hover:bg-danger-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.cancel', { n: cancellableSelected.length }) }}
        </button>
        <button v-if="(draftsSelected.length > 0) && auth.canWrite"
          @click="bulkDelete"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-danger-500/50 text-danger-500 hover:bg-danger-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.delete', { n: draftsSelected.length }) }}
        </button>

        <RouterLink
          v-if="auth.canWrite"
          to="/purchase-invoices/new"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
        >
          {{ t('purchase_invoice.new') }}
        </RouterLink>
      </div>
    </div>

    <!-- ═══ Filtry v boxu ═══ -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3">
      <div class="flex flex-wrap items-center gap-2">
        <input
          v-model="search"
          type="search"
          :placeholder="t('purchase_invoice.filters.search_placeholder')"
          class="flex-1 min-w-48 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        />
        <select v-model="statusFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('purchase_invoice.filters.all_statuses') }}</option>
          <option value="draft">{{ t('purchase_invoice.status.draft') }}</option>
          <option value="received">{{ t('purchase_invoice.status.received') }}</option>
          <option value="booked">{{ t('purchase_invoice.status.booked') }}</option>
          <option value="paid">{{ t('purchase_invoice.status.paid') }}</option>
          <option value="cancelled">{{ t('purchase_invoice.status.cancelled') }}</option>
        </select>
        <select v-model="kindFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('purchase_invoice.filters.all_kinds') }}</option>
          <option value="invoice">{{ t('purchase_invoice.document_kind.invoice') }}</option>
          <option value="receipt">{{ t('purchase_invoice.document_kind.receipt') }}</option>
          <option value="credit_note">{{ t('purchase_invoice.document_kind.credit_note') }}</option>
          <option value="advance">{{ t('purchase_invoice.document_kind.advance') }}</option>
        </select>
        <div class="min-w-48 flex-1 max-w-xs">
          <SearchableSelect
            :model-value="vendorFilter === '' ? null : vendorFilter"
            @update:model-value="(v) => vendorFilter = v === null ? '' : Number(v)"
            :options="vendors.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))"
            :placeholder="t('purchase_invoice.filters.all_vendors')"
          />
        </div>
        <select v-model="yearFilter" :disabled="!!dateFrom || !!dateTo"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50">
          <option value="">{{ t('invoice.all_years') }}</option>
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <select v-model="monthFilter" :disabled="!!dateFrom || !!dateTo || yearFilter === ''"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50">
          <option :value="''">{{ t('invoice.all_months') }}</option>
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <input v-model="dateFrom" type="date" :placeholder="t('common.from')"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" :title="t('common.from')" />
        <input v-model="dateTo" type="date" :placeholder="t('common.to')"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" :title="t('common.to')" />
        <button v-if="dateFrom || dateTo" @click="dateFrom = ''; dateTo = ''"
          class="cursor-pointer h-9 px-2 text-xs text-neutral-500 hover:text-neutral-700">{{ t('invoice.clear_date_filter') }}</button>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="overdueOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('purchase_invoice.filters.overdue') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="unpaidOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('purchase_invoice.filters.unpaid_only') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-warning-700 px-2">
          <input v-model="needsReviewOnly" type="checkbox" class="rounded border-neutral-300 text-warning-600" />
          {{ t('purchase_invoice.filters.needs_review') }}
        </label>
      </div>
    </div>

    <!-- ═══ Loading / Error / Empty / Data ═══ -->
    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <TableSkeleton :rows="6" :cols="7" />
    </div>

    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-else-if="!groups.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState
        :title="search || statusFilter || kindFilter ? t('purchase_invoice.empty_filtered') : t('purchase_invoice.empty')"
        :cta="t('purchase_invoice.new')"
        to="/purchase-invoices/new" />
    </div>

    <div v-else>
      <div class="text-xs text-neutral-500 mb-3 flex items-center justify-between">
        <span>{{ t('purchase_invoice.summary_count', { count: total }) }}</span>
        <span v-if="loadedCount < total">{{ t('common.loaded_count', { loaded: loadedCount, total }) }}</span>
      </div>

      <!-- ═══ Skupiny po měsících ═══ -->
      <section v-for="g in groups" :key="g.month" class="mb-5">
        <header class="sticky top-16 z-[5] flex items-center justify-between bg-neutral-50/95 backdrop-blur border border-neutral-200 rounded-t-lg px-4 py-2.5 mb-0">
          <div class="flex items-center gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ formatMonth(g.month) }}</h2>
            <span class="text-xs text-neutral-500">{{ g.count }}</span>
          </div>
          <div class="flex items-center gap-3 text-xs">
            <span v-for="tc in g.totals_per_currency" :key="tc.currency" class="font-mono">
              <span class="text-neutral-500">{{ tc.currency }}:</span>
              <span class="font-semibold text-neutral-900 ml-1">{{ formatMoney(tc.with_vat, tc.currency) }}</span>
            </span>
          </div>
        </header>

        <!-- Desktop: tabulka -->
        <div class="hidden md:block bg-surface border border-t-0 border-neutral-200 rounded-b-lg overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm table-sticky-first">
              <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
                <tr>
                  <th class="px-2 py-2 w-10 text-center">
                    <input
                      type="checkbox"
                      :checked="allSelected"
                      @change="toggleAll"
                      :title="t('common.select_all')"
                      class="w-4 h-4 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                    />
                  </th>
                  <th class="text-left px-4 py-2 font-medium w-32">{{ t('purchase_invoice.fields.varsymbol') }}</th>
                  <th class="text-left px-4 py-2 font-medium">{{ t('purchase_invoice.fields.vendor') }}</th>
                  <th class="text-left px-4 py-2 font-medium w-32">{{ t('purchase_invoice.fields.vendor_invoice_number') }}</th>
                  <th class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.fields.document_kind') }}</th>
                  <th class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.fields.tax_date') }}</th>
                  <th class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.fields.due_date') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('purchase_invoice.totals.to_pay') }}</th>
                  <th class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.status.draft') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr
                  v-for="inv in g.invoices"
                  :key="inv.id"
                  @click="openInvoice(inv)"
                  class="cursor-pointer hover:bg-neutral-50 transition"
                  :class="rowClass(inv)"
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
                    <span v-else class="text-neutral-400">#{{ inv.id }}</span>
                  </td>
                  <td class="px-4 py-2.5">
                    <div class="font-medium text-neutral-900">{{ inv.vendor_company_name }}</div>
                    <div v-if="inv.vendor_ic" class="text-xs text-neutral-500 font-mono">{{ t('common.ic') }} {{ inv.vendor_ic }}</div>
                  </td>
                  <td class="px-4 py-2.5 font-mono text-xs text-neutral-600">
                    <div class="flex items-center gap-1.5">
                      <span
                        v-if="inv.extraction_warning"
                        :title="t('purchase_invoice.extraction.needs_review_tooltip')"
                        class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-warning-500/20 text-warning-600 flex-shrink-0"
                      >
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                        </svg>
                      </span>
                      <span>{{ inv.vendor_invoice_number }}</span>
                    </div>
                  </td>
                  <td class="px-4 py-2.5 text-center text-xs text-neutral-600">{{ t(`purchase_invoice.document_kind.${inv.document_kind}`) }}</td>
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
                  <td class="px-4 py-2.5 text-center">
                    <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                      {{ t(`purchase_invoice.status.${inv.status}`) }}
                    </span>
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
            :class="rowClass(inv)"
          >
            <div class="flex items-start gap-3">
              <input
                type="checkbox"
                :checked="selectedIds.includes(inv.id)"
                @change="toggleSelected(inv.id)"
                @click.stop
                class="w-5 h-5 mt-0.5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
              />
              <div class="flex-1 min-w-0">
                <div class="flex items-baseline justify-between gap-2">
                  <div class="font-medium text-neutral-900 truncate flex items-center gap-1.5">
                    <span
                      v-if="inv.extraction_warning"
                      :title="t('purchase_invoice.extraction.needs_review_tooltip')"
                      class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-warning-500/20 text-warning-600 flex-shrink-0"
                    >
                      <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                      </svg>
                    </span>
                    <span class="truncate">{{ inv.vendor_company_name }}</span>
                  </div>
                  <div class="font-mono text-sm whitespace-nowrap">
                    {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                  </div>
                </div>
                <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
                  <div class="font-mono truncate">
                    <span>{{ inv.varsymbol || '#' + inv.id }}</span>
                    <span class="text-neutral-400"> · </span>
                    <span>{{ inv.vendor_invoice_number }}</span>
                  </div>
                  <span class="text-xs px-1.5 py-0.5 rounded whitespace-nowrap" :class="statusBadgeClass(inv.status)">
                    {{ t(`purchase_invoice.status.${inv.status}`) }}
                  </span>
                </div>
                <div class="flex items-center justify-between gap-2 mt-1 text-xs text-neutral-500">
                  <span>{{ formatDate(inv.tax_date || inv.issue_date) }}</span>
                  <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''">
                    {{ t('purchase_invoice.fields.due_date') }}: {{ formatDate(inv.due_date) }}
                  </span>
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
  </div>
</template>

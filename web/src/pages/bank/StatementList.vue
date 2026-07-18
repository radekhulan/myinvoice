<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { bankApi, type BankStatement, type BankAccountOption, type ImportResult, type AmbiguousAccount } from '@/api/bank'
import type { AxiosError } from 'axios'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import FilterBar from '@/components/ui/FilterBar.vue'
import { formatAccountNumber } from '@/utils/bankAccount'

// embedded = vykresleno jako záložka „Bankovní výpisy" uvnitř BankPage.vue
// (hlavičku stránky dodává obálka, tady zůstávají jen akční tlačítka).
defineProps<{ embedded?: boolean }>()

const { t, tm, rt, locale } = useI18n()
const toast = useToast()
const authStore = useAuthStore()
const isAdmin = computed(() => authStore.user?.role === 'admin')

const router = useRouter()
const route = useRoute()
const statements = ref<BankStatement[]>([])
const loading = ref(false)

// Filtry rok / měsíc / účet — stejný design jako přehled faktur. Rok defaultně „vše"
// (výpisy se hromadí přes víc let, poslední rok by řadu z nich skryl).
const DEFAULT_YEAR = new Date().getFullYear()
const yearFilter = ref<number | ''>('')
const monthFilter = ref<number | ''>('')
const accountFilter = ref<string>('')
const bankCodeFilter = ref<string>('')
const years = ref<number[]>([])
const accounts = ref<BankAccountOption[]>([])
const accountFilterKey = computed({
  get: () => accountFilter.value ? `${accountFilter.value}|${bankCodeFilter.value}` : '',
  set: (value: string) => {
    const separator = value.lastIndexOf('|')
    accountFilter.value = separator >= 0 ? value.slice(0, separator) : value
    bankCodeFilter.value = separator >= 0 ? value.slice(separator + 1) : ''
  },
})

// `tm()` vrací raw pole zpráv, `rt()` zformátuje jednotlivé položky (interpolace).
const monthOptions = computed(() => (tm('common.months_short') as unknown as string[]).map(m => rt(m)))
// Dropdown roků: z dat doplněný o aktuální + aktuálně zvolený (kdyby v datech nebyl).
const yearOptions = computed(() => {
  const set = new Set<number>(years.value)
  set.add(DEFAULT_YEAR)
  if (typeof yearFilter.value === 'number') set.add(yearFilter.value)
  return [...set].sort((a, b) => b - a)
})
const activeFilterCount = computed(() => {
  let n = 0
  if (monthFilter.value !== '') n++
  if (accountFilter.value !== '') n++
  return n
})
function accountLabel(a: BankAccountOption): string {
  const num = formatAccountNumber(a.account_number, a.bank_code)
  return a.label ? `${num} — ${a.label}` : num
}
// „Filtr je aktivní" = zúžení oproti zobrazení všeho (rok ≠ vše / měsíc / účet).
const filtersActive = computed(() => yearFilter.value !== '' || monthFilter.value !== '' || accountFilter.value !== '')
function resetFilters() {
  yearFilter.value = ''
  monthFilter.value = ''
  accountFilter.value = ''
  bankCodeFilter.value = ''
}
const uploading = ref(false)
const scanning = ref(false)
// Tlačítko „Skenovat adresář" jen když je v cfg.php nastavený bank_import.scan_root.
const scanConfigured = ref(false)
const lastResult = ref<ImportResult | null>(null)
const error = ref('')

// #167: výpis u víceměnového účtu se sdíleným číslem účtu — server vrátí 409
// `ambiguous_account_currency` se seznamem měnových variant; necháme uživatele zvolit
// cílový účet a soubor nahrajeme znovu s account_id. Modal řešíme přes Promise, aby
// se sekvenční smyčka uploadu pozastavila do volby uživatele.
const ambiguityModal = ref<{ fileName: string; candidates: AmbiguousAccount[] } | null>(null)
const ambiguitySelected = ref<number | null>(null)
let ambiguityResolver: ((accountId: number | null) => void) | null = null

function ambiguousCandidates(e: unknown): AmbiguousAccount[] | null {
  const err = e as AxiosError<{ error?: { code?: string; candidates?: AmbiguousAccount[] } }>
  const data = err?.response?.data?.error
  if (data?.code === 'ambiguous_account_currency' && Array.isArray(data.candidates)) {
    return data.candidates
  }
  return null
}

function askForAccount(fileName: string, candidates: AmbiguousAccount[]): Promise<number | null> {
  ambiguitySelected.value = candidates[0]?.account_id ?? null
  ambiguityModal.value = { fileName, candidates }
  return new Promise(resolve => { ambiguityResolver = resolve })
}

function confirmAmbiguity() {
  const id = ambiguitySelected.value
  ambiguityModal.value = null
  ambiguityResolver?.(id)
  ambiguityResolver = null
}

function cancelAmbiguity() {
  ambiguityModal.value = null
  ambiguityResolver?.(null)
  ambiguityResolver = null
}

async function onScan() {
  scanning.value = true
  error.value = ''
  try {
    const r = await bankApi.scan()
    toast.success(t('bank.scan_done', { scanned: r.scanned, imported: r.imported, duplicate: r.duplicate, errors: r.errors }))
    await load()
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('bank.scan_failed')))
  } finally {
    scanning.value = false
  }
}
const page = ref(1)
const total = ref(0)
const perPage = ref(50)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const rangeFrom = computed(() => (total.value === 0 ? 0 : (page.value - 1) * perPage.value + 1))
const rangeTo = computed(() => Math.min(page.value * perPage.value, total.value))

async function load() {
  loading.value = true
  try {
    const r = await bankApi.list({
      page: page.value,
      year: yearFilter.value,
      month: monthFilter.value,
      account: accountFilter.value || undefined,
      bank_code: bankCodeFilter.value || undefined,
    })
    statements.value = r.items
    total.value = r.total
    perPage.value = r.limit
    years.value = r.years
    accounts.value = r.accounts
    scanConfigured.value = r.scan_configured
  } finally { loading.value = false }
}
function goToPage(p: number) {
  const np = Math.min(Math.max(1, p), totalPages.value)
  if (np !== page.value) { page.value = np; load() }
}

// Filtry ↔ URL query (stejný pattern jako přehledy faktur — reset na menu link click).
let suppressUrlSync = false
function loadFiltersFromQuery(q: typeof route.query) {
  yearFilter.value = typeof q.year === 'string' && q.year !== '' && q.year !== 'all'
    ? Number(q.year)
    : ''
  monthFilter.value = typeof q.month === 'string' && q.month !== '' ? Number(q.month) : ''
  accountFilter.value = typeof q.account === 'string' ? q.account : ''
  bankCodeFilter.value = typeof q.bank === 'string' ? q.bank : ''
}
function syncFiltersToUrl() {
  if (suppressUrlSync) return
  const q: Record<string, string> = {}
  if (yearFilter.value !== '') q.year = String(yearFilter.value)
  if (monthFilter.value !== '') q.month = String(monthFilter.value)
  if (accountFilter.value) q.account = accountFilter.value
  if (bankCodeFilter.value) q.bank = bankCodeFilter.value
  router.replace({ query: q })
}

watch([yearFilter, monthFilter, accountFilter, bankCodeFilter], () => {
  page.value = 1
  syncFiltersToUrl()
  load()
})
// Vyčištění roku (vše) zruší i měsíční filtr (měsíc dává smysl jen ve zvoleném roce).
watch(yearFilter, (y) => { if (y === '') monthFilter.value = '' })

// Reset filtrů při kliku na menu (route.query je prázdná).
watch(() => route.query, (newQ) => {
  if (Object.keys(newQ).length === 0) {
    suppressUrlSync = true
    yearFilter.value = ''
    monthFilter.value = ''
    accountFilter.value = ''
    bankCodeFilter.value = ''
    page.value = 1
    load()
    setTimeout(() => { suppressUrlSync = false }, 0)
  }
})

onMounted(() => {
  loadFiltersFromQuery(route.query)
  load()
})

// E-mailová avíza jsou měsíční agregát (statement_date = 1. den měsíce), proto
// u nich nedává smysl ukazovat konkrétní datum — zobrazíme název měsíce
// (sdílený `monthLabel` níže přijímá 'YYYY-MM').
function statementDateLabel(s: BankStatement): string {
  return s.source === 'email_notice' ? monthLabel((s.statement_date ?? '').slice(0, 7)) : formatDate(s.statement_date)
}

// Seskupení výpisů po měsících (YYYY-MM z statement_date), zachová pořadí ze
// serveru. Tabulka i karty se opticky rozdělí měsíčními hlavičkami.
function monthLabel(ym: string): string {
  if (!ym) return '—'
  const d = new Date(ym + '-01T00:00:00')
  if (isNaN(d.getTime())) return ym
  const s = d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long', year: 'numeric' })
  return s.charAt(0).toUpperCase() + s.slice(1)
}
const groupedStatements = computed(() => {
  const map = new Map<string, BankStatement[]>()
  for (const s of statements.value) {
    const ym = (s.statement_date ?? '').slice(0, 7)
    if (!map.has(ym)) map.set(ym, [])
    map.get(ym)!.push(s)
  }
  return [...map.entries()].map(([month, items]) => ({ month, label: monthLabel(month), items }))
})

async function onDelete(s: BankStatement, ev: MouseEvent) {
  ev.stopPropagation()
  if (!confirm(t('bank.delete_confirm', { name: s.file_name }))) return
  try {
    await bankApi.delete(s.id)
    toast.success(t('bank.delete_done'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('bank.delete_failed')))
  }
}

// Jeden vstup pro GPC/ABO i PDF — rozhoduje se PER SOUBOR podle přípony (uživatel
// může naráz vybrat mix obojího), backend endpointy zůstávají oddělené (GPC parser
// vs bank-specifický PDF parser — Creditas/ČSOB/KB/Raiffeisenbank, viz BankStatementPdfParserRegistry).
function uploadFnFor(file: File): (file: File, accountId?: number) => Promise<ImportResult> {
  return file.name.toLowerCase().endsWith('.pdf') ? bankApi.importPdf : bankApi.upload
}

async function onFileSelected(e: Event) {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  if (files.length === 0) return

  uploading.value = true
  error.value = ''
  lastResult.value = null

  // Backend přijímá jeden soubor per request — pro batch upload jen sekvenčně
  // iterujeme. Sekvenčně (ne paralelně) kvůli `bank_statements.file_hash` dedup:
  // pokud user nahrál stejný soubor 2× v jednom dropu, druhý vrátí
  // `duplicate=true` až po commitu prvního. Sumarizovaný report v toast / banneru.
  let okCount = 0
  let duplicateCount = 0
  let errorCount = 0
  const errors: string[] = []
  let lastNonDuplicate: ImportResult | null = null

  const results: ImportResult[] = []
  for (const file of files) {
    const uploadFn = uploadFnFor(file)
    try {
      results.push(await uploadFn(file))
    } catch (e) {
      // #167/#206: sdílené číslo účtu (napříč měnami nebo bankami) → nech uživatele zvolit cílový účet a zkus znovu.
      const candidates = ambiguousCandidates(e)
      if (candidates) {
        const accountId = await askForAccount(file.name, candidates)
        if (accountId === null) continue  // uživatel zrušil → soubor přeskočíme (ne chyba)
        try {
          results.push(await uploadFn(file, accountId))
        } catch (e2) {
          errorCount++
          errors.push(`${file.name}: ${apiErrorMessage(e2)}`)
        }
      } else {
        errorCount++
        errors.push(`${file.name}: ${apiErrorMessage(e)}`)
      }
    }
  }
  for (const r of results) {
    if (r.duplicate) duplicateCount++
    else { okCount++; lastNonDuplicate = r }
  }

  await load()

  // Single-file mode: zachovat původní UX (redirect na detail nové faktury)
  if (files.length === 1 && lastNonDuplicate) {
    lastResult.value = lastNonDuplicate
    router.push(`/bank/${lastNonDuplicate.statement_id}`)
  } else {
    // Batch mode: souhrnný report
    if (errorCount > 0) {
      error.value = t('bank.upload_batch_error', { ok: okCount, dup: duplicateCount, err: errorCount })
        + (errors.length > 0 ? '\n' + errors.slice(0, 5).join('\n') : '')
    } else if (okCount > 0 || duplicateCount > 0) {
      toast.success(t('bank.upload_batch_done', { ok: okCount, dup: duplicateCount }))
    }
  }

  uploading.value = false
  if (input) input.value = ''
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div v-if="!embedded">
        <h1 class="text-2xl font-semibold">{{ t('bank.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('bank.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 ml-auto">
        <button v-if="authStore.canWrite && scanConfigured" @click="onScan" :disabled="scanning"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-primary-500/40 text-primary-700 hover:bg-primary-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/></svg>
          {{ scanning ? '…' : t('bank.scan_folder') }}
        </button>
        <label v-if="authStore.canWrite" class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
          :title="t('bank.upload_hint')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
          {{ uploading ? '…' : t('bank.upload_gpc') }}
          <input type="file" accept=".gpc,.txt,.pdf,*/*" multiple class="hidden" @change="onFileSelected" />
        </label>
      </div>
    </div>

    <!-- Filtry rok / měsíc / účet -->
    <FilterBar :active-count="activeFilterCount">
      <select v-model="yearFilter"
        class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option value="">{{ t('bank.all_years') }}</option>
        <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
      </select>
      <select v-model="monthFilter" :disabled="yearFilter === ''"
        class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50"
        :title="t('bank.month_filter')">
        <option :value="''">{{ t('bank.all_months') }}</option>
        <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
      </select>
      <select v-if="accounts.length > 1" v-model="accountFilterKey"
        class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option value="">{{ t('bank.all_accounts') }}</option>
        <option
          v-for="a in accounts"
          :key="`${a.account_number}|${a.bank_code ?? ''}`"
          :value="`${a.account_number}|${a.bank_code ?? ''}`"
        >{{ accountLabel(a) }}</option>
      </select>
    </FilterBar>

    <div v-if="lastResult" class="rounded-md px-4 py-2 text-sm mb-4"
      :class="lastResult.duplicate ? 'bg-warning-50 border border-warning-500/40 text-warning-600' : 'bg-success-50 border border-success-500/40 text-success-600'">
      <span v-if="lastResult.duplicate">{{ t('bank.import_duplicate', { id: lastResult.statement_id }) }}</span>
      <span v-else>{{ t('bank.import_done', { transactions: lastResult.transactions, matched: lastResult.matched }) }}</span>
    </div>

    <div v-if="error" class="rounded-md px-4 py-2 text-sm mb-4 bg-danger-50 border border-danger-500/40 text-danger-500">
      {{ error }}
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="!statements.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-12 text-center text-neutral-500">
      <template v-if="filtersActive">
        {{ t('bank.no_data_filtered') }}
        <button type="button" @click="resetFilters"
          class="cursor-pointer ml-1 text-primary-600 hover:text-primary-700 underline">{{ t('bank.show_all') }}</button>
      </template>
      <template v-else>{{ t('bank.no_data') }}</template>
    </div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">Datum</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.account') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.currency') }}</th>
            <th class="px-3 py-2 text-left font-medium">Soubor</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('bank.balance') }}</th>
            <th class="px-3 py-2 text-center font-medium">Transakce</th>
            <th class="px-3 py-2 text-center font-medium">{{ t('bank.matched') }}</th>
            <th class="px-3 py-2 text-right font-medium"></th>
          </tr>
        </thead>
        <tbody v-for="group in groupedStatements" :key="group.month" class="divide-y divide-neutral-100">
          <tr class="bg-neutral-50/80 border-t border-neutral-200">
            <td colspan="8" class="px-3 py-1.5 text-xs font-semibold text-neutral-600 tracking-wide">
              {{ group.label }}
              <span class="font-normal text-neutral-400 ml-1">({{ group.items.length }})</span>
            </td>
          </tr>
          <tr v-for="s in group.items" :key="s.id" @click="router.push(`/bank/${s.id}`)" class="cursor-pointer hover:bg-neutral-50">
            <td class="px-3 py-2 text-xs">
              <span class="inline-flex items-center gap-1.5">
                <span>{{ statementDateLabel(s) }}</span>
                <span v-if="s.source === 'email_notice'" :title="t('bank.email_notice_hint')"
                  class="text-[10px] px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500 font-medium">
                  {{ t('bank.email_notice_badge') }}
                </span>
                <span v-else-if="s.source === 'pdf'" :title="t('bank.pdf_source_hint')"
                  class="text-[10px] px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500 font-medium">
                  {{ t('bank.pdf_source_badge') }}
                </span>
                <span v-if="s.statement_number" class="text-neutral-400">#{{ s.statement_number }}</span>
              </span>
            </td>
            <td class="px-3 py-2 text-xs">
              <div class="font-mono">{{ formatAccountNumber(s.account_number, s.bank_code) }}</div>
              <div v-if="s.account_label" class="text-neutral-400 mt-0.5">{{ s.account_label }}</div>
            </td>
            <td class="px-3 py-2">
              <span v-if="s.currency" class="text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-700 font-medium">{{ s.currency }}</span>
              <span v-else class="text-xs text-neutral-400">—</span>
            </td>
            <td class="px-3 py-2 text-xs text-neutral-600 truncate max-w-xs">{{ s.file_name }}</td>
            <td class="px-3 py-2 text-right font-mono text-xs">{{ formatMoney(s.curr_balance, s.currency ?? 'CZK') }}</td>
            <td class="px-3 py-2 text-center">{{ s.transaction_count }}</td>
            <td class="px-3 py-2 text-center">
              <span class="text-xs px-2 py-0.5 rounded font-medium"
                :class="s.matched_count === s.transaction_count ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
                {{ s.matched_count }} / {{ s.transaction_count }}
              </span>
            </td>
            <td class="px-3 py-2 text-right whitespace-nowrap">
              <div class="inline-flex items-center gap-1.5">
                <a v-if="s.has_file" :href="bankApi.downloadUrl(s.id)" @click.stop
                   :title="t('bank.download_gpc')"
                   class="inline-flex items-center gap-1 px-2 h-7 text-xs border border-neutral-200 text-neutral-700 hover:bg-neutral-50 rounded">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                  GPC
                </a>
                <a v-if="s.has_pdf" :href="bankApi.pdfUrl(s.id)" @click.stop
                   :title="t('bank.download_pdf')"
                   class="inline-flex items-center gap-1 px-2 h-7 text-xs border border-neutral-200 text-neutral-700 hover:bg-neutral-50 rounded">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                  PDF
                </a>
                <button v-if="isAdmin" type="button" @click="onDelete(s, $event)"
                   :title="t('bank.delete')"
                   class="cursor-pointer inline-flex w-7 h-7 items-center justify-center text-neutral-400 hover:text-danger-500 hover:bg-danger-50 border border-transparent hover:border-danger-200 rounded">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      </div>

      <!-- Mobile: karty seskupené po měsících -->
      <div class="md:hidden">
        <template v-for="group in groupedStatements" :key="`mg-${group.month}`">
          <div class="bg-neutral-50/80 border-t border-neutral-200 px-3 py-1.5 text-xs font-semibold text-neutral-600">
            {{ group.label }}<span class="font-normal text-neutral-400 ml-1">({{ group.items.length }})</span>
          </div>
          <div class="divide-y divide-neutral-100">
        <div v-for="s in group.items" :key="`m-${s.id}`"
          @click="router.push(`/bank/${s.id}`)"
          class="cursor-pointer hover:bg-neutral-50 px-3 py-3">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 flex items-center gap-1.5 flex-wrap">
              {{ statementDateLabel(s) }}<span v-if="s.statement_number" class="text-neutral-400 ml-1">#{{ s.statement_number }}</span>
              <span v-if="s.source === 'email_notice'" :title="t('bank.email_notice_hint')"
                class="text-[10px] px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500 font-medium">
                {{ t('bank.email_notice_badge') }}
              </span>
              <span v-else-if="s.source === 'pdf'" :title="t('bank.pdf_source_hint')"
                class="text-[10px] px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500 font-medium">
                {{ t('bank.pdf_source_badge') }}
              </span>
              <span v-if="s.currency" class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-700 font-medium">{{ s.currency }}</span>
            </div>
            <div class="font-mono text-sm font-semibold whitespace-nowrap">{{ formatMoney(s.curr_balance, s.currency ?? 'CZK') }}</div>
          </div>
          <div class="font-mono text-xs text-neutral-500 mt-0.5">{{ formatAccountNumber(s.account_number, s.bank_code) }}</div>
          <div v-if="s.account_label" class="text-xs text-neutral-400">{{ s.account_label }}</div>
          <div class="text-xs text-neutral-500 truncate mt-0.5">{{ s.file_name }}</div>
          <div class="flex items-baseline justify-between gap-2 mt-2">
            <span class="text-xs text-neutral-500">{{ s.transaction_count }} transakcí</span>
            <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap"
              :class="s.matched_count === s.transaction_count ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
              {{ s.matched_count }} / {{ s.transaction_count }} {{ t('bank.matched') }}
            </span>
          </div>
          <div class="flex items-center gap-1.5 mt-2">
            <a v-if="s.has_file" :href="bankApi.downloadUrl(s.id)" @click.stop
               class="inline-flex items-center gap-1 px-2 h-7 text-xs border border-neutral-200 text-neutral-700 hover:bg-neutral-50 rounded">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
              GPC
            </a>
            <a v-if="s.has_pdf" :href="bankApi.pdfUrl(s.id)" @click.stop
               class="inline-flex items-center gap-1 px-2 h-7 text-xs border border-neutral-200 text-neutral-700 hover:bg-neutral-50 rounded">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
              PDF
            </a>
            <button v-if="isAdmin" type="button" @click="onDelete(s, $event)"
               class="cursor-pointer inline-flex items-center gap-1 px-2 h-7 text-xs border border-danger-500/40 text-danger-600 hover:bg-danger-50 rounded">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
              {{ t('bank.delete') }}
            </button>
          </div>
        </div>
          </div>
        </template>
      </div>
    </div>

    <nav v-if="!loading && total > perPage" class="mt-4 flex items-center justify-between gap-3 text-sm">
      <span class="text-neutral-500">{{ t('common.pagination_range', { from: rangeFrom, to: rangeTo, total }) }}</span>
      <div class="flex items-center gap-1">
        <button type="button" :disabled="page <= 1" @click="goToPage(page - 1)"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">‹</button>
        <span class="px-2 text-neutral-600">{{ page }} / {{ totalPages }}</span>
        <button type="button" :disabled="page >= totalPages" @click="goToPage(page + 1)"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">›</button>
      </div>
    </nav>

    <!-- #167/#206: volba cílového účtu u sdíleného čísla účtu (různá měna nebo kód banky). Bez click-outside. -->
    <div v-if="ambiguityModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-xl w-full max-w-md p-5">
        <div class="flex items-start gap-3 mb-3">
          <svg class="w-5 h-5 text-primary-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
          <div>
            <h2 class="text-base font-semibold text-neutral-900">{{ t('bank.choose_account_title') }}</h2>
            <p class="text-sm text-neutral-500 mt-1">{{ t('bank.choose_account_hint', { file: ambiguityModal.fileName }) }}</p>
          </div>
        </div>
        <select v-model="ambiguitySelected"
          class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm mb-4">
          <option v-for="c in ambiguityModal.candidates" :key="c.account_id" :value="c.account_id">{{ c.label }}</option>
        </select>
        <div class="flex justify-end gap-2">
          <button type="button" @click="cancelAmbiguity"
            class="cursor-pointer h-9 px-3 border border-neutral-300 text-neutral-700 hover:bg-neutral-50 text-sm font-medium rounded-md">
            {{ t('common.cancel') }}
          </button>
          <button type="button" @click="confirmAmbiguity" :disabled="ambiguitySelected === null"
            class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
            {{ t('bank.choose_account_confirm') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

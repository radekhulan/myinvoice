<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, RouterLink, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { bankApi, type BankStatementDetail, type BankTransaction, type MatchCandidate, type SplitSuggestion } from '@/api/bank'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import Modal from '@/components/ui/Modal.vue'
import VendorPicker from '@/components/purchase/VendorPicker.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { invoicesApi } from '@/api/invoices'
import ClientFormModal from '@/components/modals/ClientFormModal.vue'
import type { Client } from '@/api/clients'
import { useAuthStore } from '@/stores/auth'
import { formatAccountNumber } from '@/utils/bankAccount'

const { t, locale } = useI18n()
const toast = useToast()
const router = useRouter()
const auth = useAuthStore()

// E-mailová avíza jsou měsíční agregát (statement_date = 1. den měsíce) → název měsíce.
function monthLabel(dateStr: string): string {
  const d = new Date(dateStr)
  if (isNaN(d.getTime())) return dateStr
  return d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long', year: 'numeric' })
}

const route = useRoute()
const statement = ref<BankStatementDetail | null>(null)
const loading = ref(true)

// Filtr transakcí dle stavu spárování ('' = vše).
const STATUS_OPTIONS = ['unmatched', 'auto_exact', 'auto_partial', 'manual', 'ignored'] as const
const statusFilter = ref<string>('')
const filteredTransactions = computed<BankTransaction[]>(() => {
  const txs = statement.value?.transactions ?? []
  return statusFilter.value === '' ? txs : txs.filter(tx => tx.match_status === statusFilter.value)
})
// Virtuální (sekundární) zdroj — měsíční agregát, ne nahraný soubor z banky.
// Takový výpis nemá počáteční/koncový zůstatek ani originál ke stažení.
const isVirtual = computed(() =>
  statement.value?.source === 'email_notice' || statement.value?.source === 'idoklad'
)

// Souhrn pro měsíční virtuální výpis: disponibilní zůstatek z nejnovější položky,
// která ho nesla (avíza Creditas/Fio/RB; u iDokladu zůstatek není), + součty
// příjmů/výdajů měsíce z transakcí.
const noticeSummary = computed(() => {
  const s = statement.value
  if (!s || !isVirtual.value) return null
  let last: BankTransaction | null = null
  let credit = 0
  let debit = 0
  for (const tx of s.transactions) {
    if (tx.amount >= 0) credit += tx.amount
    else debit += -tx.amount
    if (tx.balance === null || tx.balance === undefined) continue
    if (last === null || tx.posted_at > last.posted_at || (tx.posted_at === last.posted_at && tx.id > last.id)) {
      last = tx
    }
  }
  return { balance: last?.balance ?? null, balanceAt: last?.posted_at ?? null, credit, debit }
})

const rematching = ref(false)
const matchingTx = ref<number | null>(null)
const matchCtx = ref<BankTransaction | null>(null)
const matchVarsymbol = ref<string>('')
const matchError = ref<string>('')
// Návrhy ke spárování dle částky ±14 dní (vydané i přijaté faktury).
const matchCandidates = ref<MatchCandidate[]>([])
const loadingCandidates = ref(false)
// true = v ±14 dnech nic nesedělo, matchCandidates obsahuje širší (±90 dní) a/nebo cross-currency návrhy.
const candidatesFallback = ref(false)
// Návrhy sloučené úhrady: kombinace faktur jednoho klienta, jejichž součet = platba.
const splitSuggestions = ref<SplitSuggestion[]>([])
const loadingSplit = ref(false)
const splitWindow = ref(7)
// Kotva: konkrétní vybraná faktura, kolem které se dohledá zbytek (téhož klienta).
type AnchorOption = { value: number; label: string; secondary?: string }
const anchorInvoiceId = ref<number | null>(null)
const anchorOptions = ref<AnchorOption[]>([])
const anchorSelected = ref<AnchorOption | null>(null)
const anchorLoading = ref(false)
let anchorSearchTimer: ReturnType<typeof setTimeout> | null = null

// Vytvoření konceptu přijaté faktury z odchozí (záporné) platby.
const createTx = ref<BankTransaction | null>(null)
const createVendorId = ref<number | null>(null)
const vendorModalOpen = ref(false)
const creatingPi = ref(false)
const vendorPickerRef = ref<InstanceType<typeof VendorPicker> | null>(null)

useHotkey('escape', () => {
  if (matchingTx.value !== null) matchingTx.value = null
  if (createTx.value !== null && !vendorModalOpen.value) createTx.value = null
})

function openCreate(tx: BankTransaction) {
  createTx.value = tx
  createVendorId.value = null
}
function onVendorCreated(client: Client) {
  vendorModalOpen.value = false
  createVendorId.value = client.id
  vendorPickerRef.value?.reload()
}
async function submitCreatePurchase() {
  if (!createTx.value || !createVendorId.value || creatingPi.value) return
  creatingPi.value = true
  try {
    const r = await bankApi.createPurchaseInvoice(createTx.value.id, createVendorId.value)
    createTx.value = null
    router.push(`/purchase-invoices/${r.purchase_invoice_id}`)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    creatingPi.value = false
  }
}

async function load() {
  loading.value = true
  try {
    statement.value = await bankApi.get(Number(route.params.id))
  } finally { loading.value = false }
}
onMounted(load)

// --- PDF příloha (nahrání / smazání) ---
const uploadingPdf = ref(false)

async function onPdfSelected(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !statement.value) return
  uploadingPdf.value = true
  try {
    await bankApi.uploadPdf(statement.value.id, file)
    toast.success(t('bank.pdf_uploaded'))
    await load()
  } catch (err) {
    toast.error(apiErrorMessage(err, t('bank.pdf_upload_failed')))
  } finally {
    uploadingPdf.value = false
    if (input) input.value = ''
  }
}

async function onDeletePdf() {
  if (!statement.value) return
  if (!confirm(t('bank.pdf_delete_confirm'))) return
  try {
    await bankApi.deletePdf(statement.value.id)
    toast.success(t('bank.pdf_deleted'))
    await load()
  } catch (err) {
    toast.error(apiErrorMessage(err, t('bank.pdf_delete_failed')))
  }
}

// Smazání avízo-výpisu (e-mailová bankovní avíza). Nabízí se jen když na něm
// nezbývá žádná spárovaná položka — typicky poté, co párování převzal oficiální
// GPC výpis. Backend mazání je admin-only a guarduje matched_count > 0.
const deletingStatement = ref(false)
async function onDeleteStatement() {
  if (!statement.value) return
  if (!confirm(t('bank.statement_delete_confirm'))) return
  deletingStatement.value = true
  try {
    await bankApi.delete(statement.value.id)
    toast.success(t('bank.statement_deleted'))
    router.push({ name: 'bank-statements' })
  } catch (err) {
    toast.error(apiErrorMessage(err, t('bank.statement_delete_failed')))
  } finally {
    deletingStatement.value = false
  }
}

function statusBadge(s: string): string {
  if (s === 'auto_exact') return 'bg-success-50 text-success-600'
  if (s === 'auto_partial') return 'bg-warning-50 text-warning-600'
  if (s === 'manual') return 'bg-primary-100 text-primary-700'
  if (s === 'ignored') return 'bg-neutral-100 text-neutral-500'
  return 'bg-danger-50 text-danger-500'
}

function statusLabel(s: string): string {
  const key = `bank.match_status.${s}`
  const label = t(key)
  return label === key ? s : label
}

function startMatch(tx: BankTransaction) {
  matchingTx.value = tx.id
  matchCtx.value = tx
  // Prefill VS z transakce — ruční zadání zůstává jako druhá možnost
  matchVarsymbol.value = tx.variable_symbol || ''
  matchError.value = ''
  // Návrhy dle částky ±14 dní (best-effort — když selže, ruční VS pořád funguje)
  matchCandidates.value = []
  candidatesFallback.value = false
  loadingCandidates.value = true
  bankApi.matchCandidates(tx.id)
    .then(r => {
      if (matchingTx.value !== tx.id) return
      matchCandidates.value = r.candidates
      candidatesFallback.value = r.fallback
    })
    .catch(() => {})
    .finally(() => { loadingCandidates.value = false })
  // Návrhy sloučené úhrady (jen příchozí platba — klient zaplatil víc faktur naráz)
  splitSuggestions.value = []
  splitWindow.value = 7
  anchorInvoiceId.value = null
  anchorOptions.value = []
  anchorSelected.value = null
  if (tx.amount > 0) loadSplitSuggestions(tx, 7)
}

function loadSplitSuggestions(tx: BankTransaction, window: number, anchorId?: number | null) {
  loadingSplit.value = true
  bankApi.splitSuggestions(tx.id, { window, invoiceId: anchorId ?? undefined })
    .then(r => {
      if (matchingTx.value === tx.id) {
        splitSuggestions.value = r.suggestions
        splitWindow.value = r.window
      }
    })
    .catch(() => {})
    .finally(() => { loadingSplit.value = false })
}

function widenSplitWindow() {
  if (!matchCtx.value) return
  loadSplitSuggestions(matchCtx.value, Math.min(60, splitWindow.value + 7), anchorInvoiceId.value)
}

// Našeptávač kotvy: vystavené faktury vč. zaplacených (fulltext varsymbol + klient).
// Zaplacené musí jít vybrat kvůli rekonciliaci (split nabízí i 'paid').
function onAnchorSearch(q: string) {
  if (anchorSearchTimer) clearTimeout(anchorSearchTimer)
  const query = q.trim()
  if (query.length < 2) { anchorOptions.value = []; return }
  anchorLoading.value = true
  anchorSearchTimer = setTimeout(() => {
    invoicesApi.searchMatchable(query, 20)
      .then(list => {
        anchorOptions.value = list.map(i => {
          const owed = i.amount_to_pay - (i.paid_total ?? 0)
          const shown = owed > 0 ? owed : i.amount_to_pay
          return {
            value: i.id,
            label: `${i.varsymbol || '#' + i.id} — ${i.client_company_name}`,
            secondary: `${formatMoney(shown, i.currency)} · ${formatDate(i.due_date || i.issue_date)}`,
          }
        })
      })
      .catch(() => { anchorOptions.value = [] })
      .finally(() => { anchorLoading.value = false })
  }, 220)
}

function onAnchorSelect(id: number | null) {
  anchorInvoiceId.value = id
  anchorSelected.value = id !== null
    ? (anchorOptions.value.find(o => o.value === id) ?? anchorSelected.value)
    : null
  if (!matchCtx.value) return
  loadSplitSuggestions(matchCtx.value, splitWindow.value, id)
}

async function confirmSuggestion(s: SplitSuggestion) {
  if (!matchingTx.value) return
  matchError.value = ''
  try {
    await bankApi.matchMultiple(matchingTx.value, s.invoices.map(i => i.id))
    matchingTx.value = null
    await load()
  } catch (e: any) {
    matchError.value = apiErrorMessage(e, t('bank.match_failed'))
  }
}

async function confirmCandidate(c: MatchCandidate) {
  if (!matchingTx.value) return
  matchError.value = ''
  try {
    await bankApi.matchManual(matchingTx.value,
      c.type === 'invoice' ? { invoiceId: c.id } : { purchaseInvoiceId: c.id })
    matchingTx.value = null
    await load()
  } catch (e: any) {
    matchError.value = apiErrorMessage(e, t('bank.match_failed'))
  }
}

async function confirmMatch() {
  if (!matchingTx.value || !matchVarsymbol.value.trim()) return
  matchError.value = ''
  try {
    await bankApi.matchManual(matchingTx.value, { varsymbol: matchVarsymbol.value.trim() })
    matchingTx.value = null
    await load()
  } catch (e: any) {
    matchError.value = apiErrorMessage(e, t('bank.match_failed'))
  }
}

const textDetail = ref<BankTransaction | null>(null)
const transactionDetailFields = computed(() => {
  const tx = textDetail.value
  if (!tx) return []
  return [
    { label: t('bank.transaction_date'), value: formatDate(tx.posted_at) },
    { label: t('bank.counterparty'), value: tx.counterparty_name },
    { label: t('bank.counterparty_account'), value: formatAccountNumber(tx.counterparty_account, tx.counterparty_bank) },
    { label: t('bank.own_account'), value: formatAccountNumber(statement.value?.account_number, statement.value?.bank_code) },
    { label: t('bank.variable_symbol'), value: tx.variable_symbol },
    { label: t('bank.constant_symbol'), value: tx.constant_symbol },
    { label: t('bank.specific_symbol'), value: tx.specific_symbol },
    { label: t('bank.bank_reference'), value: tx.bank_ref },
    { label: t('bank.transaction_balance'), value: tx.balance != null ? formatMoney(tx.balance, tx.currency ?? statement.value?.currency ?? 'CZK') : null },
  ].filter(field => field.value != null && field.value !== '')
})
const ignoreTarget = ref<BankTransaction | null>(null)
const ignoreNote = ref('')
const ignoring = ref(false)
const ignoreError = ref('')

function ignoreTx(tx: BankTransaction) {
  ignoreTarget.value = tx
  ignoreNote.value = tx.ignore_note ?? ''
  ignoreError.value = ''
}

function closeIgnore() {
  if (!ignoring.value) ignoreTarget.value = null
}

async function confirmIgnore() {
  const tx = ignoreTarget.value
  if (!tx || ignoring.value) return
  ignoring.value = true
  ignoreError.value = ''
  try {
    const result = await bankApi.ignore(tx.id, ignoreNote.value.trim() || null)
    tx.match_status = 'ignored'
    tx.ignore_note = result.ignore_note
    ignoreTarget.value = null
  } catch (e) {
    ignoreError.value = apiErrorMessage(e, t('bank.ignore_failed'))
  } finally {
    ignoring.value = false
  }
}

const unmatchTarget = ref<BankTransaction | null>(null)
const unmatching = ref(false)
const unmatchError = ref('')

function unmatchTx(tx: BankTransaction) {
  unmatchTarget.value = tx
  unmatchError.value = ''
}

function closeUnmatch() {
  if (!unmatching.value) unmatchTarget.value = null
}

async function confirmUnmatch() {
  const tx = unmatchTarget.value
  if (!tx || unmatching.value) return
  unmatching.value = true
  unmatchError.value = ''
  try {
    await bankApi.unmatch(tx.id)
    if (statement.value && ['auto_exact', 'auto_partial', 'manual'].includes(tx.match_status)) {
      statement.value.matched_count = Math.max(0, statement.value.matched_count - 1)
    }
    Object.assign(tx, {
      match_status: 'unmatched', ignore_note: null, matched_invoice_id: null, matched_purchase_invoice_id: null,
      matched_varsymbol: null, matched_invoice_amount: null, matched_client_name: null,
      matched_purchase_ref: null, matched_vendor_name: null, matched_invoices: [], matched_at: null,
    })
    unmatchTarget.value = null
  } catch (e) {
    unmatchError.value = apiErrorMessage(e, t('bank.unmatch_failed'))
  } finally {
    unmatching.value = false
  }
}

async function rematchStatement() {
  if (!statement.value || rematching.value) return
  if (!confirm(t('bank.rematch_confirm'))) return
  rematching.value = true
  try {
    const r = await bankApi.rematch(statement.value.id)
    toast.success(t('bank.rematch_done', {
      matched: r.newly_matched,
      partial: r.newly_partial,
      remaining: r.still_unmatched,
    }))
    await load()
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('bank.rematch_failed')))
  } finally {
    rematching.value = false
  }
}
</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

  <div v-else-if="statement">
    <RouterLink to="/bank" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('bank.back') }}</RouterLink>
    <h1 class="text-2xl font-semibold mt-1 flex items-center gap-2 flex-wrap">
      <span v-if="statement.source === 'email_notice'" :title="t('bank.email_notice_hint')"
        class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-500 font-medium">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        {{ t('bank.email_notice_badge') }}
      </span>
      <span v-if="statement.source === 'idoklad'" :title="t('bank.idoklad_source_hint')"
        class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-500 font-medium">
        {{ t('bank.idoklad_source_badge') }}
      </span>
      <span v-if="statement.source === 'email_notice'">{{ t('bank.email_notice_statement_title', { month: monthLabel(statement.statement_date) }) }}</span>
      <span v-else-if="statement.source === 'idoklad'">{{ t('bank.idoklad_statement_title', { month: monthLabel(statement.statement_date) }) }}</span>
      <span v-else>{{ t('bank.statement_title', { number: statement.statement_number, date: formatDate(statement.statement_date) }) }}</span>
    </h1>
    <p class="text-sm text-neutral-500 mt-0.5 flex items-center gap-1.5 flex-wrap">
      <span class="text-neutral-500">{{ t('bank.account') }}</span>
      <span class="font-mono font-semibold text-neutral-800">{{ formatAccountNumber(statement.account_number, statement.bank_code) }}</span>
      <span v-if="statement.account_label" class="text-neutral-400">— {{ statement.account_label }}</span>
      <span v-if="statement.currency" class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-700 font-medium">{{ statement.currency }}</span>
      <span>· {{ statement.file_name }}</span>
    </p>

    <!-- Měsíční avízo-výpis: disponibilní zůstatek z nejnovějšího avíza (nesou ho
         Creditas/Fio/RB) + součty příjmů/výdajů měsíce spočtené z transakcí. -->
    <div v-if="isVirtual && noticeSummary" class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4 mb-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.available_balance') }}</div>
        <div class="text-lg font-mono font-semibold">
          {{ noticeSummary.balance !== null ? formatMoney(noticeSummary.balance, statement.currency ?? 'CZK') : '—' }}
        </div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.available_balance_as_of') }}</div>
        <div class="text-lg font-mono">
          {{ noticeSummary.balanceAt !== null ? formatDate(noticeSummary.balanceAt) : '—' }}
        </div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.credit_total') }}</div>
        <div class="text-lg font-mono text-success-600">+{{ formatMoney(noticeSummary.credit, statement.currency ?? 'CZK') }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.debit_total') }}</div>
        <div class="text-lg font-mono text-danger-500">−{{ formatMoney(noticeSummary.debit, statement.currency ?? 'CZK') }}</div>
      </div>
    </div>

    <div v-else-if="!isVirtual" class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4 mb-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.prev_balance') }}</div>
        <div class="text-lg font-mono">{{ formatMoney(statement.prev_balance, statement.currency ?? 'CZK') }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.curr_balance') }}</div>
        <div class="text-lg font-mono font-semibold">{{ formatMoney(statement.curr_balance, statement.currency ?? 'CZK') }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.credit_total') }}</div>
        <div class="text-lg font-mono text-success-600">+{{ formatMoney(statement.credit_total, statement.currency ?? 'CZK') }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.debit_total') }}</div>
        <div class="text-lg font-mono text-danger-500">−{{ formatMoney(Math.abs(statement.debit_total), statement.currency ?? 'CZK') }}</div>
      </div>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mt-4">
      <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
          {{ t('bank.transactions') }}
          ({{ filteredTransactions.length }}<span v-if="statusFilter"> / {{ statement.transactions.length }}</span>)
        </h2>
        <div class="flex items-center gap-2">
          <select v-model="statusFilter"
            :title="t('bank.filter_status')"
            class="h-8 px-2 text-xs border border-neutral-300 rounded-md text-neutral-700 bg-surface">
            <option value="">{{ t('bank.filter_all') }}</option>
            <option v-for="s in STATUS_OPTIONS" :key="s" :value="s">{{ statusLabel(s) }}</option>
          </select>
          <a v-if="statement.has_file" :href="bankApi.downloadUrl(statement.id)"
             :title="t('bank.download_gpc')"
             class="cursor-pointer h-8 px-3 text-xs border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md font-medium inline-flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            GPC
          </a>
          <a v-if="statement.has_pdf" :href="bankApi.pdfUrl(statement.id)"
             :title="statement.pdf_name ?? t('bank.download_pdf')"
             class="cursor-pointer h-8 px-3 text-xs border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md font-medium inline-flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            PDF
          </a>
          <label v-if="auth.canWrite && !statement.has_pdf && !isVirtual"
             :title="t('bank.pdf_upload_hint')"
             class="cursor-pointer h-8 px-3 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md font-medium inline-flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
            {{ uploadingPdf ? '…' : t('bank.pdf_upload') }}
            <input type="file" accept=".pdf,application/pdf" class="hidden" @change="onPdfSelected" />
          </label>
          <button v-if="auth.canWrite && statement.has_pdf" type="button" @click="onDeletePdf"
             :title="t('bank.pdf_delete')"
             class="cursor-pointer h-8 px-3 text-xs border border-danger-500/40 text-danger-600 hover:bg-danger-50 rounded-md font-medium inline-flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
            {{ t('bank.pdf_delete') }}
          </button>
          <button v-if="auth.canWrite" type="button" @click="rematchStatement" :disabled="rematching"
            class="cursor-pointer h-8 px-3 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 disabled:opacity-50 rounded-md font-medium inline-flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" :class="{ 'animate-spin': rematching }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15" />
            </svg>
            {{ rematching ? t('bank.rematch_running') : t('bank.rematch') }}
          </button>
          <button v-if="auth.isAdmin && isVirtual && statement.matched_count === 0"
            type="button" @click="onDeleteStatement" :disabled="deletingStatement"
            :title="t('bank.statement_delete_hint')"
            class="cursor-pointer h-8 px-3 text-xs border border-danger-500/40 text-danger-600 hover:bg-danger-50 disabled:opacity-50 rounded-md font-medium inline-flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
            {{ deletingStatement ? '…' : t('bank.statement_delete') }}
          </button>
        </div>
      </header>
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.date') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('bank.amount') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.vs_ks') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.counterparty') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.invoice') }}</th>
            <th class="px-3 py-2 text-center font-medium">{{ t('invoice.status_label') }}</th>
            <th class="px-3 py-2 w-32"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="tx in filteredTransactions" :key="tx.id" :class="{ 'opacity-50': tx.match_status === 'ignored' }">
            <td class="px-3 py-2 text-xs">{{ formatDate(tx.posted_at) }}</td>
            <td class="px-3 py-2 text-right font-mono text-xs"
              :class="tx.amount > 0 ? 'text-success-600' : 'text-danger-500'">
              {{ tx.amount > 0 ? '+' : '' }}{{ formatMoney(tx.amount, tx.currency ?? statement.currency ?? 'CZK') }}
            </td>
            <td class="px-3 py-2 font-mono text-xs">
              <span v-if="tx.variable_symbol">{{ tx.variable_symbol }}</span>
              <span v-else class="text-neutral-400">—</span>
              <span v-if="tx.constant_symbol" class="text-neutral-400 ml-1">/ {{ tx.constant_symbol }}</span>
            </td>
            <td class="px-3 py-2 text-xs">
              <div class="font-mono text-neutral-600">{{ tx.counterparty_account }}<span v-if="tx.counterparty_bank">/{{ tx.counterparty_bank }}</span></div>
              <div v-if="tx.match_status === 'ignored' && tx.ignore_note" class="text-neutral-600 whitespace-pre-wrap break-words max-w-xs">{{ t('bank.ignore_note_label') }}: {{ tx.ignore_note }}</div>
              <div v-if="tx.description" class="text-neutral-500 truncate max-w-xs">{{ tx.description }}</div>
            </td>
            <td class="px-3 py-2 text-xs">
              <template v-if="(tx.matched_invoices?.length ?? 0) > 1">
                <RouterLink v-for="mi in tx.matched_invoices" :key="mi.invoice_id" :to="`/invoices/${mi.invoice_id}`"
                  class="text-primary-600 hover:underline block">
                  {{ mi.varsymbol || `#${mi.invoice_id}` }}
                </RouterLink>
                <div v-if="tx.matched_invoices?.[0]?.client_name" class="text-neutral-500 text-xs">{{ tx.matched_invoices[0].client_name }}</div>
              </template>
              <template v-else>
                <RouterLink v-if="tx.matched_invoice_id" :to="`/invoices/${tx.matched_invoice_id}`"
                  class="text-primary-600 hover:underline">
                  {{ tx.matched_varsymbol || `#${tx.matched_invoice_id}` }}
                </RouterLink>
                <RouterLink v-else-if="tx.matched_purchase_invoice_id" :to="`/purchase-invoices/${tx.matched_purchase_invoice_id}`"
                  class="text-primary-600 hover:underline">
                  {{ tx.matched_purchase_ref || `#${tx.matched_purchase_invoice_id}` }}
                </RouterLink>
                <span v-else class="text-neutral-400">—</span>
                <div v-if="tx.matched_client_name" class="text-neutral-500 text-xs">{{ tx.matched_client_name }}</div>
                <div v-else-if="tx.matched_vendor_name" class="text-neutral-500 text-xs">{{ tx.matched_vendor_name }}</div>
              </template>
            </td>
            <td class="px-3 py-2 text-center">
              <span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusBadge(tx.match_status)">
                {{ statusLabel(tx.match_status) }}
              </span>
            </td>
            <td class="px-3 py-2 text-right text-xs whitespace-nowrap">
              <RouterLink v-if="tx.matched_invoice_id" :to="`/invoices/${tx.matched_invoice_id}`"
                class="text-primary-600 hover:text-primary-700 mr-2">{{ t('bank.open') }}</RouterLink>
              <RouterLink v-else-if="tx.matched_purchase_invoice_id" :to="`/purchase-invoices/${tx.matched_purchase_invoice_id}`"
                class="text-primary-600 hover:text-primary-700 mr-2">{{ t('bank.open') }}</RouterLink>
              <button v-if="(tx.amount < 0 && tx.match_status === 'unmatched') && auth.canWrite" @click="openCreate(tx)"
                class="cursor-pointer text-primary-600 hover:text-primary-700 mr-2">
                {{ t('bank.create_purchase') }}
              </button>
              <button v-if="(tx.match_status === 'unmatched' || tx.match_status === 'auto_partial') && auth.canWrite"
                @click="startMatch(tx)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-2">
                {{ t('bank.match') }}
              </button>
              <button v-if="(tx.match_status === 'unmatched') && auth.canWrite" @click="ignoreTx(tx)"
                class="cursor-pointer text-neutral-500 hover:text-neutral-700">
                {{ t('bank.ignore') }}
              </button>
              <button v-if="(['auto_exact','auto_partial','manual','ignored'].includes(tx.match_status)) && auth.canWrite"
                @click="unmatchTx(tx)" class="cursor-pointer text-neutral-500 hover:text-danger-600">
                {{ t(tx.match_status === 'ignored' ? 'bank.unignore' : 'bank.unmatch') }}
              </button>
              <button type="button" @click="textDetail = tx" :title="t('bank.show_transaction_text')"
                :aria-label="t('bank.show_transaction_text')"
                class="cursor-pointer shrink-0 inline-flex items-center justify-center w-7 h-7 ml-2 align-middle border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded focus-visible:outline-2 focus-visible:outline-primary-500">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      </div>

      <!-- Mobile: stack karet -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="tx in filteredTransactions" :key="`m-${tx.id}`"
          class="p-3 space-y-2"
          :class="{ 'opacity-50': tx.match_status === 'ignored' }">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-mono text-base font-semibold whitespace-nowrap"
              :class="tx.amount > 0 ? 'text-success-600' : 'text-danger-500'">
              {{ tx.amount > 0 ? '+' : '' }}{{ formatMoney(tx.amount, tx.currency ?? statement.currency ?? 'CZK') }}
            </div>
            <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="statusBadge(tx.match_status)">
              {{ statusLabel(tx.match_status) }}
            </span>
          </div>
          <div class="flex items-baseline justify-between text-xs text-neutral-500">
            <span class="font-mono">{{ formatDate(tx.posted_at) }}</span>
            <span class="font-mono">
              <span v-if="tx.variable_symbol">VS {{ tx.variable_symbol }}</span>
              <span v-else class="text-neutral-400">—</span>
              <span v-if="tx.constant_symbol" class="text-neutral-400 ml-1">/ {{ tx.constant_symbol }}</span>
            </span>
          </div>
          <div class="text-xs">
            <div class="font-mono text-neutral-600 truncate">{{ tx.counterparty_account }}<span v-if="tx.counterparty_bank">/{{ tx.counterparty_bank }}</span></div>
            <div v-if="tx.description" class="text-neutral-500 truncate">{{ tx.description }}</div>
          </div>
          <div v-if="(tx.matched_invoices?.length ?? 0) > 1" class="text-xs">
            <RouterLink v-for="mi in tx.matched_invoices" :key="mi.invoice_id" :to="`/invoices/${mi.invoice_id}`"
              class="text-primary-600 hover:underline font-mono mr-2">
              {{ mi.varsymbol || `#${mi.invoice_id}` }}
            </RouterLink>
            <span v-if="tx.matched_invoices?.[0]?.client_name" class="text-neutral-500">{{ tx.matched_invoices[0].client_name }}</span>
          </div>
          <div v-else-if="tx.matched_invoice_id" class="text-xs">
            <RouterLink :to="`/invoices/${tx.matched_invoice_id}`"
              class="text-primary-600 hover:underline font-mono">
              {{ tx.matched_varsymbol || `#${tx.matched_invoice_id}` }}
            </RouterLink>
            <span v-if="tx.matched_client_name" class="text-neutral-500 ml-2">{{ tx.matched_client_name }}</span>
          </div>
          <div v-else-if="tx.matched_purchase_invoice_id" class="text-xs">
            <RouterLink :to="`/purchase-invoices/${tx.matched_purchase_invoice_id}`"
              class="text-primary-600 hover:underline font-mono">
              {{ tx.matched_purchase_ref || `#${tx.matched_purchase_invoice_id}` }}
            </RouterLink>
            <span v-if="tx.matched_vendor_name" class="text-neutral-500 ml-2">{{ tx.matched_vendor_name }}</span>
          </div>
          <p v-if="tx.match_status === 'ignored' && tx.ignore_note" class="text-xs text-neutral-600 whitespace-pre-wrap break-words">{{ t('bank.ignore_note_label') }}: {{ tx.ignore_note }}</p>
          <div class="flex flex-wrap gap-2 pt-1">
            <RouterLink v-if="tx.matched_invoice_id" :to="`/invoices/${tx.matched_invoice_id}`"
              class="flex-1 h-9 inline-flex items-center justify-center text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md">
              {{ t('bank.open') }}
            </RouterLink>
            <RouterLink v-else-if="tx.matched_purchase_invoice_id" :to="`/purchase-invoices/${tx.matched_purchase_invoice_id}`"
              class="flex-1 h-9 inline-flex items-center justify-center text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md">
              {{ t('bank.open') }}
            </RouterLink>
            <button v-if="(tx.amount < 0 && tx.match_status === 'unmatched') && auth.canWrite" @click="openCreate(tx)"
              class="cursor-pointer flex-1 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md">
              {{ t('bank.create_purchase') }}
            </button>
            <button v-if="(tx.match_status === 'unmatched' || tx.match_status === 'auto_partial') && auth.canWrite"
              @click="startMatch(tx)"
              class="cursor-pointer flex-1 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md">
              {{ t('bank.match') }}
            </button>
            <button v-if="(tx.match_status === 'unmatched') && auth.canWrite" @click="ignoreTx(tx)"
              class="cursor-pointer flex-1 h-9 text-sm border border-neutral-300 text-neutral-600 hover:bg-neutral-50 rounded-md">
              {{ t('bank.ignore') }}
            </button>
            <button v-if="(['auto_exact','auto_partial','manual','ignored'].includes(tx.match_status)) && auth.canWrite"
              @click="unmatchTx(tx)"
              class="cursor-pointer flex-1 h-9 text-sm border border-neutral-300 text-neutral-600 hover:bg-danger-50 hover:text-danger-600 rounded-md">
              {{ t(tx.match_status === 'ignored' ? 'bank.unignore' : 'bank.unmatch') }}
            </button>
            <button type="button" @click="textDetail = tx" :title="t('bank.show_transaction_text')"
                :aria-label="t('bank.show_transaction_text')"
                class="cursor-pointer shrink-0 inline-flex items-center justify-center w-9 h-9 border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded focus-visible:outline-2 focus-visible:outline-primary-500">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
              </button>
          </div>
        </div>
      </div>
    </div>

    <Modal v-if="textDetail" :title="t('bank.show_transaction_text')" width-class="max-w-xl" @close="textDetail = null">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <p class="text-xl font-semibold font-mono" :class="textDetail.amount > 0 ? 'text-success-600' : 'text-danger-500'">
          {{ textDetail.amount > 0 ? '+' : '' }}{{ formatMoney(textDetail.amount, textDetail.currency ?? statement.currency ?? 'CZK') }}
        </p>
        <span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusBadge(textDetail.match_status)">
          {{ statusLabel(textDetail.match_status) }}
        </span>
      </div>
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm mb-5">
        <div v-for="field in transactionDetailFields" :key="field.label" class="min-w-0">
          <dt class="text-neutral-500 mb-1">{{ field.label }}</dt>
          <dd class="text-neutral-700 whitespace-pre-wrap break-words">{{ field.value }}</dd>
        </div>
      </dl>
      <dl class="space-y-4 text-sm">
        <div v-if="textDetail.matched_invoices?.length || textDetail.matched_invoice_id || textDetail.matched_purchase_invoice_id">
          <dt class="font-medium mb-1">{{ t('bank.invoice') }}</dt>
          <dd class="space-y-1 break-words">
            <template v-if="textDetail.matched_invoices?.length">
              <div v-for="invoice in textDetail.matched_invoices" :key="invoice.invoice_id">
                <RouterLink :to="`/invoices/${invoice.invoice_id}`" class="text-primary-600 hover:underline">
                  {{ invoice.varsymbol || `#${invoice.invoice_id}` }}
                </RouterLink>
                <span v-if="invoice.client_name" class="text-neutral-500"> · {{ invoice.client_name }}</span>
              </div>
            </template>
            <div v-else-if="textDetail.matched_invoice_id">
              <RouterLink :to="`/invoices/${textDetail.matched_invoice_id}`" class="text-primary-600 hover:underline">
                {{ textDetail.matched_varsymbol || `#${textDetail.matched_invoice_id}` }}
              </RouterLink>
              <span v-if="textDetail.matched_client_name" class="text-neutral-500"> · {{ textDetail.matched_client_name }}</span>
            </div>
            <div v-if="textDetail.matched_purchase_invoice_id">
              <RouterLink :to="`/purchase-invoices/${textDetail.matched_purchase_invoice_id}`" class="text-primary-600 hover:underline">
                {{ textDetail.matched_purchase_ref || `#${textDetail.matched_purchase_invoice_id}` }}
              </RouterLink>
              <span v-if="textDetail.matched_vendor_name" class="text-neutral-500"> · {{ textDetail.matched_vendor_name }}</span>
            </div>
          </dd>
        </div>
        <div v-if="textDetail.description">
          <dt class="font-medium mb-1">{{ t('bank.transaction_description') }}</dt>
          <dd class="text-neutral-700 whitespace-pre-wrap break-words">{{ textDetail.description }}</dd>
        </div>
        <div v-if="textDetail.match_status === 'ignored' && textDetail.ignore_note">
          <dt class="font-medium mb-1">{{ t('bank.ignore_note_label') }}</dt>
          <dd class="text-neutral-700 whitespace-pre-wrap break-words">{{ textDetail.ignore_note }}</dd>
        </div>
      </dl>
      <template #footer>
        <button type="button" @click="textDetail = null"
          class="cursor-pointer px-3 py-2 text-sm rounded-md border border-neutral-300">{{ t('common.close') }}</button>
      </template>
    </Modal>

    <Modal v-if="unmatchTarget" :title="t(unmatchTarget.match_status === 'ignored' ? 'bank.unignore' : 'bank.unmatch')" width-class="max-w-md" @close="closeUnmatch">
      <p class="text-sm mb-3">{{ t(unmatchTarget.match_status === 'ignored' ? 'bank.unignore_confirm' : 'bank.unmatch_confirm') }}</p>
      <p class="text-xs text-neutral-500">
        {{ formatDate(unmatchTarget.posted_at) }} · {{ formatMoney(unmatchTarget.amount, unmatchTarget.currency ?? statement.currency ?? 'CZK') }}
        <span v-if="unmatchTarget.counterparty_name"> · {{ unmatchTarget.counterparty_name }}</span>
      </p>
      <div v-if="unmatchTarget.ignore_note" class="mt-4 text-sm">
        <p class="font-medium mb-1">{{ t('bank.ignore_note_label') }}</p>
        <p class="text-neutral-700 whitespace-pre-wrap break-words">{{ unmatchTarget.ignore_note }}</p>
        <p class="text-neutral-500 mt-2">{{ t('bank.unmatch_note_removed') }}</p>
      </div>
      <p v-if="unmatchError" role="alert" class="text-sm text-danger-600 mt-2">{{ unmatchError }}</p>
      <template #footer>
        <button type="button" :disabled="unmatching" @click="closeUnmatch"
          class="cursor-pointer px-3 py-2 text-sm rounded-md border border-neutral-300 disabled:opacity-50">{{ t('common.cancel') }}</button>
        <button type="button" :disabled="unmatching" @click="confirmUnmatch"
          class="cursor-pointer px-3 py-2 text-sm rounded-md bg-danger-600 text-white disabled:opacity-50">
          {{ unmatching ? t('common.saving') : t(unmatchTarget.match_status === 'ignored' ? 'bank.unignore' : 'bank.unmatch') }}
        </button>
      </template>
    </Modal>

    <Modal v-if="ignoreTarget" :title="t('bank.ignore')" width-class="max-w-md" @close="closeIgnore">
      <form id="ignore-transaction" @submit.prevent="confirmIgnore">
        <p class="text-sm mb-3">{{ t('bank.ignore_confirm') }}</p>
        <p class="text-xs text-neutral-500 mb-4">
          {{ formatDate(ignoreTarget.posted_at) }} · {{ formatMoney(ignoreTarget.amount, ignoreTarget.currency ?? statement.currency ?? 'CZK') }}
          <span v-if="ignoreTarget.counterparty_name"> · {{ ignoreTarget.counterparty_name }}</span>
        </p>
        <label for="ignore-note" class="block text-sm font-medium mb-1">{{ t('bank.ignore_note') }}</label>
        <textarea id="ignore-note" v-model="ignoreNote" :disabled="ignoring" maxlength="1000" rows="3"
          class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        <p v-if="ignoreError" role="alert" class="text-sm text-danger-600 mt-2">{{ ignoreError }}</p>
      </form>
      <template #footer>
        <button type="button" :disabled="ignoring" @click="closeIgnore"
          class="cursor-pointer px-3 py-2 text-sm rounded-md border border-neutral-300 disabled:opacity-50">{{ t('common.cancel') }}</button>
        <button type="submit" form="ignore-transaction" :disabled="ignoring"
          class="cursor-pointer px-3 py-2 text-sm rounded-md bg-primary-600 text-white disabled:opacity-50">
          {{ ignoring ? t('common.saving') : t('bank.ignore') }}
        </button>
      </template>
    </Modal>

    <!-- Manual match modal — návrhy dle částky + ruční VS jako druhá možnost -->
    <div v-if="matchingTx" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('bank.manual_match_title') }}</h3>
        <p v-if="matchCtx" class="text-xs text-neutral-500 mb-3 font-mono">
          {{ matchCtx.amount > 0 ? '+' : '' }}{{ formatMoney(matchCtx.amount, matchCtx.currency ?? statement.currency ?? 'CZK') }}
          · {{ formatDate(matchCtx.posted_at) }}
          <span v-if="matchCtx.counterparty_name" class="text-neutral-400"> · {{ matchCtx.counterparty_name }}</span>
        </p>

        <!-- Návrhy ke spárování dle částky (±14 dní, fallback ±90 dní) -->
        <div class="mb-4">
          <div class="text-sm font-medium text-neutral-700 mb-1.5">{{ t('bank.candidates_title') }}</div>
          <p v-if="!loadingCandidates && candidatesFallback && matchCandidates.length > 0"
            class="text-xs text-warning-600 bg-warning-50 border border-warning-500/30 rounded-md px-2 py-1.5 mb-1.5">
            {{ t('bank.candidates_fallback_hint') }}
          </p>
          <div v-if="loadingCandidates" class="text-xs text-neutral-500 py-2">{{ t('common.loading') }}</div>
          <div v-else-if="matchCandidates.length === 0" class="text-xs text-neutral-400 py-2">{{ t('bank.no_candidates') }}</div>
          <ul v-else class="border border-neutral-200 rounded-md divide-y divide-neutral-100 max-h-56 overflow-auto">
            <li v-for="c in matchCandidates" :key="`${c.type}-${c.id}`">
              <button type="button" @click="confirmCandidate(c)"
                class="w-full text-left px-3 py-2 hover:bg-primary-50 flex items-center justify-between gap-2">
                <span class="min-w-0">
                  <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-semibold"
                    :class="c.type === 'invoice' ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
                    {{ c.type === 'invoice' ? t('bank.candidate_issued') : t('bank.candidate_purchase') }}
                  </span>
                  <span v-if="c.paid" class="text-[10px] uppercase px-1.5 py-0.5 rounded font-semibold bg-neutral-200 text-neutral-600 ml-1">
                    {{ t('bank.candidate_paid') }}
                  </span>
                  <span v-if="c.currency_mismatch" :title="t('bank.candidate_currency_mismatch_hint')"
                    class="text-[10px] uppercase px-1.5 py-0.5 rounded font-semibold bg-danger-50 text-danger-600 ml-1">
                    {{ t('bank.candidate_currency_mismatch') }}
                  </span>
                  <span class="font-mono text-sm ml-1">{{ c.ref || `#${c.id}` }}</span>
                  <span v-if="c.party" class="text-xs text-neutral-500 block truncate">{{ c.party }}</span>
                </span>
                <span class="text-right whitespace-nowrap shrink-0">
                  <span class="font-mono text-sm">{{ formatMoney(c.amount, c.currency) }}</span>
                  <span v-if="c.converted_amount != null" class="text-xs text-neutral-400 block">
                    ≈ {{ formatMoney(c.converted_amount, c.converted_currency || 'CZK') }}
                  </span>
                  <span class="text-xs text-neutral-400 block">{{ formatDate(c.due_date || c.issue_date) }}</span>
                </span>
              </button>
            </li>
          </ul>
        </div>

        <!-- Sloučená úhrada: kombinace faktur jednoho klienta sečtené na částku platby -->
        <div v-if="matchCtx && matchCtx.amount > 0" class="mb-4">
          <div class="flex items-center justify-between gap-2 mb-1">
            <div class="text-sm font-medium text-neutral-700">{{ t('bank.split_title') }}</div>
            <button v-if="splitWindow < 60" type="button" @click="widenSplitWindow"
              class="cursor-pointer text-xs text-primary-600 hover:underline whitespace-nowrap">
              {{ t('bank.split_widen', { days: Math.min(60, splitWindow + 7) }) }}
            </button>
          </div>
          <p class="text-xs text-neutral-400 mb-1.5">{{ t('bank.split_hint', { days: splitWindow }) }}</p>

          <!-- Kotva: vyber jednu fakturu, dohledá se zbytek téhož klienta -->
          <div class="mb-2">
            <SearchableSelect
              :model-value="anchorInvoiceId"
              :options="anchorOptions"
              :selected-option="anchorSelected"
              :remote="true"
              :loading="anchorLoading"
              :placeholder="t('bank.split_anchor_placeholder')"
              :loading-label="t('common.loading')"
              :no-results-label="t('bank.no_candidates')"
              @search="onAnchorSearch"
              @update:model-value="(v) => onAnchorSelect(v as number | null)" />
            <p v-if="anchorInvoiceId" class="text-xs text-primary-600 mt-1">{{ t('bank.split_anchor_active') }}</p>
          </div>

          <div v-if="loadingSplit" class="text-xs text-neutral-500 py-2">{{ t('common.loading') }}</div>
          <div v-else-if="splitSuggestions.length === 0" class="text-xs text-neutral-400 py-2">{{ t('bank.split_none') }}</div>
          <ul v-else class="space-y-2">
            <li v-for="(s, idx) in splitSuggestions" :key="idx" class="border border-neutral-200 rounded-md p-2.5">
              <div class="flex items-center justify-between gap-2 mb-1.5">
                <span class="text-sm font-medium truncate">{{ s.client_name || t('bank.split_unknown_client') }}</span>
                <span class="font-mono text-sm"
                  :class="Math.abs(s.total - Math.abs(matchCtx.amount)) < 1 ? 'text-success-600' : 'text-neutral-600'">
                  {{ formatMoney(s.total, s.currency) }}
                </span>
              </div>
              <ul class="text-xs text-neutral-500 space-y-0.5 mb-2">
                <li v-for="inv in s.invoices" :key="inv.id" class="flex items-center justify-between gap-2">
                  <span class="font-mono truncate">
                    {{ inv.ref || `#${inv.id}` }}
                    <span v-if="inv.is_paid"
                      class="font-sans text-[10px] uppercase px-1.5 py-0.5 rounded font-semibold bg-neutral-200 text-neutral-600 ml-1"
                      :title="t('bank.split_reconcile_hint')">{{ t('bank.candidate_paid') }}</span>
                    <span class="text-neutral-400 ml-1">· {{ formatDate(inv.due_date || inv.issue_date) }}</span>
                  </span>
                  <span class="font-mono whitespace-nowrap">
                    {{ formatMoney(inv.amount, inv.currency) }}
                    <span v-if="inv.converted != null" class="text-neutral-400"> ≈ {{ formatMoney(inv.converted, s.currency) }}</span>
                  </span>
                </li>
              </ul>
              <button type="button" @click="confirmSuggestion(s)"
                class="cursor-pointer w-full h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">
                {{ t('bank.split_match', { count: s.count }) }}
              </button>
            </li>
          </ul>
        </div>

        <!-- Druhá možnost: ruční zadání VS -->
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.match_by_vs') }}</label>
        <div class="flex gap-2 mb-1">
          <input v-model="matchVarsymbol" type="text" inputmode="numeric"
            placeholder="2603001"
            @keyup.enter="confirmMatch"
            class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          <button @click="confirmMatch" :disabled="!matchVarsymbol.trim()"
            class="cursor-pointer px-4 h-10 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ t('bank.match') }}
          </button>
        </div>
        <p class="text-xs text-neutral-500 mb-4">{{ t('bank.vs_hint') }}</p>

        <div v-if="matchError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500 mb-3">
          {{ matchError }}
        </div>
        <div class="flex justify-end">
          <button @click="matchingTx = null" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
        </div>
      </div>
    </div>

    <!-- Vytvoření konceptu přijaté faktury z odchozí platby — výběr dodavatele -->
    <div v-if="createTx" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('bank.create_purchase_title') }}</h3>
        <p class="text-xs text-neutral-500 mb-3">
          {{ formatMoney(Math.abs(createTx.amount), createTx.currency ?? 'CZK') }} ·
          {{ formatDate(createTx.posted_at) }}
          <span v-if="createTx.counterparty_name"> · {{ createTx.counterparty_name }}</span>
        </p>
        <VendorPicker ref="vendorPickerRef" v-model="createVendorId" :on-create-new="() => { vendorModalOpen = true }" />
        <p class="text-xs text-neutral-500 mt-2 mb-4">{{ t('bank.create_purchase_hint') }}</p>
        <div class="flex justify-end gap-2">
          <button @click="createTx = null" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="submitCreatePurchase" :disabled="!createVendorId || creatingPi"
            class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ creatingPi ? '…' : t('bank.create_purchase_submit') }}
          </button>
        </div>
      </div>
    </div>

    <ClientFormModal v-if="vendorModalOpen"
      :defaults="{ is_vendor: true, is_customer: false, company_name: createTx?.counterparty_name || '' }"
      @created="onVendorCreated"
      @close="vendorModalOpen = false" />
  </div>
</template>

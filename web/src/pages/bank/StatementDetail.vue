<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, RouterLink, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { bankApi, type BankStatementDetail, type BankTransaction, type MatchCandidate } from '@/api/bank'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import VendorPicker from '@/components/purchase/VendorPicker.vue'
import ClientFormModal from '@/components/modals/ClientFormModal.vue'
import type { Client } from '@/api/clients'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const toast = useToast()
const router = useRouter()
const auth = useAuthStore()

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
const rematching = ref(false)
const matchingTx = ref<number | null>(null)
const matchCtx = ref<BankTransaction | null>(null)
const matchVarsymbol = ref<string>('')
const matchError = ref<string>('')
// Návrhy ke spárování dle částky ±14 dní (vydané i přijaté faktury).
const matchCandidates = ref<MatchCandidate[]>([])
const loadingCandidates = ref(false)

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
  loadingCandidates.value = true
  bankApi.matchCandidates(tx.id)
    .then(list => { if (matchingTx.value === tx.id) matchCandidates.value = list })
    .catch(() => {})
    .finally(() => { loadingCandidates.value = false })
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

async function ignoreTx(tx: BankTransaction) {
  if (!confirm(t('bank.ignore_confirm'))) return
  await bankApi.ignore(tx.id)
  await load()
}

async function unmatchTx(tx: BankTransaction) {
  if (!confirm(t('bank.unmatch_confirm'))) return
  try {
    await bankApi.unmatch(tx.id)
    await load()
  } catch (e: any) {
    alert(apiErrorMessage(e, t('bank.unmatch_failed')))
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
    <h1 class="text-2xl font-semibold mt-1">
      {{ t('bank.statement_title', { number: statement.statement_number, date: formatDate(statement.statement_date) }) }}
    </h1>
    <p class="text-sm text-neutral-500 mt-0.5 flex items-center gap-1.5 flex-wrap">
      <span>{{ t('bank.account') }}<span class="font-mono">{{ statement.account_number }}</span></span>
      <span v-if="statement.account_label" class="text-neutral-400">— {{ statement.account_label }}</span>
      <span v-if="statement.currency" class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-700 font-medium">{{ statement.currency }}</span>
      <span>· {{ statement.file_name }}</span>
    </p>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4 mb-4">
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
        <div class="text-lg font-mono text-danger-500">−{{ formatMoney(statement.debit_total, statement.currency ?? 'CZK') }}</div>
      </div>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
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
          <label v-if="auth.canWrite && !statement.has_pdf"
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
              <div v-if="tx.description" class="text-neutral-500 truncate max-w-xs">{{ tx.description }}</div>
            </td>
            <td class="px-3 py-2 text-xs">
              <RouterLink v-if="tx.matched_invoice_id" :to="`/invoices/${tx.matched_invoice_id}`"
                class="text-primary-600 hover:underline">
                {{ tx.matched_varsymbol || `#${tx.matched_invoice_id}` }}
              </RouterLink>
              <span v-else class="text-neutral-400">—</span>
              <div v-if="tx.matched_client_name" class="text-neutral-500 text-xs">{{ tx.matched_client_name }}</div>
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
                {{ t('bank.unmatch') }}
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
          <div v-if="tx.matched_invoice_id" class="text-xs">
            <RouterLink :to="`/invoices/${tx.matched_invoice_id}`"
              class="text-primary-600 hover:underline font-mono">
              {{ tx.matched_varsymbol || `#${tx.matched_invoice_id}` }}
            </RouterLink>
            <span v-if="tx.matched_client_name" class="text-neutral-500 ml-2">{{ tx.matched_client_name }}</span>
          </div>
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
              {{ t('bank.unmatch') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Manual match modal — návrhy dle částky + ruční VS jako druhá možnost -->
    <div v-if="matchingTx" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('bank.manual_match_title') }}</h3>
        <p v-if="matchCtx" class="text-xs text-neutral-500 mb-3 font-mono">
          {{ matchCtx.amount > 0 ? '+' : '' }}{{ formatMoney(matchCtx.amount, matchCtx.currency ?? statement.currency ?? 'CZK') }}
          · {{ formatDate(matchCtx.posted_at) }}
          <span v-if="matchCtx.counterparty_name" class="text-neutral-400"> · {{ matchCtx.counterparty_name }}</span>
        </p>

        <!-- Návrhy ke spárování dle částky (±14 dní) -->
        <div class="mb-4">
          <div class="text-sm font-medium text-neutral-700 mb-1.5">{{ t('bank.candidates_title') }}</div>
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

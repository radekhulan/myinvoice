<script setup lang="ts">
import { ref, onMounted, reactive, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type VatRate, type Country, type CurrencyAccount, type Unit } from '@/api/settings'
import { suppliersApi, type SupplierListItem, type SupplierCreatePayload } from '@/api/suppliers'
import { expenseCategoriesApi, type ExpenseCategory } from '@/api/expenseCategories'
import { vatClassificationsApi, type VatClassification } from '@/api/vatClassifications'
import { clientsApi } from '@/api/clients'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()
const supplierStore = useSupplierStore()
const auth = useAuthStore()

type Tab = 'suppliers' | 'currencies' | 'vat' | 'countries' | 'units' | 'expense_categories' | 'vat_classifications'
const tab = ref<Tab>('suppliers')

const currencies = ref<CurrencyAccount[]>([])
const vatRates   = ref<VatRate[]>([])
const countries  = ref<Country[]>([])
const units      = ref<Unit[]>([])
const suppliers  = ref<SupplierListItem[]>([])
const loading    = ref(false)

async function loadAll() {
  loading.value = true
  try {
    [suppliers.value, currencies.value, vatRates.value, countries.value, units.value] = await Promise.all([
      suppliersApi.list(),
      settingsApi.listCurrencies(),
      settingsApi.listVatRates(),
      settingsApi.listCountries(),
      settingsApi.listUnits(),
    ])
  } finally { loading.value = false }
}
onMounted(loadAll)

// ─── Suppliers (multi-tenant firmy) — embed jako první tab ───────────────
const supplierDraft = reactive<SupplierCreatePayload>({
  company_name: '', street: '', city: '', zip: '', email: '',
  country_iso2: 'CZ', ic: '', dic: '', is_vat_payer: true,
  default_payment_due_days: 14, default_hourly_rate: 1500,
})
const supplierCreateOpen = ref(false)
const supplierAresLoading = ref(false)
const supplierAresMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null)

function newSupplier() {
  Object.assign(supplierDraft, {
    company_name: '', street: '', city: '', zip: '', email: '',
    country_iso2: 'CZ', ic: '', dic: '', is_vat_payer: true,
    default_payment_due_days: 14, default_hourly_rate: 1500,
  })
  supplierAresMessage.value = null
  supplierCreateOpen.value = true
}

async function supplierLookupAres() {
  const ic = (supplierDraft.ic || '').trim()
  if (!/^\d{8}$/.test(ic)) {
    supplierAresMessage.value = { type: 'error', text: t('supplier.ares_invalid_ic') }
    return
  }
  supplierAresLoading.value = true
  supplierAresMessage.value = null
  try {
    const r = await clientsApi.lookupAres(ic)
    if (!r.found || !r.data) {
      supplierAresMessage.value = { type: 'error', text: t('supplier.ares_not_found') }
      return
    }
    const d = r.data
    supplierDraft.company_name = d.company_name || supplierDraft.company_name
    supplierDraft.street       = d.street       || supplierDraft.street
    supplierDraft.city         = d.city         || supplierDraft.city
    supplierDraft.zip          = d.zip          || supplierDraft.zip
    supplierDraft.country_iso2 = d.country_iso2 || supplierDraft.country_iso2 || 'CZ'
    supplierDraft.ic           = d.ic           || ic
    supplierDraft.dic          = d.dic          || supplierDraft.dic
    supplierDraft.is_vat_payer = d.is_vat_payer
    supplierAresMessage.value = { type: 'success', text: t('supplier.ares_loaded', { name: d.company_name }) }
  } catch (e: any) {
    supplierAresMessage.value = { type: 'error', text: e?.response?.data?.error?.message || t('supplier.ares_failed') }
  } finally {
    supplierAresLoading.value = false
  }
}

async function saveSupplier() {
  if (!supplierDraft.company_name || !supplierDraft.street || !supplierDraft.city || !supplierDraft.zip || !supplierDraft.email) {
    toast.error(t('common.error'))
    return
  }
  try {
    await suppliersApi.create(supplierDraft)
    supplierCreateOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
    await auth.refresh()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeSupplier(s: SupplierListItem) {
  if (s.clients_count > 0 || s.invoices_count > 0) return
  if (!confirm(t('supplier.delete_confirm'))) return
  try {
    await suppliersApi.delete(s.id)
    toast.success(t('common.deleted'))
    await loadAll()
    await auth.refresh()
    if (supplierStore.currentSupplierId === s.id) {
      const first = suppliers.value[0]
      if (first) supplierStore.setSupplier(first.id)
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function switchSupplier(id: number) {
  if (id === supplierStore.currentSupplierId) return
  supplierStore.setSupplier(id)
  window.location.reload()
}

// ─── Currencies ───────────────────────────────────────────
const currencyDraft = reactive<Partial<CurrencyAccount> & { _new?: boolean }>({})
const currencyOpen = ref(false)
function newCurrency() {
  Object.assign(currencyDraft, {
    id: undefined, code: '', label: '', symbol: '', name_cs: '', name_en: '',
    decimals: 2, is_active: true, is_default: false,
    account_number: null, bank_code: null, bank_name: null, iban: null, bic: null,
    _new: true,
  })
  currencyOpen.value = true
}
function editCurrency(c: CurrencyAccount) {
  Object.assign(currencyDraft, { ...c, _new: false })
  currencyOpen.value = true
}
async function saveCurrency() {
  try {
    if (currencyDraft._new) {
      await settingsApi.createCurrency({
        code: currencyDraft.code, label: currencyDraft.label, symbol: currencyDraft.symbol,
        name_cs: currencyDraft.name_cs, name_en: currencyDraft.name_en,
        decimals: currencyDraft.decimals, is_active: currencyDraft.is_active, is_default: currencyDraft.is_default,
        account_number: currencyDraft.account_number || null,
        bank_code: currencyDraft.bank_code || null,
        bank_name: currencyDraft.bank_name || null,
        iban: currencyDraft.iban || null,
        bic: currencyDraft.bic || null,
      })
    } else if (currencyDraft.id) {
      await settingsApi.updateCurrency(currencyDraft.id, {
        label: currencyDraft.label,
        is_active: currencyDraft.is_active,
        is_default: currencyDraft.is_default,
        account_number: currencyDraft.account_number || null,
        bank_code: currencyDraft.bank_code || null,
        bank_name: currencyDraft.bank_name || null,
        iban: currencyDraft.iban || null,
        bic: currencyDraft.bic || null,
      })
    }
    currencyOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteCurrency(c: CurrencyAccount) {
  if (!confirm(`Smazat ${c.label}?`)) return
  try {
    await settingsApi.deleteCurrency(c.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── VAT rates ────────────────────────────────────────────
const vatDraft = reactive<Partial<VatRate> & { _new?: boolean }>({})
const vatOpen = ref(false)

// Platná sazba = dnešek spadá do intervalu valid_from..valid_to
function isVatValid(v: VatRate): boolean {
  const today = new Date().toISOString().slice(0, 10)
  if (v.valid_from && v.valid_from > today) return false
  if (v.valid_to && v.valid_to < today) return false
  return true
}
// Nejdřív platné sazby, pak ostatní (stabilní řazení v rámci skupin)
const sortedVatRates = computed(() =>
  [...vatRates.value].sort((a, b) => (isVatValid(a) ? 0 : 1) - (isVatValid(b) ? 0 : 1))
)
function newVat() {
  Object.assign(vatDraft, {
    id: undefined, code: '', rate_percent: 21, country: 'CZ',
    label_cs: '', label_en: '', is_default: false, is_reverse_charge: false,
    valid_from: new Date().toISOString().slice(0, 10), valid_to: null, _new: true,
  })
  vatOpen.value = true
}
function editVat(v: VatRate) {
  Object.assign(vatDraft, { ...v, _new: false })
  vatOpen.value = true
}
async function saveVat() {
  try {
    if (vatDraft._new) await settingsApi.createVatRate(vatDraft)
    else if (vatDraft.id) await settingsApi.updateVatRate(vatDraft.id, vatDraft)
    vatOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteVat(v: VatRate) {
  if (!confirm(`Smazat sazbu ${v.code} (${v.rate_percent} %)?`)) return
  try {
    await settingsApi.deleteVatRate(v.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── Countries ────────────────────────────────────────────
const countryDraft = reactive<Partial<Country> & { _new?: boolean }>({})
const countryOpen = ref(false)

useHotkey('escape', () => {
  if (currencyOpen.value) currencyOpen.value = false
  else if (vatOpen.value) vatOpen.value = false
  else if (countryOpen.value) countryOpen.value = false
  else if (unitOpen.value) unitOpen.value = false
})

// ─── Units ─────────────────────────────────────────────────
const unitDraft = reactive<Partial<Unit> & { _new?: boolean }>({})
const unitOpen = ref(false)
function newUnit() {
  Object.assign(unitDraft, {
    id: undefined, code: '', label_cs: '', label_en: '',
    is_default: false, display_order: 0, _new: true,
  })
  unitOpen.value = true
}
function editUnit(u: Unit) {
  Object.assign(unitDraft, { ...u, _new: false })
  unitOpen.value = true
}
async function saveUnit() {
  try {
    if (unitDraft._new) await settingsApi.createUnit(unitDraft)
    else if (unitDraft.id) await settingsApi.updateUnit(unitDraft.id, unitDraft)
    unitOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteUnit(u: Unit) {
  if (!confirm(`Smazat jednotku ${u.code}?`)) return
  try {
    await settingsApi.deleteUnit(u.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
function newCountry() {
  Object.assign(countryDraft, { id: undefined, iso2: '', iso3: '', name_cs: '', name_en: '', is_eu: false, _new: true })
  countryOpen.value = true
}
function editCountry(c: Country) {
  Object.assign(countryDraft, { ...c, _new: false })
  countryOpen.value = true
}
async function saveCountry() {
  try {
    if (countryDraft._new) await settingsApi.createCountry(countryDraft)
    else if (countryDraft.id) await settingsApi.updateCountry(countryDraft.id, countryDraft)
    countryOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteCountry(c: Country) {
  if (!confirm(`Smazat zemi ${c.iso2} – ${c.name_cs}?`)) return
  try {
    await settingsApi.deleteCountry(c.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── Expense categories (kategorie nákladů pro CRM rozpad) ─────────────
const expenseCategories = ref<ExpenseCategory[]>([])
const expenseDraft = reactive({
  id: 0,
  code: '',
  label: '',
  fixed_or_var: 'variable' as 'fixed' | 'variable',
  display_order: 0,
  archived: false,
})
const expenseOpen = ref(false)

async function loadExpenseCategories() {
  expenseCategories.value = await expenseCategoriesApi.list(true)
}

function newExpense() {
  Object.assign(expenseDraft, { id: 0, code: '', label: '', fixed_or_var: 'variable', display_order: 0, archived: false })
  expenseOpen.value = true
}

function editExpense(c: ExpenseCategory) {
  Object.assign(expenseDraft, c)
  expenseOpen.value = true
}

async function saveExpense() {
  try {
    if (expenseDraft.id) {
      await expenseCategoriesApi.update(expenseDraft.id, {
        code: expenseDraft.code,
        label: expenseDraft.label,
        fixed_or_var: expenseDraft.fixed_or_var,
        display_order: expenseDraft.display_order,
        archived: expenseDraft.archived,
      })
    } else {
      await expenseCategoriesApi.create({
        code: expenseDraft.code,
        label: expenseDraft.label,
        fixed_or_var: expenseDraft.fixed_or_var,
        display_order: expenseDraft.display_order,
      })
    }
    expenseOpen.value = false
    toast.success(t('common.saved'))
    await loadExpenseCategories()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeExpense(c: ExpenseCategory) {
  if (!confirm(t('expense_categories.delete_confirm', { label: c.label }))) return
  try {
    const r = await expenseCategoriesApi.delete(c.id)
    toast.success(r.deleted ? t('common.deleted') : t('expense_categories.archived_due_to_usage'))
    await loadExpenseCategories()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── VAT classifications (kódy DPHDP3 + KH) ──────────────────────────
const vatClassifications = ref<VatClassification[]>([])
const vatClsDraft = reactive({
  id: 0,
  code: '',
  label: '',
  direction: 'both' as 'sale' | 'purchase' | 'both',
  dphdp3_line: '',
  kh_section: '',
  vat_rate: null as number | null,
  is_reverse_charge: false,
  display_order: 100,
  archived: false,
})
const vatClsOpen = ref(false)
const vatClsEditMode = ref<'create' | 'edit'>('create')

async function loadVatClassifications() {
  vatClassifications.value = await vatClassificationsApi.list(undefined, true)
}

function newVatCls() {
  Object.assign(vatClsDraft, { id: 0, code: '', label: '', direction: 'both',
    dphdp3_line: '', kh_section: '', vat_rate: null, is_reverse_charge: false,
    display_order: 100, archived: false })
  vatClsEditMode.value = 'create'
  vatClsOpen.value = true
}

function editVatCls(c: VatClassification) {
  Object.assign(vatClsDraft, {
    id: c.id, code: c.code, label: c.label, direction: c.direction,
    dphdp3_line: c.dphdp3_line || '', kh_section: c.kh_section || '',
    vat_rate: c.vat_rate, is_reverse_charge: c.is_reverse_charge,
    display_order: c.display_order, archived: c.archived,
  })
  vatClsEditMode.value = 'edit'
  vatClsOpen.value = true
}

async function saveVatCls() {
  try {
    if (vatClsEditMode.value === 'edit') {
      await vatClassificationsApi.update(vatClsDraft.id, {
        label: vatClsDraft.label,
        direction: vatClsDraft.direction,
        dphdp3_line: vatClsDraft.dphdp3_line || null,
        kh_section: vatClsDraft.kh_section || null,
        vat_rate: vatClsDraft.vat_rate,
        is_reverse_charge: vatClsDraft.is_reverse_charge,
        display_order: vatClsDraft.display_order,
        archived: vatClsDraft.archived,
      })
    } else {
      await vatClassificationsApi.create({
        code: vatClsDraft.code,
        label: vatClsDraft.label,
        direction: vatClsDraft.direction,
        dphdp3_line: vatClsDraft.dphdp3_line || null,
        kh_section: vatClsDraft.kh_section || null,
        vat_rate: vatClsDraft.vat_rate,
        is_reverse_charge: vatClsDraft.is_reverse_charge,
        display_order: vatClsDraft.display_order,
      })
    }
    vatClsOpen.value = false
    toast.success(t('common.saved'))
    await loadVatClassifications()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeVatCls(c: VatClassification) {
  if (c.supplier_id === null) {
    toast.error(t('vat_classifications.cannot_delete_global'))
    return
  }
  if (!confirm(t('vat_classifications.delete_confirm', { code: c.code }))) return
  try {
    await vatClassificationsApi.delete(c.id)
    toast.success(t('common.deleted'))
    await loadVatClassifications()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// Načti při přepnutí na tab=expense_categories / vat_classifications
watch(tab, (newTab) => {
  if (newTab === 'expense_categories') loadExpenseCategories()
  if (newTab === 'vat_classifications') loadVatClassifications()
})
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('codebooks.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('codebooks.subtitle') }}</p>
    </div>

    <!-- Tabs — Dodavatelé jako první volba (multi-tenant firmy embed do Codebooks) -->
    <div class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in (['suppliers', 'currencies', 'vat', 'vat_classifications', 'expense_categories', 'countries', 'units'] as const)" :key="tt"
        @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ tt === 'suppliers' ? t('nav.suppliers')
          : tt === 'currencies' ? t('codebooks.tab_currencies')
          : tt === 'vat' ? t('codebooks.tab_vat')
          : tt === 'vat_classifications' ? t('codebooks.tab_vat_classifications')
          : tt === 'expense_categories' ? t('codebooks.tab_expense_categories')
          : tt === 'countries' ? t('codebooks.tab_countries')
          : t('codebooks.tab_units') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <!-- ====== SUPPLIERS (multi-tenant firmy) ====== -->
    <section v-else-if="tab === 'suppliers'">
      <div class="flex justify-end mb-3">
        <button @click="newSupplier"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('supplier.new') }}
        </button>
      </div>

      <!-- Desktop tabulka -->
      <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 w-10"></th>
                <th class="px-3 py-2 text-left font-medium">{{ t('supplier.company_name') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('supplier.ic') }} / {{ t('supplier.dic') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('supplier.clients') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('supplier.invoices') }}</th>
                <th class="px-3 py-2 w-48"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="s in suppliers" :key="s.id" class="hover:bg-neutral-50">
                <td class="px-3 py-2 text-center">
                  <span v-if="s.id === supplierStore.currentSupplierId" class="text-primary-600 text-base" :title="t('supplier.active_label')">●</span>
                </td>
                <td class="px-3 py-2">
                  <div class="font-medium text-neutral-900">{{ s.company_name }}</div>
                  <div v-if="s.display_name && s.display_name !== s.company_name" class="text-xs text-neutral-500">{{ s.display_name }}</div>
                </td>
                <td class="px-3 py-2 font-mono text-xs">
                  <span v-if="s.ic">{{ s.ic }}</span>
                  <span v-if="s.ic && s.dic"> / </span>
                  <span v-if="s.dic">{{ s.dic }}</span>
                  <span v-if="!s.ic && !s.dic" class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-right font-mono">{{ s.clients_count }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ s.invoices_count }}</td>
                <td class="px-3 py-2 text-right text-xs">
                  <button v-if="s.id !== supplierStore.currentSupplierId" @click="switchSupplier(s.id)"
                    class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">
                    {{ t('supplier.switch') }}
                  </button>
                  <button @click="removeSupplier(s)" :disabled="s.clients_count > 0 || s.invoices_count > 0 || suppliers.length <= 1"
                    class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed">
                    {{ t('common.delete') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile karty -->
      <div class="md:hidden bg-surface border border-neutral-200 rounded-lg shadow-sm divide-y divide-neutral-100 overflow-hidden">
        <div v-for="s in suppliers" :key="`m-${s.id}`" class="px-4 py-3">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 flex items-center gap-1.5 min-w-0 truncate">
              <span v-if="s.id === supplierStore.currentSupplierId" class="text-primary-600 text-base shrink-0" :title="t('supplier.active_label')">●</span>
              {{ s.company_name }}
            </div>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <span class="font-mono">
              <span v-if="s.ic">{{ s.ic }}</span>
              <span v-if="s.ic && s.dic"> / </span>
              <span v-if="s.dic">{{ s.dic }}</span>
              <span v-if="!s.ic && !s.dic" class="text-neutral-400">—</span>
            </span>
            <span class="font-mono">{{ t('supplier.clients') }}: {{ s.clients_count }} · {{ t('supplier.invoices') }}: {{ s.invoices_count }}</span>
          </div>
          <div class="flex gap-3 mt-2 text-xs">
            <button v-if="s.id !== supplierStore.currentSupplierId" @click="switchSupplier(s.id)"
              class="cursor-pointer text-primary-600 hover:text-primary-700">{{ t('supplier.switch') }}</button>
            <button @click="removeSupplier(s)" :disabled="s.clients_count > 0 || s.invoices_count > 0 || suppliers.length <= 1"
              class="cursor-pointer ml-auto text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed">
              {{ t('common.delete') }}
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- ====== CURRENCIES ====== -->
    <section v-else-if="tab === 'currencies'">
      <div class="flex justify-end mb-3">
        <button @click="newCurrency"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('codebooks.new_currency') }}
        </button>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.account_label') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.decimals') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.account_cz') }} / {{ t('settings.iban') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('common.default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('settings.active') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in currencies" :key="c.id">
              <td class="px-3 py-2 font-mono">{{ c.code }} <span class="text-xs text-neutral-500">{{ c.symbol }}</span></td>
              <td class="px-3 py-2 text-xs">{{ c.label }}</td>
              <td class="px-3 py-2 text-center font-mono">{{ c.decimals }}</td>
              <td class="px-3 py-2 font-mono text-xs">
                <span v-if="c.account_number">{{ c.account_number }}<span v-if="c.bank_code"> / {{ c.bank_code }}</span></span>
                <span v-else-if="c.iban">{{ c.iban }}</span>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-3 py-2 text-center">
                <span v-if="c.is_default" class="text-primary-600">✓</span>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-3 py-2 text-center">
                <span v-if="c.is_active" class="text-success-600">✓</span>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editCurrency(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">{{ t('common.edit') }}</button>
                <button @click="deleteCurrency(c)" :disabled="(c.invoices_count ?? 0) > 0"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="(c.invoices_count ?? 0) > 0 ? t('codebooks.in_use_currency', { n: c.invoices_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="c in currencies" :key="`m-${c.id}`" class="p-3 space-y-1.5">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2">
                <span class="font-mono font-semibold">{{ c.code }}</span>
                <span class="text-xs text-neutral-500">{{ c.symbol }}</span>
                <span class="text-xs text-neutral-500">·</span>
                <span class="text-xs text-neutral-500">{{ c.label }}</span>
              </div>
              <span class="font-mono text-xs text-neutral-500">{{ c.decimals }}d</span>
            </div>
            <div class="font-mono text-xs text-neutral-600 truncate">
              <span v-if="c.account_number">{{ c.account_number }}<span v-if="c.bank_code"> / {{ c.bank_code }}</span></span>
              <span v-else-if="c.iban">{{ c.iban }}</span>
              <span v-else class="text-neutral-400">—</span>
            </div>
            <div class="flex items-center justify-between gap-2 text-xs">
              <span>
                <span v-if="c.is_default" class="text-primary-600">✓ {{ t('common.default') }}</span>
                <span v-if="c.is_default && c.is_active" class="text-neutral-400 mx-1.5">·</span>
                <span v-if="c.is_active" class="text-success-600">✓ {{ t('settings.active') }}</span>
              </span>
              <div class="flex gap-2">
                <button @click="editCurrency(c)" class="cursor-pointer h-8 px-3 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded">{{ t('common.edit') }}</button>
                <button @click="deleteCurrency(c)" :disabled="(c.invoices_count ?? 0) > 0"
                  class="cursor-pointer h-8 px-3 text-xs border border-danger-500/40 text-danger-500 hover:bg-danger-50 disabled:opacity-30 disabled:cursor-not-allowed rounded"
                  :title="(c.invoices_count ?? 0) > 0 ? t('codebooks.in_use_currency', { n: c.invoices_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ====== VAT RATES ====== -->
    <section v-else-if="tab === 'vat'">
      <div class="flex justify-end mb-3">
        <button @click="newVat"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('codebooks.new_vat') }}
        </button>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.country') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.code') }}</th>
              <th class="px-3 py-2 text-right font-medium">%</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_cs') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_reverse_charge') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.valid') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="v in sortedVatRates" :key="v.id" :class="isVatValid(v) ? 'font-semibold' : 'text-neutral-400'">
              <td class="px-3 py-2 text-center font-mono">{{ v.country }}</td>
              <td class="px-3 py-2 font-mono text-xs">{{ v.code }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ v.rate_percent }} %</td>
              <td class="px-3 py-2">{{ v.label_cs }}</td>
              <td class="px-3 py-2 text-center"><span v-if="v.is_default" class="text-primary-600">✓</span></td>
              <td class="px-3 py-2 text-center"><span v-if="v.is_reverse_charge" class="text-warning-600">⇄</span></td>
              <td class="px-3 py-2 text-xs text-neutral-500">{{ v.valid_from }}<span v-if="v.valid_to"> – {{ v.valid_to }}</span></td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editVat(v)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">{{ t('common.edit') }}</button>
                <button @click="deleteVat(v)" :disabled="(v.items_count ?? 0) > 0"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="(v.items_count ?? 0) > 0 ? t('codebooks.in_use_vat', { n: v.items_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="v in sortedVatRates" :key="`m-${v.id}`" class="p-3 space-y-1.5" :class="{ 'opacity-50': !isVatValid(v) }">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2">
                <span class="font-mono text-xs">{{ v.country }}</span>
                <span class="font-mono text-sm font-semibold">{{ v.code }}</span>
                <span class="text-sm text-neutral-700">{{ v.label_cs }}</span>
              </div>
              <span class="font-mono font-semibold">{{ v.rate_percent }} %</span>
            </div>
            <div class="flex items-center justify-between gap-2 text-xs">
              <span class="text-neutral-500">
                <span v-if="v.is_default" class="text-primary-600">✓ {{ t('codebooks.is_default') }}</span>
                <span v-if="v.is_default && v.is_reverse_charge" class="text-neutral-400 mx-1.5">·</span>
                <span v-if="v.is_reverse_charge" class="text-warning-600">⇄ RC</span>
              </span>
              <span class="text-neutral-500">{{ v.valid_from }}<span v-if="v.valid_to"> – {{ v.valid_to }}</span></span>
            </div>
            <div class="flex justify-end gap-2">
              <button @click="editVat(v)" class="cursor-pointer h-8 px-3 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded">{{ t('common.edit') }}</button>
              <button @click="deleteVat(v)" :disabled="(v.items_count ?? 0) > 0"
                class="cursor-pointer h-8 px-3 text-xs border border-danger-500/40 text-danger-500 hover:bg-danger-50 disabled:opacity-30 disabled:cursor-not-allowed rounded"
                :title="(v.items_count ?? 0) > 0 ? t('codebooks.in_use_vat', { n: v.items_count }) : t('common.delete')">
                {{ t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ====== COUNTRIES ====== -->
    <section v-else-if="tab === 'countries'">
      <div class="flex justify-end mb-3">
        <button @click="newCountry"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('codebooks.new_country') }}
        </button>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.iso2') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.iso3') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_cs') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_en') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_eu') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in countries" :key="c.id">
              <td class="px-3 py-2 text-center font-mono">{{ c.iso2 }}</td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ c.iso3 }}</td>
              <td class="px-3 py-2">{{ c.name_cs }}</td>
              <td class="px-3 py-2 text-neutral-500">{{ c.name_en }}</td>
              <td class="px-3 py-2 text-center"><span v-if="c.is_eu" class="text-primary-600">EU</span></td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editCountry(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">{{ t('common.edit') }}</button>
                <button @click="deleteCountry(c)" :disabled="(c.uses_count ?? 0) > 0"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="(c.uses_count ?? 0) > 0 ? t('codebooks.in_use_country', { n: c.uses_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="c in countries" :key="`m-${c.id}`" class="p-3 space-y-1.5">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2">
                <span class="font-mono font-semibold">{{ c.iso2 }}</span>
                <span class="font-mono text-xs text-neutral-500">{{ c.iso3 }}</span>
                <span class="text-sm">{{ c.name_cs }}</span>
              </div>
              <span v-if="c.is_eu" class="text-xs px-2 py-0.5 rounded bg-primary-100 text-primary-700">EU</span>
            </div>
            <div class="flex items-center justify-between gap-2">
              <span class="text-xs text-neutral-500 truncate">{{ c.name_en }}</span>
              <div class="flex gap-2">
                <button @click="editCountry(c)" class="cursor-pointer h-8 px-3 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded">{{ t('common.edit') }}</button>
                <button @click="deleteCountry(c)" :disabled="(c.uses_count ?? 0) > 0"
                  class="cursor-pointer h-8 px-3 text-xs border border-danger-500/40 text-danger-500 hover:bg-danger-50 disabled:opacity-30 disabled:cursor-not-allowed rounded"
                  :title="(c.uses_count ?? 0) > 0 ? t('codebooks.in_use_country', { n: c.uses_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ====== UNITS ====== -->
    <section v-else-if="tab === 'units'">
      <div class="flex justify-end mb-3">
        <button @click="newUnit"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('codebooks.new_unit') }}
        </button>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_cs') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_en') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.display_order') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="u in units" :key="u.id">
              <td class="px-3 py-2 font-mono">{{ u.code }}</td>
              <td class="px-3 py-2">{{ u.label_cs }}</td>
              <td class="px-3 py-2 text-neutral-500">{{ u.label_en }}</td>
              <td class="px-3 py-2 text-center"><span v-if="u.is_default" class="text-primary-600">✓</span></td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ u.display_order }}</td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editUnit(u)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">{{ t('common.edit') }}</button>
                <button @click="deleteUnit(u)" :disabled="(u.items_count ?? 0) > 0"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="(u.items_count ?? 0) > 0 ? t('codebooks.in_use_unit', { n: u.items_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="u in units" :key="`m-${u.id}`" class="p-3 space-y-1.5">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2">
                <span class="font-mono font-semibold">{{ u.code }}</span>
                <span class="text-sm text-neutral-700">{{ u.label_cs }}</span>
                <span class="text-xs text-neutral-500">· {{ u.label_en }}</span>
              </div>
              <span v-if="u.is_default" class="text-primary-600 text-xs">✓ {{ t('codebooks.is_default') }}</span>
            </div>
            <div class="flex justify-end gap-2">
              <button @click="editUnit(u)" class="cursor-pointer h-8 px-3 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded">{{ t('common.edit') }}</button>
              <button @click="deleteUnit(u)" :disabled="(u.items_count ?? 0) > 0"
                class="cursor-pointer h-8 px-3 text-xs border border-danger-500/40 text-danger-500 hover:bg-danger-50 disabled:opacity-30 disabled:cursor-not-allowed rounded"
                :title="(u.items_count ?? 0) > 0 ? t('codebooks.in_use_unit', { n: u.items_count }) : t('common.delete')">
                {{ t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ====== EXPENSE CATEGORIES ====== -->
    <section v-else-if="tab === 'expense_categories'">
      <div class="flex justify-between mb-3 gap-2">
        <p class="text-sm text-neutral-500">{{ t('expense_categories.hint') }}</p>
        <button @click="newExpense"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('expense_categories.new') }}
        </button>
      </div>

      <div v-if="expenseCategories.length === 0" class="bg-surface border border-dashed border-neutral-300 rounded-lg p-8 text-center text-sm text-neutral-500">
        {{ t('expense_categories.empty') }}
      </div>

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-24">{{ t('expense_categories.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('expense_categories.label') }}</th>
              <th class="px-3 py-2 text-center font-medium w-24">{{ t('expense_categories.fixed_or_var') }}</th>
              <th class="px-3 py-2 text-right font-medium w-24">{{ t('expense_categories.usage') }}</th>
              <th class="px-3 py-2 w-40"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in expenseCategories" :key="c.id" :class="['hover:bg-neutral-50', c.archived ? 'opacity-50' : '']">
              <td class="px-3 py-2 font-mono text-xs">{{ c.code }}</td>
              <td class="px-3 py-2">
                {{ c.label }}
                <span v-if="c.archived" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('expense_categories.archived') }}</span>
              </td>
              <td class="px-3 py-2 text-center text-xs">
                <span :class="c.fixed_or_var === 'fixed' ? 'text-primary-700' : 'text-warning-600'">
                  {{ c.fixed_or_var === 'fixed' ? t('expense_categories.fixed') : t('expense_categories.variable') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right font-mono text-xs text-neutral-600">{{ c.purchases_count || 0 }}</td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editExpense(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">
                  {{ t('common.edit') }}
                </button>
                <button @click="removeExpense(c)" class="cursor-pointer text-danger-500 hover:text-danger-600">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ====== VAT CLASSIFICATIONS ====== -->
    <section v-else-if="tab === 'vat_classifications'">
      <div class="flex justify-between mb-3 gap-2">
        <p class="text-sm text-neutral-500">{{ t('vat_classifications.hint') }}</p>
        <button @click="newVatCls"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('vat_classifications.new') }}
        </button>
      </div>

      <div v-if="vatClassifications.length === 0" class="bg-surface border border-dashed border-neutral-300 rounded-lg p-8 text-center text-sm text-neutral-500">
        {{ t('vat_classifications.empty') }}
      </div>

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-20">{{ t('vat_classifications.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('vat_classifications.label') }}</th>
              <th class="px-3 py-2 text-center font-medium w-24">{{ t('vat_classifications.direction') }}</th>
              <th class="px-3 py-2 text-center font-medium w-20">{{ t('vat_classifications.dphdp3_line') }}</th>
              <th class="px-3 py-2 text-center font-medium w-20">{{ t('vat_classifications.kh_section') }}</th>
              <th class="px-3 py-2 text-right font-medium w-16">{{ t('vat_classifications.vat_rate') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in vatClassifications" :key="c.id" :class="['hover:bg-neutral-50', c.archived ? 'opacity-50' : '']">
              <td class="px-3 py-2 font-mono text-xs font-medium">
                {{ c.code }}
                <span v-if="c.is_reverse_charge" class="ml-1 text-xs px-1 py-0.5 rounded bg-warning-50 text-warning-600">RC</span>
              </td>
              <td class="px-3 py-2 text-xs">
                {{ c.label.length > 80 ? c.label.slice(0, 80) + '…' : c.label }}
                <span v-if="c.supplier_id === null" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700">{{ t('vat_classifications.global') }}</span>
                <span v-if="c.archived" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('vat_classifications.archived') }}</span>
              </td>
              <td class="px-3 py-2 text-center text-xs">
                <span :class="c.direction === 'sale' ? 'text-success-600' : c.direction === 'purchase' ? 'text-warning-600' : 'text-neutral-600'">
                  {{ t('vat_classifications.direction_' + c.direction) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ c.dphdp3_line ?? '—' }}</td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ c.kh_section ?? '—' }}</td>
              <td class="px-3 py-2 text-right font-mono text-xs">{{ c.vat_rate !== null ? c.vat_rate.toFixed(0) + '%' : '—' }}</td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editVatCls(c)" :disabled="c.supplier_id === null"
                  :title="c.supplier_id === null ? t('vat_classifications.global_readonly') : t('common.edit')"
                  class="cursor-pointer text-primary-600 hover:text-primary-700 disabled:opacity-30 disabled:cursor-not-allowed mr-3">
                  {{ t('common.edit') }}
                </button>
                <button @click="removeVatCls(c)" :disabled="c.supplier_id === null"
                  :title="c.supplier_id === null ? t('vat_classifications.global_readonly') : t('common.delete')"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ====== Modals ====== -->

    <!-- VAT classification modal -->
    <div v-if="vatClsOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-xl w-full p-5">
        <h3 class="text-lg font-semibold mb-3">
          {{ vatClsEditMode === 'edit' ? t('vat_classifications.edit_title') : t('vat_classifications.new_title') }}
        </h3>
        <div class="space-y-3">
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.code') }} *</label>
              <input v-model="vatClsDraft.code" type="text" maxlength="8"
                :disabled="vatClsEditMode === 'edit'"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.direction') }}</label>
              <select v-model="vatClsDraft.direction" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="sale">{{ t('vat_classifications.direction_sale') }}</option>
                <option value="purchase">{{ t('vat_classifications.direction_purchase') }}</option>
                <option value="both">{{ t('vat_classifications.direction_both') }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.vat_rate') }}</label>
              <input v-model.number="vatClsDraft.vat_rate" type="number" step="0.1" placeholder="21"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.label') }} *</label>
            <input v-model="vatClsDraft.label" type="text" maxlength="150"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.dphdp3_line') }}</label>
              <input v-model="vatClsDraft.dphdp3_line" type="text" maxlength="10" placeholder="1"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.kh_section') }}</label>
              <input v-model="vatClsDraft.kh_section" type="text" maxlength="8" placeholder="A.4"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.display_order') }}</label>
              <input v-model.number="vatClsDraft.display_order" type="number" step="1"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="vatClsDraft.is_reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('vat_classifications.is_reverse_charge') }}
          </label>
          <label v-if="vatClsEditMode === 'edit'" class="flex items-center gap-2 text-sm">
            <input v-model="vatClsDraft.archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('vat_classifications.archive') }}
          </label>
        </div>
        <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
          <button @click="vatClsOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="saveVatCls" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>

    <!-- Expense category modal -->
    <div v-if="expenseOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">
          {{ expenseDraft.id ? t('expense_categories.edit_title') : t('expense_categories.new_title') }}
        </h3>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('expense_categories.code') }} *</label>
            <input v-model="expenseDraft.code" type="text" maxlength="20" placeholder="hosting"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('expense_categories.code_hint') }}</p>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('expense_categories.label') }} *</label>
            <input v-model="expenseDraft.label" type="text" maxlength="100" placeholder="Hosting a domény"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('expense_categories.fixed_or_var') }}</label>
            <select v-model="expenseDraft.fixed_or_var" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="variable">{{ t('expense_categories.variable') }}</option>
              <option value="fixed">{{ t('expense_categories.fixed') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('expense_categories.display_order') }}</label>
            <input v-model.number="expenseDraft.display_order" type="number" step="1"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <label v-if="expenseDraft.id" class="flex items-center gap-2 text-sm">
            <input v-model="expenseDraft.archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('expense_categories.archive') }}
          </label>
        </div>
        <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
          <button @click="expenseOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="saveExpense" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>

    <!-- Supplier create modal (multi-tenant firma) -->
    <div v-if="supplierCreateOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-xl w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('supplier.create_title') }}</h3>
        <p class="text-xs text-neutral-500 mb-4">{{ t('supplier.create_hint') }}</p>
        <form @submit.prevent="saveSupplier">
          <div class="space-y-3">
            <div class="bg-primary-50/50 border border-primary-200 rounded-md p-3">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.ares_lookup') }}</label>
              <div class="flex gap-2">
                <input v-model="supplierDraft.ic" type="text" placeholder="12345678" maxlength="8"
                  @keydown.enter.prevent="supplierLookupAres"
                  class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                <button type="button" @click="supplierLookupAres" :disabled="supplierAresLoading"
                  class="cursor-pointer h-10 px-4 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md inline-flex items-center gap-1.5">
                  <svg v-if="!supplierAresLoading" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 1 1-14 0 7 7 0 0 1 14 0z"/></svg>
                  <span v-else>…</span>
                  {{ supplierAresLoading ? t('common.loading') : t('supplier.ares_load') }}
                </button>
              </div>
              <div v-if="supplierAresMessage" class="mt-2 text-xs px-2 py-1 rounded"
                :class="supplierAresMessage.type === 'success' ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-500'">
                {{ supplierAresMessage.text }}
              </div>
            </div>

            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.company_name') }} *</label>
              <input v-model="supplierDraft.company_name" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.dic') }}</label>
              <input v-model="supplierDraft.dic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.street') }} *</label>
              <input v-model="supplierDraft.street" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div class="grid grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.zip') }} *</label>
                <input v-model="supplierDraft.zip" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              </div>
              <div class="col-span-2">
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.city') }} *</label>
                <input v-model="supplierDraft.city" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.email') }} *</label>
              <input v-model="supplierDraft.email" type="email" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
            <button type="button" @click="supplierCreateOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button type="submit" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.create') }}</button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="currencyOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ currencyDraft._new ? t('codebooks.new_currency') : t('settings.edit_currency', { code: currencyDraft.code }) }}</h3>
        <div class="space-y-3">
          <div class="grid grid-cols-3 gap-3" v-if="currencyDraft._new">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.code') }} *</label>
              <input v-model="currencyDraft.code" type="text" maxlength="3" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" /></div>
            <div><label class="block text-sm font-medium mb-1">Symbol</label>
              <input v-model="currencyDraft.symbol" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.decimals') }}</label>
              <input v-model.number="currencyDraft.decimals" type="number" min="0" max="6" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
          </div>
          <div class="grid grid-cols-2 gap-3" v-if="currencyDraft._new">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
              <input v-model="currencyDraft.name_cs" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
              <input v-model="currencyDraft.name_en" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.account_label_required') }}</label>
            <input v-model="currencyDraft.label" type="text" placeholder="CZK — Fio Bank"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          <div><label class="block text-sm font-medium mb-1">{{ t('settings.currency_account_cz') }}</label>
            <input v-model="currencyDraft.account_number" type="text" placeholder="1000000005" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('settings.currency_bank_code') }}</label>
              <input v-model="currencyDraft.bank_code" type="text" placeholder="0100" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('settings.currency_bank_name') }}</label>
              <input v-model="currencyDraft.bank_name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <div><label class="block text-sm font-medium mb-1">{{ t('settings.iban') }}</label>
            <input v-model="currencyDraft.iban" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
          <div><label class="block text-sm font-medium mb-1">{{ t('settings.bic') }}</label>
            <input v-model="currencyDraft.bic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="currencyDraft.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('settings.active') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="currencyDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('codebooks.is_default_account_hint') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="currencyOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="saveCurrency" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="vatOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ vatDraft._new ? t('codebooks.new_vat') : vatDraft.code }}</h3>
        <div class="space-y-3">
          <div class="grid grid-cols-3 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.country') }}</label>
              <input v-model="vatDraft.country" type="text" maxlength="2" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.code') }} *</label>
              <input v-model="vatDraft.code" type="text" placeholder="STD" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
            <div><label class="block text-sm font-medium mb-1">% *</label>
              <input v-model.number="vatDraft.rate_percent" type="number" step="0.01" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
              <input v-model="vatDraft.label_cs" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
              <input v-model="vatDraft.label_en" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.valid_from') }}</label>
              <input v-model="vatDraft.valid_from" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.valid_to') }}</label>
              <input v-model="vatDraft.valid_to" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="vatDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" /> {{ t('codebooks.is_default_for_country') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="vatDraft.is_reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" /> {{ t('codebooks.is_reverse_charge_label') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="vatOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="saveVat" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="unitOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ unitDraft._new ? t('codebooks.new_unit') : unitDraft.code }}</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('codebooks.code') }} *</label>
            <input v-model="unitDraft.code" :disabled="!unitDraft._new" type="text" maxlength="20" placeholder="ks"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-50" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
              <input v-model="unitDraft.label_cs" type="text" placeholder="kus" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
              <input v-model="unitDraft.label_en" type="text" placeholder="piece" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('codebooks.display_order') }}</label>
            <input v-model.number="unitDraft.display_order" type="number" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="unitDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('codebooks.is_default_unit_hint') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="unitOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="saveUnit" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="countryOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ countryDraft._new ? t('codebooks.new_country') : countryDraft.iso2 }}</h3>
        <div class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.iso2') }} *</label>
              <input v-model="countryDraft.iso2" :disabled="!countryDraft._new" type="text" maxlength="2" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase disabled:bg-neutral-50" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.iso3') }}</label>
              <input v-model="countryDraft.iso3" type="text" maxlength="3" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" /></div>
          </div>
          <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
            <input v-model="countryDraft.name_cs" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
            <input v-model="countryDraft.name_en" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="countryDraft.is_eu" type="checkbox" class="rounded border-neutral-300 text-primary-600" /> {{ t('codebooks.is_eu_label') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="countryOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="saveCountry" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

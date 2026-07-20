<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { clientsApi, TAX_NUMBER_LABELS, type ClientPayload, type Client, type ClientEmailContact, type EmailContactUsageCode, type EmailContactRecipient } from '@/api/clients'
import { codebooksApi, type Country, type Currency } from '@/api/codebooks'
import { expenseCategoriesApi, type ExpenseCategory } from '@/api/expenseCategories'
import { revenueCategoriesApi, type RevenueCategory } from '@/api/revenueCategories'
import { useToast } from '@/composables/useToast'
import { useSupplierStore } from '@/stores/supplier'
import { settingsApi, type BrandingProfile } from '@/api/settings'

/**
 * V `embedded` módu komponenta nečte route, neredirektuje a vrací výsledek
 * přes `@created` event. Používá se v modal okně (InvoiceEditor, RecurringForm…).
 */
const props = withDefaults(defineProps<{ embedded?: boolean; defaults?: Partial<ClientPayload> }>(), {
  embedded: false,
  defaults: () => ({}),
})
const emit = defineEmits<{
  (e: 'created', client: Client): void
  (e: 'cancel'): void
}>()

const { t, locale } = useI18n()
const toast = useToast()
const supplierStore = useSupplierStore()
const brandingProfiles = ref<BrandingProfile[]>([])

const route = useRoute()
const router = useRouter()

const isEdit = computed(() =>
  !props.embedded && route.params.id !== undefined && route.params.id !== 'new'
)
const clientId = computed(() => (isEdit.value ? Number(route.params.id) : null))

// Splatnost — UI preset selector. 'inherit' = dědit supplier default; ostatní hodnoty
// zapíšou do form pevnou dvojici (payment_due_default, payment_due_unit). 'custom'
// odhalí číselný input pro libovolný počet dnů (zachová dosavadní hodnotu, nebo 30 default).
type ClientDuePreset = 'inherit' | '7' | '14' | 'month' | 'custom'
// 'custom' musí být „sticky" i když hodnota odpovídá presetu (7/14) — jinak by getter
// spadl zpět na preset a číselný input by se nikdy neukázal.
const dueCustom = ref(false)
const clientDuePreset = computed<ClientDuePreset>({
  get() {
    if (dueCustom.value) return 'custom'
    const d = form.value.payment_due_default
    const u = form.value.payment_due_unit
    if (d == null && u == null) return 'inherit'
    if (u === 'month' && d === 1) return 'month'
    if ((u === 'days' || u == null) && d === 7) return '7'
    if ((u === 'days' || u == null) && d === 14) return '14'
    return 'custom'
  },
  set(v: ClientDuePreset) {
    dueCustom.value = (v === 'custom')
    if (v === 'inherit') {
      form.value.payment_due_default = null
      form.value.payment_due_unit = null
    } else if (v === '7') {
      form.value.payment_due_default = 7
      form.value.payment_due_unit = 'days'
    } else if (v === '14') {
      form.value.payment_due_default = 14
      form.value.payment_due_unit = 'days'
    } else if (v === 'month') {
      form.value.payment_due_default = 1
      form.value.payment_due_unit = 'month'
    } else {
      if (form.value.payment_due_default == null) form.value.payment_due_default = 30
      form.value.payment_due_unit = 'days'
    }
  },
})

// Lidsky čitelná hodnota supplier defaultu pro „Použít výchozí (…)" option.
const supplierDueLabel = computed(() => {
  const sup = supplierStore.currentSupplier
  if (!sup) return t('client.payment_due_inherit_fallback')
  const d = sup.default_payment_due_days
  const u = sup.default_payment_due_unit
  if (u === 'month' && d === 1) return t('client.payment_due_preset_month').toLowerCase()
  if (u === 'days') return `${d} ${t('client.payment_due_custom_days_suffix')}`
  return `${d}× ${t('client.payment_due_preset_month').toLowerCase()}`
})

const form = ref<ClientPayload>({
  company_name: '',
  ic: null,
  dic: null,
  tax_number: null,
  street: '',
  city: '',
  zip: '',
  country_iso2: 'CZ',
  main_email: '',
  phone: null,
  language: 'cs',
  currency_default_id: 0,
  reverse_charge: false,
  is_vat_payer: true,
  // Default: customer. Override z ?role=vendor query (klik 'Nový dodavatel' v list).
  is_customer: route.query.role !== 'vendor',
  is_vendor: route.query.role === 'vendor',
  is_fuel_station: false,
  auto_send_reminders: true,
  payment_due_default: null,
  payment_due_unit: null,
  hourly_rate: 0,
  note: null,
  default_expense_category_id: null,
  default_revenue_category_id: null,
  invoice_number_format: null,
  proforma_number_format: null,
  credit_note_number_format: null,
  invoice_number_period: null,
  default_branding_profile_id: null,
})

// Pro lock UI — counts of issued/received invoices se hodí znát, aby user věděl
// proč nelze flag vypnout. Pro start: jen rely na backend error message.
const lockCustomer = ref(false)  // true pokud klient má vydané faktury (server enforces)
const lockVendor   = ref(false)  // true pokud má přijaté faktury

// ── E-mailové kontakty dle účelu (#86) ──────────────────────────────────────
// Replace-all model: pole se posílá celé; bez kontaktů platí dosavadní chování
// (vše na hlavní e-mail + e-maily zakázky). UI: per-kontakt checkboxy účelů
// + jedna role to/cc/bcc (datový model umí roli per účel, UI drží jednu na kontakt).
const USAGE_CODES: EmailContactUsageCode[] = ['documents', 'reminders', 'approvals', 'communication']
const MAX_EMAIL_CONTACTS = 10  // zrcadlí backend ClientEmailContactRepository::MAX_CONTACTS
const emailContacts = ref<ClientEmailContact[]>([])

function addEmailContact(prefillEmail = '') {
  if (emailContacts.value.length >= MAX_EMAIL_CONTACTS) return
  emailContacts.value.push({
    email: prefillEmail,
    label: null,
    contact_name: null,
    is_active: true,
    usages: [{ usage: 'documents', recipient: 'to' }],
  })
}
function removeEmailContact(idx: number) {
  emailContacts.value.splice(idx, 1)
}
function hasUsage(c: ClientEmailContact, code: EmailContactUsageCode): boolean {
  return c.usages.some(u => u.usage === code)
}
function toggleUsage(c: ClientEmailContact, code: EmailContactUsageCode) {
  if (hasUsage(c, code)) {
    c.usages = c.usages.filter(u => u.usage !== code)
  } else {
    c.usages.push({ usage: code, recipient: contactRecipient(c) })
  }
}
function contactRecipient(c: ClientEmailContact): EmailContactRecipient {
  return c.usages[0]?.recipient ?? 'to'
}
function setContactRecipient(c: ClientEmailContact, r: EmailContactRecipient) {
  c.usages = c.usages.map(u => ({ ...u, recipient: r }))
}

// Národní daňové číslo (#120): SK DIČ / DE+AT Steuernummer / PL NIP / HU Adószám.
// Pole se zobrazí jen pro země z TAX_NUMBER_LABELS; u SK se zároveň pole `dic`
// přejmenuje na „IČ DPH" (DIČ s prefixem SK = IČ DPH, samostatné DIČ je bez prefixu).
const taxNumberLabel = computed(() => TAX_NUMBER_LABELS[form.value.country_iso2] ?? null)
const isSkClient = computed(() => form.value.country_iso2 === 'SK')

const countries = ref<Country[]>([])
const currencies = ref<Currency[]>([])
const expenseCategories = ref<ExpenseCategory[]>([])
const revenueCategories = ref<RevenueCategory[]>([])
const submitting = ref(false)
const error = ref('')
const errors = ref<Record<string, string[]>>({})
const aresLoading = ref(false)
// Chyby lookupů patří k příslušnému poli, ne do globálního `error` u tlačítka
// Uložit dole na stránce — tam je uživatel u dlouhého formuláře nevidí (#120).
const aresError = ref('')
const viesLoading = ref(false)
const viesError = ref('')
const viesResult = ref<import('@/api/clients').ViesLookupResult | null>(null)
const duplicateIc = ref<{ id: number; name: string } | null>(null)
const duplicateDic = ref<{ id: number; name: string } | null>(null)

// Detaily plátce DPH — CZ DIČ jde do registru plátců DPH (CRPDPH/MFČR: účty + nespolehlivost),
// zahraniční EU DIČ (SK…) do VIES; CRPDPH je jen český registr a cizí DIČ by vždy
// vrátil nepravdivé „není evidován" (#120).
const vatInfoOpen = ref(false)
const vatInfoLoading = ref(false)
const vatInfo = ref<import('@/api/clients').BankLookupResult | null>(null)
const vatInfoVies = ref<import('@/api/clients').ViesLookupResult | null>(null)
const vatInfoError = ref('')

async function loadVatPayerDetails() {
  const raw = (form.value.dic || '').toUpperCase().replace(/[\s-]/g, '')
  if (!raw) return
  const prefix = raw.match(/^([A-Z]{2})\d/)?.[1] ?? null
  vatInfoOpen.value = true
  vatInfoLoading.value = true
  vatInfoError.value = ''
  vatInfo.value = null
  vatInfoVies.value = null
  try {
    if (prefix && prefix !== 'CZ') {
      vatInfoVies.value = await clientsApi.lookupVies(raw)
    } else {
      vatInfo.value = await clientsApi.lookupBank(raw.replace(/\D/g, ''))
    }
  } catch (e: any) {
    vatInfoError.value = e?.response?.data?.error?.message || t('client.vat_payer_details_failed')
  } finally {
    vatInfoLoading.value = false
  }
}

onMounted(async () => {
  const [c, cur, ec, rc, bp] = await Promise.all([
    codebooksApi.countries(),
    codebooksApi.currencies(),
    expenseCategoriesApi.list(false).catch(() => [] as ExpenseCategory[]),  // jen aktivní
    revenueCategoriesApi.list(false).catch(() => [] as RevenueCategory[]),  // jen aktivní
    settingsApi.listBrandingProfiles().catch(() => [] as BrandingProfile[]),
  ])
  countries.value = c
  currencies.value = cur
  expenseCategories.value = ec
  revenueCategories.value = rc
  brandingProfiles.value = bp.filter(p => p.is_active)
  if (form.value.currency_default_id === 0) {
    const def = cur.find(x => x.is_default && x.code === 'CZK') || cur[0]
    if (def) form.value.currency_default_id = def.id
  }
  if (isEdit.value && clientId.value) {
    const c = await clientsApi.get(clientId.value)
    Object.assign(form.value, sanitize(c))
    emailContacts.value = (c.email_contacts ?? []).map(ec => ({
      ...ec,
      usages: (ec.usages ?? []).map(u => ({ usage: u.usage, recipient: u.recipient ?? 'to' })),
    }))
    lockCustomer.value = (c.invoices_count ?? 0) > 0
    lockVendor.value   = (c.purchase_invoices_count ?? 0) > 0
  } else if (props.embedded && props.defaults) {
    Object.assign(form.value, props.defaults)
  }
})

// Při přepnutí mezi „Klient" (/clients/new) a „Dodavatel" (/clients/new?role=vendor)
// se komponenta recykluje (stejná route) → setup neproběhne znovu. Bez tohoto watcheru
// by zůstala role z prvního otevření. Reagujeme jen v režimu nového záznamu.
watch(() => route.query.role, (role) => {
  if (isEdit.value || props.embedded) return
  const vendor = role === 'vendor'
  form.value.is_vendor = vendor
  form.value.is_customer = !vendor
})

function sanitize(c: Client): Partial<ClientPayload> {
  return {
    company_name: c.company_name,
    first_name: c.first_name ?? null,
    last_name: c.last_name ?? null,
    ic: c.ic ?? null,
    dic: c.dic ?? null,
    tax_number: c.tax_number ?? null,
    street: c.street,
    city: c.city,
    zip: c.zip,
    country_iso2: c.country_iso2,
    main_email: c.main_email,
    phone: c.phone ?? null,
    language: c.language,
    currency_default_id: c.currency_default_id,
    reverse_charge: c.reverse_charge,
    is_vat_payer: c.is_vat_payer ?? true,
    is_customer: c.is_customer !== false,
    is_vendor:   c.is_vendor   === true,
    is_fuel_station: c.is_fuel_station === true,
    auto_send_reminders: c.auto_send_reminders ?? true,
    payment_due_default: c.payment_due_default ?? null,
    payment_due_unit: c.payment_due_unit ?? null,
    hourly_rate: c.hourly_rate ?? 0,
    note: c.note ?? null,
    default_expense_category_id: c.default_expense_category_id ?? null,
    default_revenue_category_id: c.default_revenue_category_id ?? null,
    invoice_number_format: c.invoice_number_format ?? null,
    proforma_number_format: c.proforma_number_format ?? null,
    credit_note_number_format: c.credit_note_number_format ?? null,
    invoice_number_period: c.invoice_number_period ?? null,
    default_branding_profile_id: c.default_branding_profile_id ?? null,
  }
}

async function loadFromAres() {
  if (!form.value.ic) return
  aresLoading.value = true
  aresError.value = ''
  try {
    const result = await clientsApi.lookupAres(form.value.ic)
    if (!result.found || !result.data) {
      aresError.value = t('supplier.ares_not_found')
      return
    }
    const d = result.data
    form.value.company_name = d.company_name
    form.value.dic = d.dic || null
    form.value.street = d.street
    form.value.city = d.city
    form.value.zip = d.zip
    form.value.country_iso2 = d.country_iso2 || 'CZ'
    form.value.is_vat_payer = d.is_vat_payer  // ARES: stav registrace DPH (autoritativní pro CZ)
    checkDuplicateIc()
    checkDuplicateDic()
  } catch (e: any) {
    aresError.value = e?.response?.data?.error?.message || t('supplier.ares_failed')
  } finally {
    aresLoading.value = false
  }
}

async function checkDuplicateIc() {
  if (isEdit.value) return
  const ic = (form.value.ic || '').trim()
  if (!ic) { duplicateIc.value = null; return }
  try {
    const res = await clientsApi.list({ q: ic, per_page: 5 })
    const match = res.data.find(c => (c.ic || '').trim() === ic)
    duplicateIc.value = match ? { id: match.id, name: match.company_name } : null
  } catch { /* tichý fail — jen pomocná hláška */ }
}

async function checkDuplicateDic() {
  if (isEdit.value) return
  const dic = (form.value.dic || '').trim()
  if (!dic) { duplicateDic.value = null; return }
  try {
    const res = await clientsApi.list({ q: dic, per_page: 5 })
    const match = res.data.find(c => (c.dic || '').trim() === dic)
    duplicateDic.value = match ? { id: match.id, name: match.company_name } : null
  } catch { /* tichý fail — jen pomocná hláška */ }
}

async function checkVies() {
  if (!form.value.dic) return
  viesLoading.value = true
  viesError.value = ''
  viesResult.value = null
  try {
    const result = await clientsApi.lookupVies(form.value.dic)
    viesResult.value = result
    // VIES: platné DIČ = registrovaný plátce DPH (autoritativní pro zahraniční EU).
    if (result.source !== 'error') form.value.is_vat_payer = result.valid
    if (result.valid) {
      // SK: DIČ = IČ DPH bez prefixu (#120) — předvyplnit, pokud ještě není.
      const vat = (result.vat_number || form.value.dic || '').toUpperCase().replace(/[\s-]/g, '')
      if (vat.startsWith('SK') && !form.value.tax_number) {
        form.value.tax_number = vat.slice(2)
      }
      if (result.name && !form.value.company_name) {
        form.value.company_name = result.name
      }
      if (result.country && !form.value.street) {
        form.value.country_iso2 = result.country
      }
      if (result.parsed && !form.value.street) {
        form.value.street = result.parsed.street
        form.value.city = result.parsed.city
        form.value.zip = result.parsed.zip
      }
    }
  } catch (e: any) {
    viesError.value = e?.response?.data?.error?.message || t('client.vies_lookup_failed')
  } finally {
    viesLoading.value = false
  }
}

async function submit() {
  submitting.value = true
  error.value = ''
  errors.value = {}
  // Kontakty: vyřaď řádky bez e-mailu (rozpracované), pošli celé pole (replace-all).
  const payload: ClientPayload = {
    ...form.value,
    email_contacts: emailContacts.value
      .filter(c => (c.email || '').trim() !== '')
      .map(c => ({
        email: c.email.trim(),
        label: c.label || null,
        contact_name: c.contact_name || null,
        is_active: c.is_active,
        usages: c.usages,
      })),
  }
  try {
    if (isEdit.value && clientId.value) {
      const updated = await clientsApi.update(clientId.value, payload)
      const backfilled = updated.expense_category_backfilled ?? 0
      if (backfilled > 0) {
        toast.success(t('client.default_expense_category_backfilled', { count: backfilled }))
      }
      const revBackfilled = updated.revenue_category_backfilled ?? 0
      if (revBackfilled > 0) {
        toast.success(t('client.default_revenue_category_backfilled', { count: revBackfilled }))
      }
      if (props.embedded) { emit('created', updated); return }
      router.push(`/clients/${clientId.value}`)
    } else {
      const created = await clientsApi.create(payload)
      if (props.embedded) { emit('created', created); return }
      router.push(`/clients/${created.id}`)
    }
  } catch (e: any) {
    const data = e?.response?.data?.error
    error.value = data?.message || t('errors.generic')
    if (data?.fields) errors.value = data.fields
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div :class="embedded ? '' : 'max-w-3xl'">
    <div v-if="!embedded" class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">
        {{ isEdit ? t('client.edit_title')
          : (route.query.role === 'vendor' ? t('purchase_invoice.new_vendor') : t('client.new_title')) }}
      </h1>
      <RouterLink to="/clients" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('client.back_to_list') }}</RouterLink>
    </div>

    <form @submit.prevent="submit" autocomplete="off" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <div class="p-5 space-y-4">
        <!-- Lookup helpers -->
        <div class="bg-primary-50 border border-primary-200 rounded-md p-3">
          <div class="text-xs font-semibold text-primary-800 mb-2">{{ t('client.lookup_in_registries') }}</div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('client.ic') }}</label>
              <div class="flex gap-2">
                <input autocomplete="off" v-model="form.ic" maxlength="8" placeholder="12345678"
                  @blur="checkDuplicateIc"
                  class="flex-1 h-9 px-3 border border-neutral-300 rounded-md font-mono text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                <button type="button" @click="loadFromAres" :disabled="!form.ic || aresLoading"
                  class="px-3 h-9 text-sm bg-surface border border-primary-300 text-primary-700 rounded-md hover:bg-primary-100 disabled:opacity-50">
                  {{ aresLoading ? '…' : 'ARES' }}
                </button>
              </div>
              <!-- Chyba ARES lookupu přímo u pole (#120) — ne v globálním erroru dole -->
              <p v-if="aresError" class="text-xs text-danger-500 mt-1">✗ {{ aresError }}</p>
              <p v-if="duplicateIc" class="text-xs text-warning-600 mt-1">
                ⚠ {{ t('client.duplicate_ic') }} <strong>{{ duplicateIc.name }}</strong>
                <RouterLink :to="`/clients/${duplicateIc.id}`" class="text-primary-700 hover:underline ml-1">{{ t('client.open_existing') }} →</RouterLink>
              </p>
            </div>
            <div>
              <!-- U SK klienta nese pole `dic` IČ DPH (s prefixem SK) — label dle země (#120) -->
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ isSkClient ? t('client.ic_dph') : t('client.dic') }}</label>
              <div class="flex gap-2">
                <input autocomplete="off" v-model="form.dic" :placeholder="isSkClient ? 'SK2022638992' : 'CZ12345678'"
                  @blur="checkDuplicateDic"
                  class="flex-1 h-9 px-3 border border-neutral-300 rounded-md font-mono text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                <button type="button" @click="checkVies" :disabled="!form.dic || viesLoading"
                  class="px-3 h-9 text-sm bg-surface border border-primary-300 text-primary-700 rounded-md hover:bg-primary-100 disabled:opacity-50">
                  {{ viesLoading ? '…' : 'VIES' }}
                </button>
              </div>
              <!-- Chyba VIES lookupu přímo u pole (#120) -->
              <p v-if="viesError" class="text-xs text-danger-500 mt-1">✗ {{ viesError }}</p>
              <p v-if="duplicateDic" class="text-xs text-warning-600 mt-1">
                ⚠ {{ t('client.duplicate_dic') }} <strong>{{ duplicateDic.name }}</strong>
                <RouterLink :to="`/clients/${duplicateDic.id}`" class="text-primary-700 hover:underline ml-1">{{ t('client.open_existing') }} →</RouterLink>
              </p>
            </div>
            <!-- Národní daňové číslo (#120) — jen země z TAX_NUMBER_LABELS (SK/DE/AT/PL/HU) -->
            <div v-if="taxNumberLabel">
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ taxNumberLabel }}</label>
              <input autocomplete="off" v-model="form.tax_number" :placeholder="isSkClient ? '2022638992' : ''"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md font-mono text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <p class="text-xs text-neutral-500 mt-1">{{ isSkClient ? t('client.tax_number_hint_sk') : t('client.tax_number_hint') }}</p>
            </div>
          </div>
          <div v-if="viesResult" class="mt-2 text-xs">
            <span v-if="viesResult.valid" class="text-primary-700">✓ {{ t('client.dic_valid', { dic: t('client.dic'), name: viesResult.name }) }}</span>
            <span v-else class="text-danger-500">✗ {{ t('client.dic_invalid', { dic: t('client.dic') }) }}</span>
          </div>

          <!-- Plátcovství DPH (z ARES/VIES, editovatelné) + detaily z registru plátců DPH -->
          <div class="mt-3 flex flex-wrap items-center gap-4 pt-3 border-t border-neutral-100">
            <label class="inline-flex items-center gap-2 text-sm">
              <input v-model="form.is_vat_payer" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :class="!form.is_vat_payer ? 'text-warning-700 font-medium' : ''">{{ t('client.vat_payer_label') }}</span>
            </label>
            <button v-if="form.dic" type="button" @click="loadVatPayerDetails" :disabled="vatInfoLoading"
              class="cursor-pointer px-3 h-9 text-sm border border-primary-500/40 rounded-md text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1.5 disabled:opacity-50">
              <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
              {{ vatInfoLoading ? t('common.loading') : t('client.vat_payer_details') }}
            </button>
          </div>

          <!-- Detaily plátce DPH (na vyžádání z registru plátců DPH / MFČR) -->
          <div v-if="vatInfoOpen" class="mt-3 bg-neutral-50 border border-neutral-200 rounded-lg p-4">
            <div class="flex items-center justify-between mb-2">
              <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('client.vat_payer_details') }}</h4>
              <button type="button" @click="vatInfoOpen = false" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-sm leading-none">✕</button>
            </div>
            <div v-if="vatInfoLoading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
            <div v-else-if="vatInfoError" class="text-sm text-danger-500">{{ vatInfoError }}</div>
            <!-- Zahraniční EU DIČ → výsledek z VIES (#120) -->
            <template v-else-if="vatInfoVies">
              <div v-if="vatInfoVies.source === 'error'" class="text-sm text-warning-600">{{ t('client.vat_payer_vies_unavailable') }}</div>
              <div v-else-if="!vatInfoVies.valid" class="text-sm text-neutral-600">{{ t('client.vat_payer_vies_not_registered') }}</div>
              <div v-else class="space-y-2 text-sm">
                <div>
                  <span class="px-2 py-0.5 rounded bg-success-50 text-success-600 font-medium">{{ t('client.vat_payer_vies_registered') }}</span>
                </div>
                <div v-if="vatInfoVies.vat_number" class="font-mono text-neutral-900">{{ vatInfoVies.vat_number }}</div>
                <div v-if="vatInfoVies.name" class="text-neutral-900">{{ vatInfoVies.name }}</div>
                <div v-if="vatInfoVies.address" class="text-neutral-600 whitespace-pre-line">{{ vatInfoVies.address }}</div>
                <p class="text-xs text-neutral-400">{{ t('client.vat_payer_vies_source') }}</p>
              </div>
            </template>
            <template v-else-if="vatInfo">
              <div v-if="vatInfo.source === 'error'" class="text-sm text-warning-600">{{ t('client.vat_payer_unavailable') }}</div>
              <div v-else-if="!vatInfo.found" class="text-sm text-neutral-600">{{ t('client.vat_payer_not_registered') }}</div>
              <div v-else class="space-y-2 text-sm">
                <div class="flex items-center gap-2">
                  <span class="text-neutral-500">{{ t('client.vat_payer_reliability') }}:</span>
                  <span v-if="vatInfo.unreliable === true" class="px-2 py-0.5 rounded bg-danger-50 text-danger-600 font-medium">{{ t('client.vat_payer_unreliable') }}</span>
                  <span v-else-if="vatInfo.unreliable === false" class="px-2 py-0.5 rounded bg-success-50 text-success-600 font-medium">{{ t('client.vat_payer_reliable') }}</span>
                  <span v-else class="px-2 py-0.5 rounded bg-neutral-100 text-neutral-600">{{ t('client.vat_payer_unknown') }}</span>
                </div>
                <div>
                  <div class="text-neutral-500 mb-1">{{ t('client.vat_payer_accounts') }}:</div>
                  <ul v-if="vatInfo.accounts.length" class="space-y-0.5">
                    <li v-for="(a, i) in vatInfo.accounts" :key="i" class="font-mono text-neutral-900">{{ a.display }}</li>
                  </ul>
                  <div v-else class="text-neutral-500">{{ t('client.vat_payer_no_accounts') }}</div>
                </div>
                <p class="text-xs text-neutral-400">{{ t('client.vat_payer_source') }}</p>
              </div>
            </template>
          </div>
        </div>

        <!-- Základní -->
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.company_name') }} *</label>
          <input autocomplete="off" v-model="form.company_name" required
            class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          <p v-if="errors.company_name" class="text-xs text-danger-500 mt-1">{{ errors.company_name[0] }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.main_email') }}</label>
            <input autocomplete="off" v-model="form.main_email" type="email"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            <p v-if="errors.main_email" class="text-xs text-danger-500 mt-1">{{ errors.main_email[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.phone') }}</label>
            <input autocomplete="off" v-model="form.phone"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>
        </div>

        <!-- E-mailové kontakty dle účelu (#86) — plný editor i embedded modal (faktura/new) -->
        <div class="border border-neutral-200 rounded-md p-3 space-y-3">
          <div class="flex items-center justify-between gap-2 flex-wrap">
            <div>
              <div class="text-sm font-medium text-neutral-700">{{ t('client.email_contacts.title') }}</div>
              <p class="text-xs text-neutral-500 mt-0.5">{{ t('client.email_contacts.hint') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <span v-if="emailContacts.length >= MAX_EMAIL_CONTACTS" class="text-xs text-neutral-400">
                {{ t('client.email_contacts.limit_reached', { max: MAX_EMAIL_CONTACTS }) }}
              </span>
              <button type="button" @click="addEmailContact(form.main_email)"
                v-if="form.main_email && !emailContacts.some(c => c.email === form.main_email)"
                :disabled="emailContacts.length >= MAX_EMAIL_CONTACTS"
                class="cursor-pointer h-8 px-2.5 text-xs border border-neutral-300 rounded-md text-neutral-600 hover:bg-neutral-50 disabled:opacity-50 disabled:cursor-not-allowed">
                {{ t('client.email_contacts.add_main') }}
              </button>
              <button type="button" @click="addEmailContact()"
                :disabled="emailContacts.length >= MAX_EMAIL_CONTACTS"
                class="cursor-pointer h-8 px-2.5 text-xs border border-primary-500/40 text-primary-700 rounded-md hover:bg-primary-50 font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                + {{ t('client.email_contacts.add') }}
              </button>
            </div>
          </div>

          <div v-for="(c, idx) in emailContacts" :key="idx"
            :class="['rounded-md border p-3 space-y-2', c.is_active ? 'border-neutral-200 bg-neutral-50/50' : 'border-neutral-200 bg-neutral-100 opacity-60']">
            <div class="grid grid-cols-1 sm:grid-cols-[1fr_minmax(0,0.7fr)_minmax(0,0.7fr)_auto] gap-2 items-start">
              <input autocomplete="off" v-model="c.email" type="email" :placeholder="t('client.email_contacts.email_ph')"
                class="w-full h-9 px-2.5 border border-neutral-300 rounded-md text-sm bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <input autocomplete="off" v-model="c.contact_name" :placeholder="t('client.email_contacts.name_ph')"
                class="w-full h-9 px-2.5 border border-neutral-300 rounded-md text-sm bg-surface" />
              <input autocomplete="off" v-model="c.label" :placeholder="t('client.email_contacts.label_ph')"
                class="w-full h-9 px-2.5 border border-neutral-300 rounded-md text-sm bg-surface" />
              <button type="button" @click="removeEmailContact(idx)"
                class="cursor-pointer w-9 h-9 inline-flex items-center justify-center border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded-md text-lg leading-none shrink-0">×</button>
            </div>
            <div class="flex items-center gap-x-4 gap-y-2 flex-wrap text-sm">
              <label v-for="code in USAGE_CODES" :key="code" class="flex items-center gap-1.5 cursor-pointer">
                <input type="checkbox" :checked="hasUsage(c, code)" @change="toggleUsage(c, code)"
                  class="rounded border-neutral-300 text-primary-600" />
                <span class="text-neutral-700">{{ t(`client.email_contacts.usage.${code}`) }}</span>
              </label>
              <span class="grow"></span>
              <label class="flex items-center gap-1.5 text-xs text-neutral-600">
                {{ t('client.email_contacts.recipient_kind') }}
                <select :value="contactRecipient(c)" @change="setContactRecipient(c, ($event.target as HTMLSelectElement).value as any)"
                  class="h-8 px-1.5 border border-neutral-300 rounded-md bg-surface text-xs">
                  <option value="to">{{ t('client.email_contacts.kind_to') }}</option>
                  <option value="cc">{{ t('client.email_contacts.kind_cc') }}</option>
                  <option value="bcc">{{ t('client.email_contacts.kind_bcc') }}</option>
                </select>
              </label>
              <label class="flex items-center gap-1.5 text-xs text-neutral-600 cursor-pointer">
                <input type="checkbox" v-model="c.is_active" class="rounded border-neutral-300 text-primary-600" />
                {{ t('client.email_contacts.active') }}
              </label>
            </div>
          </div>

          <p v-if="emailContacts.some(c => c.is_active && c.email && c.usages.some(u => u.usage !== 'communication'))"
            class="text-xs text-warning-700 bg-warning-50 border border-warning-200 rounded-md px-2.5 py-1.5">
            {{ t('client.email_contacts.main_excluded_note') }}
          </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.street') }} *</label>
            <input autocomplete="off" v-model="form.street" required
              class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.zip') }} *</label>
            <input autocomplete="off" v-model="form.zip" required
              class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.city') }} *</label>
            <input autocomplete="off" v-model="form.city" required
              class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.country') }}</label>
            <select v-model="form.country_iso2"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option v-for="c in countries" :key="c.iso2" :value="c.iso2">{{ locale === 'en' ? c.name_en : c.name_cs }}</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.language') }}</label>
            <select v-model="form.language"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option value="cs">Čeština</option>
              <option value="en">English</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.payment_due_label') }}</label>
            <div class="flex gap-2 items-center">
              <select v-model="clientDuePreset"
                class="flex-1 min-w-0 h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                <option value="inherit">{{ t('client.payment_due_inherit', { default: supplierDueLabel }) }}</option>
                <option value="7">{{ t('client.payment_due_preset_7') }}</option>
                <option value="14">{{ t('client.payment_due_preset_14') }}</option>
                <option value="month">{{ t('client.payment_due_preset_month') }}</option>
                <option value="custom">{{ t('client.payment_due_preset_custom') }}</option>
              </select>
              <div v-if="clientDuePreset === 'custom'" class="flex items-center gap-1.5 shrink-0">
                <input autocomplete="off" v-model.number="form.payment_due_default" type="number" min="1" max="365"
                  class="w-20 h-10 px-2 border border-neutral-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                <span class="text-xs text-neutral-500">{{ t('client.payment_due_custom_days_suffix') }}</span>
              </div>
            </div>
            <p v-if="clientDuePreset === 'month'" class="text-xs text-neutral-500 mt-1">{{ t('client.payment_due_month_hint') }}</p>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.currency_default') }}</label>
            <select v-model.number="form.currency_default_id"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.hourly_rate') }}</label>
            <input autocomplete="off" v-model.number="form.hourly_rate" type="number" step="0.01" min="0" placeholder="0"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('client.hourly_rate_hint') }}</p>
            <p v-if="errors.hourly_rate" class="text-xs text-danger-500 mt-1">{{ errors.hourly_rate[0] }}</p>
          </div>
        </div>

        <div class="space-y-2">
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            <span>{{ t('client.reverse_charge') }}</span>
          </label>
          <div>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.auto_send_reminders" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span>{{ t('client.auto_send_reminders') }}</span>
            </label>
            <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('client.auto_send_reminders_hint') }}</p>
          </div>
        </div>

        <!-- Role flagy: klient i dodavatel současně -->
        <div class="pt-3 border-t border-neutral-100">
          <p class="text-xs text-neutral-500 mb-2">{{ t('client.roles_hint') }}</p>
          <div class="flex flex-wrap gap-6">
            <label class="flex items-center gap-2 text-sm">
              <input
                v-model="form.is_customer"
                type="checkbox"
                :disabled="lockCustomer && form.is_customer"
                class="rounded border-neutral-300 text-primary-600 disabled:opacity-50"
              />
              <span>{{ t('client.is_customer_label') }}</span>
              <span v-if="lockCustomer" class="text-xs text-neutral-400 italic">
                ({{ t('client.locked_has_invoices') }})
              </span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input
                v-model="form.is_vendor"
                type="checkbox"
                :disabled="lockVendor && form.is_vendor"
                class="rounded border-neutral-300 text-primary-600 disabled:opacity-50"
              />
              <span>{{ t('client.is_vendor_label') }}</span>
              <span v-if="lockVendor" class="text-xs text-neutral-400 italic">
                ({{ t('client.locked_has_purchases') }})
              </span>
            </label>
          </div>
          <p v-if="!form.is_customer && !form.is_vendor" class="text-xs text-danger-600 mt-1">
            {{ t('client.roles_required') }}
          </p>
          <label v-if="form.is_vendor" class="flex items-center gap-2 text-sm mt-3">
            <input v-model="form.is_fuel_station" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            <span>{{ t('client.is_fuel_station_label') }}</span>
            <span class="text-xs text-neutral-400">{{ t('client.is_fuel_station_hint') }}</span>
          </label>
        </div>

        <!-- Výchozí kategorie nákladu (jen pro dodavatele) -->
        <div v-if="form.is_vendor" class="pt-3 border-t border-neutral-100">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.default_expense_category') }}</label>
          <select v-model="form.default_expense_category_id"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
            <option :value="null">— {{ t('client.default_expense_category_none') }} —</option>
            <option v-for="c in expenseCategories" :key="c.id" :value="c.id">
              {{ c.label }} ({{ c.code }})
            </option>
          </select>
          <p class="text-xs text-neutral-500 mt-1">{{ t('client.default_expense_category_hint') }}</p>
        </div>

        <!-- Výchozí kategorie tržby (jen pro zákazníky) -->
        <div v-if="form.is_customer" class="pt-3 border-t border-neutral-100">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.default_revenue_category') }}</label>
          <select v-model="form.default_revenue_category_id"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
            <option :value="null">— {{ t('client.default_revenue_category_none') }} —</option>
            <option v-for="c in revenueCategories" :key="c.id" :value="c.id">
              {{ c.label }} ({{ c.code }})
            </option>
          </select>
          <p class="text-xs text-neutral-500 mt-1">{{ t('client.default_revenue_category_hint') }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.note') }}</label>
          <textarea autocomplete="off" v-model="form.note" rows="2"
            class="w-full px-3 py-2 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"></textarea>
        </div>

        <div v-if="form.is_customer && brandingProfiles.length" class="pt-3 border-t border-neutral-100">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.branding_profile') }}</label>
          <select v-model="form.default_branding_profile_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
            <option :value="null">{{ t('client.branding_profile_default') }}</option>
            <option v-for="profile in brandingProfiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option>
          </select>
          <p class="text-xs text-neutral-500 mt-1">{{ t('client.branding_profile_hint') }}</p>
        </div>

        <!-- Per-client číselná řada (volitelná) -->
        <details class="pt-3 border-t border-neutral-100" :open="!!(form.invoice_number_format || form.proforma_number_format || form.credit_note_number_format)">
          <summary class="cursor-pointer text-sm font-medium text-neutral-700">
            {{ t('client.numbering_section') }}
          </summary>
          <p class="text-xs text-neutral-500 mt-1 mb-3">{{ t('client.numbering_hint') }}</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('client.invoice_number_format') }}</label>
              <input v-model="form.invoice_number_format" type="text" maxlength="60" placeholder="{YY}{CCCC}"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('client.proforma_number_format') }}</label>
              <input v-model="form.proforma_number_format" type="text" maxlength="60" placeholder="9{YY}{CCCC}"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('client.credit_note_number_format') }}</label>
              <input v-model="form.credit_note_number_format" type="text" maxlength="60" placeholder=""
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('client.invoice_number_period') }}</label>
              <select v-model="form.invoice_number_period"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                <option :value="null">{{ t('client.numbering_period_inherit') }}</option>
                <option value="year">{{ t('client.numbering_period_year') }}</option>
                <option value="month">{{ t('client.numbering_period_month') }}</option>
                <option value="none">{{ t('client.numbering_period_none') }}</option>
              </select>
            </div>
          </div>
          <p class="text-xs text-neutral-500 mt-2">{{ t('client.numbering_placeholders_hint') }}</p>
        </details>

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </div>
      </div>

      <div class="px-5 py-3 border-t border-neutral-200 bg-neutral-50 flex justify-end gap-3 rounded-b-lg">
        <button v-if="embedded" type="button" @click="emit('cancel')"
          class="px-4 h-10 border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface text-sm font-medium">{{ t('common.cancel') }}</button>
        <RouterLink v-else to="/clients" class="px-4 h-10 leading-10 border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface text-sm font-medium">{{ t('common.cancel') }}</RouterLink>
        <button type="submit" :disabled="submitting"
          class="px-5 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
          {{ submitting ? t('common.saving') : (isEdit ? t('common.save') : t('common.create')) }}
        </button>
      </div>
    </form>
  </div>
</template>

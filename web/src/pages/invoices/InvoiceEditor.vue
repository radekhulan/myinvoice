<script setup lang="ts">
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { invoicesApi, type Invoice, type InvoicePayload, type InvoiceItem, type WorkReportItem, type WorkReportMaterial, type InvoiceAttachment } from '@/api/invoices'
import { useHotkey } from '@/composables/useHotkey'
import { focusLastRow } from '@/composables/useRowFocus'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'

const { t, locale } = useI18n()
const toast = useToast()

useHotkey('ctrl+s', (e) => { e.preventDefault(); submit() })
import { clientsApi, type Client, type ViesLookupResult } from '@/api/clients'
import { projectsApi, type Project } from '@/api/projects'
import { codebooksApi, type VatRate, type Currency, type Unit } from '@/api/codebooks'
import { vatClassificationsApi, type VatClassification } from '@/api/vatClassifications'
import { revenueCategoriesApi, type RevenueCategory } from '@/api/revenueCategories'
import { formatMoney, formatPercent } from '@/composables/useFormat'
import { evalMath } from '@/directives/vMath'
import { apiErrorMessage } from '@/api/errors'
import { useSupplierStore } from '@/stores/supplier'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import Modal from '@/components/ui/Modal.vue'
import ClientFormModal from '@/components/modals/ClientFormModal.vue'
import ProjectFormModal from '@/components/modals/ProjectFormModal.vue'
import { priceListApi, type PriceListItem } from '@/api/priceList'
import { useAuthStore } from '@/stores/auth'

const supplierStore = useSupplierStore()
const auth = useAuthStore()
const isAdmin = computed(() => auth.user?.role === 'admin')

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const invoiceId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const loaded = ref(false)
const submitting = ref(false)
const loadedRate = ref<{ rate: number; date: string; currency: string } | null>(null)

// Kurz se posílá jako ruční jen při skutečné změně uživatelem (ve stejné měně, v jaké
// byl načten). Jinak backend po změně DUZP / data / měny přenačte kurz ČNB.
function userChangedExchangeRate(): boolean {
  const rate = form.value.exchange_rate
  if (form.value.currency === 'CZK' || !rate || rate <= 0) return false
  const orig = loadedRate.value
  if (!orig || orig.currency !== form.value.currency) return false
  return Math.abs(rate - orig.rate) > 1e-9
}
const error = ref('')
// Odemčení uzamčeného (non-draft) dokladu — POUZE pro tuto návštěvu editoru.
// Nastavuje se vědomým potvrzením v modalu (checkbox s následky); ?force=1
// z URL se záměrně ignoruje a příznak nepřežije reload (žádná persistence).
const forceEdit = ref(false)
const unlockModalOpen = ref(false)
const unlockAck = ref(false)
// Uzamčený doklad: vystavená/odeslaná/zaplacená faktura bez odemčení.
const isLocked = computed(() => editedStatus.value !== 'draft' && !forceEdit.value)
function confirmUnlock() {
  forceEdit.value = true
  unlockModalOpen.value = false
  unlockAck.value = false
}

// Předvolba typu dokladu z URL (`/invoices/new?type=proforma`). Whitelist — nesmí
// projít nic jiného než povolené typy, jinak fallback na běžnou vydanou fakturu.
const queryDocType = computed<'proforma' | 'credit_note' | null>(() => {
  const q = route.query.type
  return q === 'proforma' || q === 'credit_note' ? q : null
})
const editedStatus = ref<string>('draft')
const editedVarsymbol = ref<string | null>(null)
// Původní typ načtené faktury — pro detekci změny typu u vystavené (force-edit),
// která backend přečísluje (uvolní staré číslo z řady, přidělí nové v řadě cílového typu).
const editedType = ref<string>('invoice')
// True, když u VYSTAVENÉ faktury (force-edit) uživatel přepnul typ → backend přečísluje.
const typeWillRenumber = computed(() =>
  forceEdit.value && editedStatus.value !== 'draft' && form.value.invoice_type !== editedType.value)
// Náhled čísla, které dostane faktura při Vystavení (pokud user nezadá ruční override).
// Naplní se z API na změnu invoice_type / issue_date — per-supplier per-period live preview.
const varsymbolAutoPreview = ref<string>('')
const varsymbolAutoHasTemplate = ref<boolean>(true)

// Type-aware texty: titulek stránky + popisek pole čísla (proforma / dobropis / faktura).
const editorTitle = computed(() => {
  const suffix = form.value.invoice_type === 'proforma' ? '_proforma'
               : form.value.invoice_type === 'credit_note' ? '_credit_note'
               : ''
  const key = (isEdit.value ? 'invoice.edit_title' : 'invoice.new_title') + suffix
  return t(key)
})
const varsymbolLabelKey = computed(() => {
  if (form.value.invoice_type === 'proforma') return 'invoice.varsymbol_label_proforma'
  if (form.value.invoice_type === 'credit_note') return 'invoice.varsymbol_label_credit_note'
  return 'invoice.varsymbol_label'
})

const clients = ref<Client[]>([])  // akumulovaná cache (výsledky hledání + vybraný) — čtou z ní defaults/VIES
// Server-side našeptávač klientů (zákazníků) — SearchableSelect v remote režimu.
const clientOptions = ref<{ value: number; label: string; secondary?: string }[]>([])
const clientsLoading = ref(false)
const selectedClientOption = ref<{ value: number; label: string; secondary?: string } | null>(null)
function clientToOption(c: Client) {
  return { value: c.id, label: c.company_name, secondary: c.ic ?? undefined }
}
function mergeClients(list: Client[]) {
  const byId = new Map(clients.value.map(c => [c.id, c]))
  for (const c of list) byId.set(c.id, c)
  clients.value = Array.from(byId.values())
}
async function onClientSearch(q: string) {
  clientsLoading.value = true
  try {
    const res = await clientsApi.list({ q: q || undefined, role: 'customers', archived: false, per_page: 50 })
    mergeClients(res.data)
    clientOptions.value = res.data.map(clientToOption)
  } catch { /* ignore */ } finally {
    clientsLoading.value = false
  }
}
// Edit / pre-select: dotáhni klienta podle id (do cache + label), fallback na denorm jméno z faktury.
async function ensureClientLoaded(id: number, fallbackName?: string | null, fallbackIc?: string | null) {
  const existing = clients.value.find(c => c.id === id)
  if (existing) { selectedClientOption.value = clientToOption(existing); return }
  try {
    const full = await clientsApi.get(id)
    mergeClients([full])
    selectedClientOption.value = clientToOption(full)
  } catch {
    selectedClientOption.value = { value: id, label: fallbackName ?? `#${id}`, secondary: fallbackIc ?? undefined }
  }
}
const projects = ref<Project[]>([])
const vatRates = ref<VatRate[]>([])
const vatClassifications = ref<VatClassification[]>([])
const revenueCategories = ref<RevenueCategory[]>([])
const currencies = ref<Currency[]>([])
const units = ref<Unit[]>([])
const priceListItems = ref<PriceListItem[]>([])
const selectedPriceListItemId = ref<number | null>(null)
const resolvingPriceListItem = ref(false)
// Ceníkové ovládání skryj, pokud dodavatel nemá žádnou použitelnou položku.
const hasPriceList = computed(() => priceListItems.value.length > 0)
const priceListOptions = computed(() => priceListItems.value.map(item => {
  const resolved = item.resolved_price
  return {
    value: item.id,
    label: `${item.code} · ${item.name}`,
    secondary: resolved
      ? `${t(`price_list.price_source.${resolved.catalog_price_source}`)} · ${resolved.unit_price_without_vat.toFixed(2)} ${resolved.target_currency_code}${resolved.catalog_exchange_rate_date ? ` · ${resolved.catalog_exchange_rate_date}` : ''}`
      : undefined,
  }
}))

async function loadPriceListItems() {
  const currency = currencies.value.find(item => item.id === form.value.currency_id)?.code
  if (!currency) return
  try {
    const result = await priceListApi.list({
      currency,
      client_id: form.value.client_id ?? undefined,
      rate_date: form.value.invoice_type === 'proforma' ? form.value.issue_date : form.value.tax_date,
      prices_include_vat: form.value.prices_include_vat,
      per_page: 200,
    })
    priceListItems.value = result.data
  } catch {
    priceListItems.value = []
  }
}

// Default jednotka pro běžnou položku — z číselníku (is_default), fallback 'ks'.
function defaultItemUnit(): string {
  return units.value.find(u => u.is_default)?.code || units.value[0]?.code || 'ks'
}

// Aktivní dodavatel — pokud není plátce DPH, fakturuje bez DPH (žádné DPH UI ani v PDF).
const supplierIsVatPayer = computed(() => supplierStore.currentSupplier?.is_vat_payer ?? true)
// Identifikovaná osoba (§ 6g–6l ZDPH, #94) — neplátce, který ale služby do EU
// fakturuje s přenesenou daňovou povinností (čl. 196 směrnice) a podává SHV.
const supplierIsIdentified = computed(() => supplierStore.currentSupplier?.is_identified ?? false)

// „Osvobozeno od daně z příjmů" má smysl jen pro OSVČ (FO): osvobození dle § 4 ZDP
// platí výhradně pro fyzické osoby, u s.r.o. (PO) žádný § 4 není a prodej majetku je
// vždy zdanitelný výnos. U PO proto checkbox skryjeme. Ponecháme ho ale, pokud už je
// příznak zaškrtnutý (legacy/import), aby šel zrušit.
const showIncomeTaxExemptUI = computed(
  () => supplierStore.currentSupplier?.taxpayer_type === 'fo' || form.value.income_tax_exempt,
)

// RC je volba na konkrétním plnění (přenesení daň. povinnosti), ne natvrdo vlastnost
// odběratele → checkbox zobrazíme vždy, když je dodavatel plátce DPH (čistý neplátce
// RC vystavit nemůže) NEBO identifikovaná osoba (#94 — RC u služeb do EU je její
// hlavní use-case). Příznak `reverse_charge` v profilu klienta slouží jen jako default
// předvyplnění při výběru klienta (viz applyClientDefaults), uživatel ho může přepnout.
const showReverseChargeUI = computed(() => supplierIsVatPayer.value || supplierIsIdentified.value)

// Neplátce/IO s RC: částky na dokladu JSOU základ daně (ceny bez DPH) — DPH si
// samovyměří odběratel sazbou své země, česká sazba se neuvádí. Sloupec proto
// místo neutrálního „Celkem" pojmenujeme „Bez DPH", ať je to z dokladu zřejmé.
const nonPayerTotalLabel = computed(() =>
  form.value.reverse_charge ? t('invoice.totals.without_vat') : t('invoice.totals.total'))

const form = ref<{
  invoice_type: 'invoice' | 'proforma' | 'credit_note'
  parent_invoice_id: number | null
  client_id: number | null
  project_id: number | null
  issue_date: string
  tax_date: string
  due_date: string
  currency_id: number
  currency: string
  reverse_charge: boolean
  prices_include_vat: boolean
  income_tax_exempt: boolean
  income_tax_exempt_reason: string
  language: 'cs' | 'en'
  note_above_items: string
  note_below_items: string
  advance_paid_amount: number
  discount_percent: number
  payment_method: 'bank_transfer' | 'card' | 'cash' | 'other'
  auto_send_reminders: boolean
  exchange_rate: number | null
  varsymbol: string  // Ruční override čísla faktury (prázdný = generuje se při issue)
  vat_classification_code: string | null
  revenue_category: string | null
  revenue_category_id: number | null
  items: InvoiceItem[]
}>({
  invoice_type: 'invoice',
  parent_invoice_id: null,
  client_id: null,
  project_id: null,
  issue_date: today(),
  tax_date: today(),
  due_date: supplierDueDate(today()),
  currency_id: 0,
  currency: 'CZK',
  reverse_charge: false,
  prices_include_vat: false,
  income_tax_exempt: false,
  income_tax_exempt_reason: '',
  language: 'cs',
  note_above_items: '',
  note_below_items: '',
  advance_paid_amount: 0,
  discount_percent: 0,
  payment_method: 'bank_transfer',
  auto_send_reminders: true,
  exchange_rate: null,
  varsymbol: '',
  vat_classification_code: null,
  revenue_category: null,
  revenue_category_id: null,
  items: [],
})

// Per-faktura přepínač automatických upomínek má smysl jen když je posílá dodavatel
// i klient (cron je AND přes všechny tři úrovně, viz cron-send-reminders.php). Jakmile
// je kterákoli z těch dvou vypnutá, faktuře se auto-upomínky stejně neodešlou → přepínač
// by nic nedělal, proto ho v takovém případě skryjeme. Ruční odeslání funguje vždy.
const remindersAvailable = computed(() =>
  (supplierStore.currentSupplier?.auto_send_reminders ?? true)
  && !!form.value.client_id
  && (clients.value.find(c => c.id === form.value.client_id)?.auto_send_reminders ?? true),
)

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function addDays(date: string, days: number): string {
  const d = new Date(date)
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

// +N kalendářních měsíců se zachováním dne; pokud cílový měsíc nemá takový den
// (31.1. + 1 měsíc → "31.2."), vrátí poslední den cílového měsíce (28./29.2.).
// Datumy parsujeme jako YYYY-MM-DD bez TZ posunu (new Date('2026-01-31') by v záporných
// TZ skočilo na 30.1., pak +1 měsíc = 28.2. místo 1.3.).
function addMonths(date: string, months: number): string {
  const [y, m, d] = date.split('-').map(Number)
  const targetMonthIdx = (m - 1) + months
  const targetYear = y + Math.floor(targetMonthIdx / 12)
  const normalizedMonth = ((targetMonthIdx % 12) + 12) % 12
  const lastDay = new Date(targetYear, normalizedMonth + 1, 0).getDate()
  const day = Math.min(d, lastDay)
  return `${String(targetYear).padStart(4, '0')}-${String(normalizedMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

type DueUnit = 'days' | 'month'

function computeDueDate(issueDate: string, value: number, unit: DueUnit): string {
  return unit === 'month' ? addMonths(issueDate, value) : addDays(issueDate, value)
}

// Splatnost z výchozího nastavení dodavatele (hodnota + jednotka). Fallback 7 dnů,
// dokud supplier store není načtený (např. hard-reload přímo na /invoices/new) —
// onMounted ji pak přepočítá na skutečný supplier default.
function supplierDueDate(issueDate: string): string {
  const sup = supplierStore.currentSupplier
  const value = sup?.default_payment_due_days ?? 7
  const unit: DueUnit = (sup?.default_payment_due_unit ?? 'days') as DueUnit
  return computeDueDate(issueDate, value, unit)
}

// RC (přenesená daň. povinnost) je teď jen hlavičkový příznak `reverse_charge` — položky si
// drží nominální sazbu (21 %), daň vynuluje backend (InvoiceMath). Default sazby RC neřeší,
// proto se položky při zaškrtnutí RC už nepřepisují na 0% „CZ-RC".
function defaultVatRateId(): number {
  // Neplátce DPH → vždy 0% Osvobozeno (rate_percent=0, !is_reverse_charge).
  if (!supplierIsVatPayer.value) {
    const zero = vatRates.value.find(v => v.country === 'CZ' && Number(v.rate_percent) === 0 && !v.is_reverse_charge)
      || vatRates.value.find(v => Number(v.rate_percent) === 0 && !v.is_reverse_charge)
    if (zero) return zero.id
  }
  const def = vatRates.value.find(v => v.country === 'CZ' && v.is_default)
    || vatRates.value.find(v => v.is_default)
  return def?.id ?? vatRates.value[0]?.id ?? 0
}

function vatRateLabel(r: VatRate): string {
  const prefix = r.country !== 'CZ' ? `${r.country} ` : ''
  if (Number(r.rate_percent) > 0) return `${prefix}${r.rate_percent} %`
  if (r.is_reverse_charge) return `${prefix}${t('invoice.vat_rate_label.reverse_charge')}`
  return `${prefix}${t('invoice.vat_rate_label.exempt')}`
}

// Řádkový výběr už nenabízí „Reverse charge" (0% CZ-RC) — RC se řeší hlavičkovým checkboxem,
// který nechá nominální sazbu (21 %) a vynuluje daň. Volba RC na řádku by jinak dala 0 %
// bez automatické poznámky „Daň odvede zákazník".
const selectableVatRates = computed(() => vatRates.value.filter(r => !r.is_reverse_charge))

// U OSS dodavatele načítáme sazby všech států (loadInvoiceVatRates → country 'ALL'), ale zahraniční
// sazba smí být jen na řádku označeném jako OSS. Na běžném řádku by se totiž vykázala v tuzemské
// evidenci (VatLedgerService) a podle prahu roku spadla do české klasifikace — DE 19 % by se
// objevilo na snížené sazbě DPHDP3 s 19% daní. Řádky bez OSS proto vidí jen CZ sazby.
const domesticVatRates = computed(() => selectableVatRates.value.filter(r => r.country === 'CZ'))

// OSS je opt-in v nastavení firmy (default vypnuto) — bez registrace se v editoru vůbec
// nenabízí. Řádek, který OSS příznak už nese, ovládací prvky ukáže i po vypnutí režimu,
// aby zpětně upravitelný doklad nešlo editovat naslepo.
const ossAvailable = computed(() => supplierStore.currentSupplier?.oss_enabled === true)

function vatRatesForItem(item: InvoiceItem): VatRate[] {
  return item.oss_applicable ? selectableVatRates.value : domesticVatRates.value
}

// Odškrtnutí OSS musí shodit i zahraniční sazbu, jinak by na řádku zůstala hodnota,
// kterou už nabídka neobsahuje (select by zobrazil prázdno a uložila by se stará sazba).
function onOssApplicableChange(item: InvoiceItem): void {
  if (item.oss_applicable) return
  const current = vatRates.value.find(r => r.id === item.vat_rate_id)
  if (current && current.country !== 'CZ') item.vat_rate_id = defaultVatRateId()
}

const ossOriginalPeriodOptions = computed(() => {
  const date = form.value.tax_date || form.value.issue_date
  const match = /^(\d{4})-(\d{2})-\d{2}$/.exec(date || '')
  if (!match) return [] as Array<{ value: string; label: string }>

  const currentYear = Number(match[1])
  const currentQuarter = Math.ceil(Number(match[2]) / 3)
  const currentIndex = currentYear * 4 + currentQuarter - 1
  const firstOssIndex = 2021 * 4 + 2 // Q3 2021
  const options: Array<{ value: string; label: string }> = []
  for (let index = currentIndex - 1; index >= firstOssIndex; index--) {
    const year = Math.floor(index / 4)
    const quarter = (index % 4) + 1
    options.push({ value: `${year}Q${quarter}`, label: `Q${quarter} ${year}` })
  }
  return options
})

async function loadInvoiceVatRates(): Promise<VatRate[]> {
  const country = supplierStore.currentSupplier?.oss_enabled ? 'ALL' : 'CZ'
  return codebooksApi.vatRates(country, form.value.issue_date).catch(() => [])
}

function blankItem(): InvoiceItem {
  // Dobropis = záporné množství (sleva/refundace), default -1
  const qty = form.value.invoice_type === 'credit_note' ? -1 : 1
  const projectRate = projects.value.find(p => p.id === form.value.project_id)?.hourly_rate
  const clientRate = clients.value.find(c => c.id === form.value.client_id)?.hourly_rate
  // Project sazba má přednost; client.hourly_rate je fallback pro faktury bez zakázky.
  const rate = (projectRate && projectRate > 0) ? projectRate
    : (clientRate && clientRate > 0) ? clientRate
    : 0
  return {
    description: '',
    quantity: qty,
    unit: defaultItemUnit(),
    unit_price_without_vat: rate,
    vat_rate_id: defaultVatRateId(),
    order_index: form.value.items.length,
    oss_applicable: false,
    oss_consumer_country: null,
    oss_rate_type: 'standard',
    oss_supply_type: 'goods',
    // Bez explicitního null by pole zůstalo undefined a select „Oprava období" by se
    // netrefil do <option :value="null">, takže by se vykreslil prázdný.
    oss_original_period: null,
  }
}

// Náhled čísla faktury — backend zná aktuální counter pro per-supplier templ.
// Volá se při mount + při změně typu / data; cancellation nemá číslo.
async function loadVarsymbolPreview() {
  if (form.value.invoice_type === ('cancellation' as never)) {
    varsymbolAutoPreview.value = ''
    varsymbolAutoHasTemplate.value = true
    return
  }
  try {
    const r = await invoicesApi.previewVarsymbol(
      form.value.invoice_type,
      form.value.issue_date,
      form.value.client_id ?? undefined,
    )
    varsymbolAutoPreview.value = r.varsymbol
    varsymbolAutoHasTemplate.value = r.has_template
  } catch {
    varsymbolAutoPreview.value = ''
    varsymbolAutoHasTemplate.value = false
  }
}
watch(() => [form.value.invoice_type, form.value.issue_date, form.value.client_id], () => {
  if (loaded.value && editedStatus.value === 'draft') loadVarsymbolPreview()
})

// Při změně Vystaveno přepočti Splatnost — projekt přebíjí klienta, klient přebíjí supplier.
// Jen pro draft / nový (po `loaded`), abys nepřepsal uloženou hodnotu při hydrataci nebo
// u vystavených dokladů. Projekt má vlastní hodnotu i jednotku, klient může jednotku
// zdědit od dodavatele.
watch(() => form.value.issue_date, (newIssue) => {
  if (!loaded.value || editedStatus.value !== 'draft' || !newIssue) return
  // Zakázka přebíjí vše — má vlastní hodnotu i jednotku (NULL unit = dny).
  if (form.value.project_id) {
    const p = projects.value.find(x => x.id === form.value.project_id)
    if (p && typeof p.payment_due_days === 'number') {
      form.value.due_date = computeDueDate(newIssue, p.payment_due_days, (p.payment_due_unit ?? 'days') as DueUnit)
      return
    }
  }
  // Klient s vlastní hodnotou → jeho jednotka (bez vlastní dědí supplier),
  // jinak plně dědí supplier default (hodnotu i jednotku).
  const c = form.value.client_id ? clients.value.find(x => x.id === form.value.client_id) : null
  if (c && typeof c.payment_due_default === 'number') {
    const unit = c.payment_due_unit ?? supplierStore.currentSupplier?.default_payment_due_unit ?? 'days'
    form.value.due_date = computeDueDate(newIssue, c.payment_due_default, unit as DueUnit)
  } else {
    form.value.due_date = supplierDueDate(newIssue)
  }
})

// Přepnutí „Vydaná faktura" (/invoices/new) ⇄ „Zálohová faktura" (?type=proforma) z menu je
// stejná route → komponenta se recykluje, onMounted už neproběhne. Bez tohoto watcheru by typ
// zůstal z prvního otevření. Jen v režimu nového dokladu (edit netknutý). Promítne se i do
// titulku, čísla dokladu (loadVarsymbolPreview) a skrytí DUZP u proformy.
watch(() => route.query.type, () => {
  if (isEdit.value) return
  form.value.invoice_type = queryDocType.value ?? 'invoice'
})

watch(
  () => [form.value.client_id, form.value.currency_id, form.value.prices_include_vat, form.value.issue_date, form.value.tax_date] as const,
  () => { if (loaded.value) void loadPriceListItems() },
)

// Při přepnutí typu na credit_note převrať množství všech existujících položek na záporná.
watch(() => form.value.invoice_type, (newType, oldType) => {
  if (newType === 'credit_note' && oldType !== 'credit_note') {
    for (const it of form.value.items) {
      if (it.quantity > 0) it.quantity = -it.quantity
    }
  }
  if (oldType === 'credit_note' && newType !== 'credit_note') {
    for (const it of form.value.items) {
      if (it.quantity < 0) it.quantity = -it.quantity
    }
  }
})

onMounted(async () => {
  const [vr, cur, un, vc, rcat] = await Promise.all([
    loadInvoiceVatRates(),
    codebooksApi.currencies(),
    codebooksApi.units(),
    vatClassificationsApi.list('sale'),
    revenueCategoriesApi.list(false),
  ])
  vatRates.value = vr
  currencies.value = cur
  units.value = un
  vatClassifications.value = vc
  revenueCategories.value = rcat
  if (form.value.currency_id === 0) {
    const def = cur.find(c => c.is_default && c.code === 'CZK') || cur[0]
    if (def) {
      form.value.currency_id = def.id
      form.value.currency = def.code
    }
  }
  await loadPriceListItems()

  // Klienti se hledají server-side (onClientSearch); cache `clients` se plní výsledky + vybraným.

  if (isEdit.value && invoiceId.value) {
    const inv = await invoicesApi.get(invoiceId.value)
    editedStatus.value = inv.status
    editedVarsymbol.value = inv.varsymbol
    editedType.value = inv.invoice_type
    Object.assign(form.value, {
      invoice_type: (inv.invoice_type === 'proforma' || inv.invoice_type === 'credit_note')
        ? inv.invoice_type
        : 'invoice',
      parent_invoice_id: inv.parent_invoice_id,
      client_id: inv.client_id,
      project_id: inv.project_id,
      issue_date: inv.issue_date.slice(0, 10),
      tax_date: (inv.tax_date ?? inv.issue_date).slice(0, 10),
      due_date: inv.due_date.slice(0, 10),
      currency_id: inv.currency_id,
      currency: inv.currency,
      reverse_charge: inv.reverse_charge,
      prices_include_vat: (inv as { prices_include_vat?: boolean }).prices_include_vat ?? false,
      income_tax_exempt: (inv as { income_tax_exempt?: boolean }).income_tax_exempt ?? false,
      income_tax_exempt_reason: (inv as { income_tax_exempt_reason?: string | null }).income_tax_exempt_reason ?? '',
      language: inv.language,
      note_above_items: inv.note_above_items ?? '',
      note_below_items: inv.note_below_items ?? '',
      advance_paid_amount: inv.advance_paid_amount,
      discount_percent: inv.discount_percent ?? 0,
      payment_method: inv.payment_method ?? 'bank_transfer',
      auto_send_reminders: (inv as { auto_send_reminders?: boolean }).auto_send_reminders ?? true,
      // Slevové položky (item_kind='discount') jsou generované z discount_percent —
      // do editovatelného seznamu nepatří (jinak by se editovaly / zdvojily při uložení).
      items: inv.items.filter(i => i.item_kind !== 'discount').map(i => ({ ...i })),
      exchange_rate: inv.exchange_rate ?? null,
      varsymbol: inv.varsymbol ?? '',
      vat_classification_code: (inv as any).vat_classification_code ?? null,
      revenue_category: (inv as any).revenue_category ?? null,
      revenue_category_id: (inv as any).revenue_category_id ?? null,
    })
    loadedRate.value = (inv.exchange_rate && inv.currency !== 'CZK')
      ? { rate: inv.exchange_rate, date: (inv.exchange_rate_date ?? inv.issue_date).slice(0, 10), currency: inv.currency }
      : null
    if (inv.client_id) {
      await ensureClientLoaded(inv.client_id, (inv as any).client_company_name, (inv as any).client_ic)
      await loadProjects(inv.client_id)
      await verifyClientVies(inv.client_id)
    }
    // Načti existující work_report (pokud existuje)
    await loadWorkReport()
    await loadAttachments()
    if (editedStatus.value === 'draft') await loadVarsymbolPreview()
  } else {
    // New invoice — pre-select from query
    // Typ dokladu z URL (?type=proforma → zálohová faktura), jinak zůstává 'invoice'.
    if (queryDocType.value) form.value.invoice_type = queryDocType.value
    // Výchozí režim cen z nastavení dodavatele (0 = bez DPH; 1 = ceny s DPH).
    form.value.prices_include_vat = supplierStore.currentSupplier?.default_prices_include_vat ?? false
    if (route.query.client_id) {
      form.value.client_id = Number(route.query.client_id)
      await ensureClientLoaded(form.value.client_id!)
      await loadProjects(form.value.client_id!)
      await applyClientDefaults(form.value.client_id!)
    }
    if (route.query.project_id) {
      form.value.project_id = Number(route.query.project_id)
      await applyProjectDefaults(form.value.project_id!)
    } else if (projects.value.length === 1) {
      // Pokud klient má jen jeden projekt, předvyplň ho.
      form.value.project_id = projects.value[0].id
      await applyProjectDefaults(form.value.project_id)
    }
    if (form.value.items.length === 0) {
      form.value.items = [blankItem()]
    }
    // Bez klienta i zakázky: splatnost z výchozího nastavení dodavatele
    // (supplier store je teď spolehlivě načtený, na rozdíl od init form refu).
    if (!form.value.client_id && !form.value.project_id) {
      form.value.due_date = supplierDueDate(form.value.issue_date)
    }
    await loadVarsymbolPreview()
  }

  await loadPriceListItems()
  loaded.value = true
})

async function loadProjects(clientId: number) {
  projects.value = await projectsApi.listForClient(clientId)
}

// Inline client/project creation přes modal — UX zlepšení, žádné opouštění editoru.
const clientModalOpen = ref(false)
const projectModalOpen = ref(false)

async function onClientCreatedInModal(client: Client) {
  // Čerstvě přidaný klient → do cache + rovnou vybrat (defaults/projects/VIES v onClientChange).
  mergeClients([client])
  selectedClientOption.value = clientToOption(client)
  form.value.client_id = client.id
  clientModalOpen.value = false
  await onClientChange()
}

async function onProjectCreatedInModal(project: Project) {
  projects.value = [project, ...projects.value.filter(p => p.id !== project.id)]
  form.value.project_id = project.id
  projectModalOpen.value = false
  await onProjectChange()
}

async function onClientChange() {
  form.value.project_id = null
  if (form.value.client_id) {
    const c = clients.value.find(cc => cc.id === form.value.client_id)
    if (c) selectedClientOption.value = clientToOption(c)
    await loadProjects(form.value.client_id)
    await applyClientDefaults(form.value.client_id)
    await verifyClientVies(form.value.client_id)
    if (projects.value.length === 1) {
      form.value.project_id = projects.value[0].id
      await applyProjectDefaults(form.value.project_id)
    }
  } else {
    selectedClientOption.value = null
    viesResult.value = null
  }
}

async function applyClientDefaults(clientId: number) {
  const c = clients.value.find(c => c.id === clientId)
  if (!c) return
  form.value.currency_id = c.currency_default_id
  form.value.currency = c.currency_default
  form.value.language = c.language
  // Čistý neplátce DPH nikdy nevystavuje RC fakturu — ignorujeme klientský flag.
  // RC jen přepne hlavičkový příznak; sazby položek (nominální) se nemění.
  // Identifikovaná osoba (#94): u EU klienta s DIČ je RC její hlavní use-case
  // (služby § 9/1, čl. 196 směrnice) → zapnout automaticky a předvyplnit
  // klasifikaci 22 (EU služby → souhrnné hlášení). Klient ze 3. země RC nemá —
  // plnění je mimo předmět DPH, žádná klauzule ani SHV.
  if (supplierIsVatPayer.value) {
    form.value.reverse_charge = c.reverse_charge
  } else if (supplierIsIdentified.value && c.country_is_eu && c.country_iso2 !== 'CZ' && (c.dic || '').trim() !== '') {
    form.value.reverse_charge = true
    if (!form.value.vat_classification_code) form.value.vat_classification_code = '22'
  } else {
    form.value.reverse_charge = false
  }
  // Výchozí kategorie tržby klienta — předvyplň, jen pokud uživatel ještě nevybral
  // (project default má přednost a aplikuje se až v applyProjectDefaults).
  if (form.value.revenue_category_id == null && c.default_revenue_category_id != null) {
    form.value.revenue_category_id = c.default_revenue_category_id
  }
  // Klient s vlastní hodnotou → jeho jednotka (bez vlastní dědí supplier),
  // jinak plně dědí supplier default (hodnotu i jednotku).
  if (typeof c.payment_due_default === 'number') {
    const unit = c.payment_due_unit ?? supplierStore.currentSupplier?.default_payment_due_unit ?? 'days'
    form.value.due_date = computeDueDate(form.value.issue_date, c.payment_due_default, unit as DueUnit)
  } else {
    form.value.due_date = supplierDueDate(form.value.issue_date)
  }
  // Klientská sazba — fallback pro faktury bez zakázky (project rate přepíše později).
  // „Prázdná položka" = prázdný popis; rate mohl naplnit předchozí klient/projekt, přesto chceme refresh.
  if (!form.value.project_id && c.hourly_rate && c.hourly_rate > 0) {
    if (form.value.items.length === 1 && (form.value.items[0].description || '').trim() === '') {
      form.value.items[0].unit_price_without_vat = c.hourly_rate
      form.value.items[0].unit = defaultItemUnit()
    }
    if (wrItems.value.length === 1 && (wrItems.value[0].description || '').trim() === '') {
      wrItems.value[0].rate = c.hourly_rate
    }
  }
}

// VIES ověření DIČ vybraného klienta (jen pokud má DIČ)
const viesResult = ref<{ status: 'checking' | 'valid' | 'invalid' | 'no_dic' | 'error'; dic?: string; name?: string; message?: string } | null>(null)

async function verifyClientVies(clientId: number) {
  const c = clients.value.find(cc => cc.id === clientId)
  if (!c) { viesResult.value = null; return }
  const dic = (c.dic || '').trim()
  if (!dic) { viesResult.value = { status: 'no_dic' }; return }
  viesResult.value = { status: 'checking', dic }
  try {
    const r: ViesLookupResult = await clientsApi.lookupVies(dic)
    if (r.valid) {
      viesResult.value = { status: 'valid', dic, name: r.name }
    } else {
      viesResult.value = { status: 'invalid', dic, message: r.source === 'error' ? t('invoice.vies.service_unavailable') : t('invoice.vies.not_valid') }
    }
  } catch (e: any) {
    viesResult.value = { status: 'error', dic, message: e?.response?.data?.error?.message || t('invoice.vies.verify_error') }
  }
}

async function onProjectChange() {
  if (form.value.project_id) await applyProjectDefaults(form.value.project_id)
}

function onCurrencyChange() {
  const c = currencies.value.find(x => x.id === form.value.currency_id)
  if (c) form.value.currency = c.code
}

async function applyProjectDefaults(projectId: number) {
  const p = projects.value.find(p => p.id === projectId)
  if (!p) return
  form.value.currency_id = p.currency_id
  form.value.currency = p.currency
  form.value.due_date = computeDueDate(form.value.issue_date, p.payment_due_days, (p.payment_due_unit ?? 'days') as DueUnit)
  // Výchozí kategorie tržby zakázky — PŘEDNOST před klientem. Aplikuje se při výběru
  // zakázky (konzistentní s tím, že zakázka přepisuje měnu/splatnost). Když zakázka
  // default nemá, ponecháme hodnotu z klienta.
  if (p.default_revenue_category_id != null) {
    form.value.revenue_category_id = p.default_revenue_category_id
  }
  // Pokud má jen jednu prázdnou položku (bez popisu), refresh sazby z projektu.
  if (form.value.items.length === 1 && (form.value.items[0].description || '').trim() === '') {
    form.value.items[0].unit_price_without_vat = p.hourly_rate
    form.value.items[0].unit = defaultItemUnit()
  }
  if (wrItems.value.length === 1 && (wrItems.value[0].description || '').trim() === '') {
    wrItems.value[0].rate = p.hourly_rate
  }
}

// (žádné watch hooky pro typ ani datumy — proforma nemá DUZP, viz template)

function addItem() {
  form.value.items.push(blankItem())
  focusLastRow('[data-row-input="inv-item"]')
}

async function addPriceListItem() {
  if (!selectedPriceListItemId.value) return
  if (!form.value.client_id || !form.value.currency_id) {
    toast.warning(t('invoice.price_list_requires_context'))
    return
  }
  resolvingPriceListItem.value = true
  try {
    const resolved = await priceListApi.resolve(selectedPriceListItemId.value, {
      client_id: form.value.client_id,
      currency_id: form.value.currency_id,
      rate_date: form.value.invoice_type === 'proforma' ? form.value.issue_date : form.value.tax_date,
      prices_include_vat: form.value.prices_include_vat,
    })
    const target = form.value.items.length === 1
      && !form.value.items[0].description.trim()
      && Number(form.value.items[0].unit_price_without_vat) === 0
      ? form.value.items[0]
      : blankItem()
    Object.assign(target, {
      description: resolved.description,
      quantity: form.value.invoice_type === 'credit_note' ? -1 : 1,
      unit: resolved.unit,
      unit_price_without_vat: resolved.unit_price_without_vat,
      vat_rate_id: resolved.vat_rate_id,
    })
    if (!form.value.items.includes(target)) form.value.items.push(target)
    form.value.items.forEach((item, index) => { item.order_index = index })
    selectedPriceListItemId.value = null
    toast.success(t('invoice.price_list_added'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    resolvingPriceListItem.value = false
  }
}

function removeItem(index: number) {
  form.value.items.splice(index, 1)
  form.value.items.forEach((it, i) => (it.order_index = i))
}

function moveUp(index: number) {
  if (index === 0) return
  const [m] = form.value.items.splice(index, 1)
  form.value.items.splice(index - 1, 0, m)
  form.value.items.forEach((it, i) => (it.order_index = i))
}

function moveDown(index: number) {
  if (index >= form.value.items.length - 1) return
  const [m] = form.value.items.splice(index, 1)
  form.value.items.splice(index + 1, 0, m)
  form.value.items.forEach((it, i) => (it.order_index = i))
}

// Live výpočet sumace na frontendu (server přepočítá při uložení)
const computed_totals = computed(() => {
  const pricesIncl = form.value.prices_include_vat && supplierIsVatPayer.value
  const breakdown = new Map<number, { rate: number; base: number; vat: number }>()

  for (const item of form.value.items) {
    const vatRate = (form.value.reverse_charge || !supplierIsVatPayer.value)
      ? 0
      : vatRates.value.find(v => v.id === item.vat_rate_id)?.rate_percent ?? 0
    // amount = cena bez DPH (zdola) / cena s DPH (shora, „ceny položek včetně DPH")
    const amount = round2(item.quantity * item.unit_price_without_vat)
    let base: number
    let vat: number
    if (pricesIncl) {
      vat = round2(amount * vatRate / (100 + vatRate))
      base = round2(amount - vat)
    } else {
      base = amount
      // base*rate/100 (dělit až nakonec), shodně s backendem InvoiceMath — viz issue #82.
      vat = round2(base * vatRate / 100)
    }

    if (!breakdown.has(vatRate)) {
      breakdown.set(vatRate, { rate: vatRate, base: 0, vat: 0 })
    }
    const b = breakdown.get(vatRate)!
    b.base += base
    b.vat += vat
  }

  // Sleva na úrovni dokladu — odečte se na každé sazbě zvlášť (zrcadlí backend
  // materializaci záporné položky „Sleva X %" na každou sazbu). Server přepočítá
  // autoritativně při uložení; tohle je jen live náhled. discountAmount = úbytek
  // základu (bez DPH) v obou režimech.
  const pct = Math.min(100, Math.max(0, form.value.discount_percent || 0))
  let discountAmount = 0
  if (pct > 0) {
    for (const b of breakdown.values()) {
      if (pricesIncl) {
        // V režimu „ceny s DPH" je sleva procento z hrubé částky; daň se dopočte
        // shora z hrubé částky po slevě (koeficient), konzistentně s backendem.
        const gross = round2(b.base + b.vat)
        const discGross = round2(gross * (pct / 100))
        if (discGross === 0) continue
        const newGross = round2(gross - discGross)
        const newVat = round2(newGross * b.rate / (100 + b.rate))
        const newBase = round2(newGross - newVat)
        discountAmount = round2(discountAmount + round2(b.base - newBase))
        b.base = newBase
        b.vat = newVat
      } else {
        const disc = round2(b.base * (pct / 100))
        if (disc === 0) continue
        b.base = round2(b.base - disc)
        b.vat = round2(b.vat - round2(disc * (b.rate / 100)))
        discountAmount = round2(discountAmount + disc)
      }
    }
  }

  let totalBase = 0
  let totalVat = 0
  for (const b of breakdown.values()) {
    totalBase = round2(totalBase + b.base)
    totalVat = round2(totalVat + b.vat)
  }

  return {
    without_vat: totalBase,
    vat: totalVat,
    with_vat: round2(totalBase + totalVat),
    discount_percent: pct,
    discount_amount: discountAmount,
    amount_to_pay: round2(totalBase + totalVat - form.value.advance_paid_amount),
    breakdown: Array.from(breakdown.values())
      .map(b => ({ rate: b.rate, base: round2(b.base), vat: round2(b.vat) }))
      .sort((a, b) => b.rate - a.rate),
  }
})

const requiresPositiveAmountToPay = computed(() => {
  if (form.value.invoice_type === 'proforma') return true
  if (form.value.invoice_type !== 'invoice') return false
  return !form.value.parent_invoice_id
})

const hasNonPositiveAmountToPay = computed(() =>
  requiresPositiveAmountToPay.value && computed_totals.value.amount_to_pay <= 0
)

// Per-row check: záporné množství a záporná cena současně backend odmítne;
// chceme to uživateli ukázat live, ne až při submitu.
function itemHasBothNegative(item: InvoiceItem): boolean {
  return Number(item.quantity) < 0 && Number(item.unit_price_without_vat) < 0
}

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

/**
 * Řádkové „Celkem" — u plátce DPH včetně DPH (aby bylo vidět, že sazba DPH má efekt;
 * net základ + DPH je v souhrnu níže). U neplátce / reverse-charge je sazba 0 → = základ.
 */
function itemTotal(item: InvoiceItem): number {
  const amount = round2(Number(item.quantity) * Number(item.unit_price_without_vat))
  // Režim „ceny s DPH": unit_price_without_vat nese cenu S DPH → řádkové „Celkem s DPH" = amount.
  if (form.value.prices_include_vat && supplierIsVatPayer.value) return amount
  const vatRate = (form.value.reverse_charge || !supplierIsVatPayer.value)
    ? 0
    : (vatRates.value.find(v => v.id === item.vat_rate_id)?.rate_percent ?? 0)
  // base*rate/100 (dělit až nakonec), shodně s backendem InvoiceMath — viz issue #82.
  return round2(amount + round2(amount * vatRate / 100))
}

/**
 * Zadání částky s DPH na řádku „Celkem s DPH" → dopočte jednotkovou cenu.
 * Přepínač „ceny s DPH" záměrně NEpřepínáme — respektujeme aktuální režim faktury:
 *  • režim „ceny s DPH" zapnutý → unit_price nese gross → uložíme gross / množství,
 *  • režim vypnutý (běžný) → z gross odečteme DPH shora a uložíme netto / množství.
 * Server přepočítá autoritativně. Podporuje výrazy přes evalMath.
 */
function setItemGross(item: InvoiceItem, raw: string): void {
  const gross = evalMath(raw)
  if (gross === null) return
  const qty = Number(item.quantity) || 0
  if (qty === 0) return
  if (form.value.prices_include_vat && supplierIsVatPayer.value) {
    // unit_price_without_vat nese cenu S DPH → ulož gross jako jednotkovou cenu.
    item.unit_price_without_vat = round2(gross / qty)
    return
  }
  // Běžný režim: dopočti netto odečtením DPH shora (u neplátce / RC je sazba 0).
  const vatRate = (form.value.reverse_charge || !supplierIsVatPayer.value)
    ? 0
    : (vatRates.value.find(v => v.id === item.vat_rate_id)?.rate_percent ?? 0)
  const net = gross / (1 + vatRate / 100)
  item.unit_price_without_vat = round2(net / qty)
}

// Přepínač „ceny s DPH" má smysl jen pro plátce DPH (u neplátce/RC je sazba 0 → gross = net).
const showPricesIncludeVatUI = computed(() => supplierIsVatPayer.value)
// Záhlaví sloupce jednotkové ceny — v režimu „ceny s DPH" je to cena včetně DPH.
const unitPriceHeaderLabel = computed(() => form.value.prices_include_vat && supplierIsVatPayer.value
  ? t('invoice.items_table.unit_price_gross')
  : t('invoice.items_table.unit_price'))

// ─── WORK REPORT ────────────────────────────────────────────────
const wrOpen = ref(false)
const wrTitle = ref('')
const wrItems = ref<WorkReportItem[]>([])
const wrVatRateId = ref<number | null>(null)

// ─── MATERIAL REPORT ────────────────────────────────────────────
const matOpen = ref(false)
const matTitle = ref('')
const matItems = ref<WorkReportMaterial[]>([])
const matVatRateId = ref<number | null>(null)

function vatRateIdByPercent(p: number): number | null {
  const r = vatRates.value.find(x => Math.round(Number(x.rate_percent)) === p && !x.is_reverse_charge)
  return r ? r.id : null
}

async function loadWorkReport() {
  if (!invoiceId.value) return
  const wr = await invoicesApi.getWorkReport(invoiceId.value)
  if (wr) {
    wrTitle.value = wr.title
    wrItems.value = wr.items.map(i => ({ ...i }))
    // Neplátce DPH: vždy 0% „Osvobozeno" (defaultVatRateId()), ať se do položek faktury
    // nepropíše DPH — sazba je pro něj skrytá. Plátce: uložená → 21 % → fallback.
    wrVatRateId.value = supplierIsVatPayer.value
      ? (wr.vat_rate_id ?? vatRateIdByPercent(21) ?? defaultVatRateId())
      : defaultVatRateId()
    if (wr.items.length > 0) wrOpen.value = true
    // Materiál
    if (wr.material_title) matTitle.value = wr.material_title
    matItems.value = (wr.materials ?? []).map(m => ({ ...m }))
    matVatRateId.value = supplierIsVatPayer.value
      ? (wr.material_vat_rate_id ?? vatRateIdByPercent(12) ?? defaultVatRateId())
      : defaultVatRateId()
    if (matItems.value.length > 0) matOpen.value = true
  }
}

// Pro výpočty + uložení: jen řádky s vyplněným popisem. Prázdné řádky uživatel
// typicky nevyplní (přidal Přidat řádek a zapomněl), automaticky je ignorujeme,
// aby totals v položce faktury seděly s tím, co se opravdu uloží.
const wrItemsValid = computed(() => wrItems.value.filter(i => (i.description || '').trim() !== ''))
const wrTotalHours = computed(() => wrItemsValid.value.reduce((s, i) => s + (Number(i.hours) || 0), 0))
const wrTotalAmount = computed(() => wrItemsValid.value.reduce((s, i) => s + (Number(i.hours) || 0) * (Number(i.rate) || 0), 0))

function addWrItem() {
  // 1. project hourly rate, 2. client hourly rate, 3. existing WR row rate, 4. default 1500
  const projectRate = projects.value.find(p => p.id === form.value.project_id)?.hourly_rate
  const clientRate = clients.value.find(c => c.id === form.value.client_id)?.hourly_rate
  const previousRate = wrItems.value[wrItems.value.length - 1]?.rate
  const defaultRate = (projectRate && projectRate > 0) ? projectRate
    : (clientRate && clientRate > 0) ? clientRate
    : (previousRate && previousRate > 0) ? previousRate
    : 1500
  wrItems.value.push({ description: '', hours: 1, rate: defaultRate, order_index: wrItems.value.length })
  focusLastRow('[data-row-input="inv-wr"]')
}
function removeWrItem(idx: number) {
  wrItems.value.splice(idx, 1)
}

function moveWrItem(idx: number, dir: -1 | 1) {
  const newIdx = idx + dir
  if (newIdx < 0 || newIdx >= wrItems.value.length) return
  const [item] = wrItems.value.splice(idx, 1)
  wrItems.value.splice(newIdx, 0, item)
}
function openWorkReport() {
  if (wrVatRateId.value == null) wrVatRateId.value = supplierIsVatPayer.value ? (vatRateIdByPercent(21) ?? defaultVatRateId()) : defaultVatRateId()
  if (wrItems.value.length === 0) {
    const date = (form.value.tax_date || form.value.issue_date || '').slice(0, 7) // YYYY-MM
    wrTitle.value = date ? t('invoice.wr_title_with_date', { date }) : t('invoice.work_report')
    addWrItem()
  }
  wrOpen.value = true
}

// Přenese sumu výkazu jako jednu položku faktury (popis = title výkazu, qty = 1, cena = celková suma výkazu).
// Pokud už existuje položka se stejným popisem (= title výkazu), AKTUALIZUJE ji
// (množství / cena / DPH zůstává); jinak přidá novou. Tím se opětovné kliknutí
// "Přenést jako položku faktury" po editaci výkazu chová jako sync, ne jako duplicate.
function pushWrToInvoiceItem() {
  if (wrItemsValid.value.length === 0) return
  const totalAmount = wrTotalAmount.value
  const defaultVatId = defaultVatRateId()
  const description = wrTitle.value || t('invoice.work_report')
  // Cíleně "ks" (kus) — výkaz se přenáší jako 1 × celková suma.
  // Když uživatel "ks" v číselníku nemá, fallback na literál (přidá free-text).
  const unit = units.value.find(u => u.code === 'ks')?.code || 'ks'

  // 1. Položka se shodným popisem → sync (aktualizace ceny).
  // 2. Jinak prázdná položka (z blankItem na nové faktuře) → naplň ji, ne push.
  //    Cena se ignoruje — blankItem default cenu předvyplňuje z project.hourly_rate
  //    (nebo client.hourly_rate fallback), takže placeholder typicky cenu má.
  // 3. Jinak nová položka.
  const existing = form.value.items.find(it => (it.description || '').trim() === description.trim())
  const empty = !existing
    ? form.value.items.find(it => (it.description || '').trim() === '')
    : undefined
  const target = existing || empty

  if (target) {
    target.description = description
    target.quantity = 1
    target.unit = unit
    target.unit_price_without_vat = totalAmount
    // Sazba DPH práce dle volby výkazu (uživatel ji explicitně nastavuje selectorem).
    if (wrVatRateId.value != null) target.vat_rate_id = wrVatRateId.value
  } else {
    form.value.items.push({
      description,
      quantity: 1,
      unit,
      unit_price_without_vat: totalAmount,
      vat_rate_id: wrVatRateId.value ?? defaultVatId,
      order_index: form.value.items.length,
    })
  }
}

async function deleteWorkReport() {
  if (!confirm(t('invoice.wr_delete_confirm'))) return
  // Pokud je faktura už uložená, smaž i z DB; jinak jen lokálně.
  if (invoiceId.value) {
    try {
      await invoicesApi.deleteWorkReport(invoiceId.value, forceEdit.value)
    } catch (e: any) {
      // 404 = výkaz v DB neexistuje (nový), pokračuj s lokálním clear
      if (e?.response?.status !== 404) {
        error.value = apiErrorMessage(e, t('invoice.wr_delete_failed'))
        return
      }
    }
  }
  wrItems.value = []
  wrTitle.value = ''
  wrOpen.value = false
}

/**
 * Pokud uživatel má otevřený výkaz s položkami, ověř jestli odpovídá faktuře.
 * Vrací null = OK, jinak warning string pro confirm().
 */
function checkWorkReportSync(): string | null {
  if (!wrOpen.value || wrItemsValid.value.length === 0) return null
  const totalHours = Math.round(wrTotalHours.value * 100) / 100
  const totalAmount = Math.round(wrTotalAmount.value * 100) / 100
  const description = (wrTitle.value || t('invoice.work_report')).trim()
  if (description === '') return null

  const ccy = currencies.value.find(c => c.id === form.value.currency_id)?.code || ''
  const loc = locale.value === 'cs' ? 'cs' : 'en-US'
  const item = form.value.items.find(it => (it.description || '').trim() === description)

  if (!item) {
    return t('invoice.wr_not_in_items_confirm', {
      description,
      hours: totalHours,
      amount: totalAmount.toLocaleString(loc),
      ccy,
    })
  }

  const itemQty = Number(item.quantity) || 0
  const itemRate = Number(item.unit_price_without_vat) || 0
  const itemAmount = Math.round(itemQty * itemRate * 100) / 100
  const amountDiff = Math.abs(itemAmount - totalAmount) > 0.01

  if (amountDiff) {
    return t('invoice.wr_diff_confirm', {
      hours: totalHours,
      amount: totalAmount.toLocaleString(loc),
      itemAmount: itemAmount.toLocaleString(loc),
      ccy,
    })
  }
  return null
}

// ── Materiál: řádky + přenos ───────────────────────────────────────────
const matItemsValid = computed(() => matItems.value.filter(m => (m.description || '').trim() !== '' && (Number(m.quantity) || 0) > 0))
const matTotal = computed(() => matItemsValid.value.reduce((s, m) => s + Math.round((Number(m.quantity) || 0) * (Number(m.unit_price) || 0) * 100) / 100, 0))

/**
 * Obdoba checkWorkReportSync pro materiál: pokud má uživatel otevřený výkaz materiálu
 * s řádky, ověř že odpovídá položce faktury. Vrací null = OK, jinak warning pro confirm().
 */
function checkMaterialReportSync(): string | null {
  if (!matOpen.value || matItemsValid.value.length === 0) return null
  const total = Math.round(matTotal.value * 100) / 100
  const description = (matTitle.value || t('invoice.wr_material_title')).trim()
  if (description === '') return null

  const ccy = currencies.value.find(c => c.id === form.value.currency_id)?.code || ''
  const loc = locale.value === 'cs' ? 'cs' : 'en-US'
  const item = form.value.items.find(it => (it.description || '').trim() === description)

  if (!item) {
    return t('invoice.wr_material_not_in_items_confirm', {
      description,
      amount: total.toLocaleString(loc),
      ccy,
    })
  }

  const itemAmount = Math.round((Number(item.quantity) || 0) * (Number(item.unit_price_without_vat) || 0) * 100) / 100
  if (Math.abs(itemAmount - total) > 0.01) {
    return t('invoice.wr_material_diff_confirm', {
      amount: total.toLocaleString(loc),
      itemAmount: itemAmount.toLocaleString(loc),
      ccy,
    })
  }
  return null
}

function addMatItem() {
  matItems.value.push({
    description: '',
    quantity: 1,
    unit: units.value.find(u => u.code === 'ks')?.code || 'ks',
    unit_price: 0,
    order_index: matItems.value.length,
  })
  focusLastRow('[data-row-input="inv-mat"]')
}
function removeMatItem(idx: number) { matItems.value.splice(idx, 1) }
function moveMatItem(idx: number, dir: -1 | 1) {
  const newIdx = idx + dir
  if (newIdx < 0 || newIdx >= matItems.value.length) return
  const [item] = matItems.value.splice(idx, 1)
  matItems.value.splice(newIdx, 0, item)
}
function openMaterial() {
  if (matVatRateId.value == null) matVatRateId.value = supplierIsVatPayer.value ? (vatRateIdByPercent(12) ?? defaultVatRateId()) : defaultVatRateId()
  if (!matTitle.value) matTitle.value = t('invoice.wr_material_title')
  if (matItems.value.length === 0) addMatItem()
  matOpen.value = true
}

// Přenese sumu materiálu jako jednu položku faktury (popis = material_title, qty=1, cena = celkem).
function pushMatToInvoiceItem() {
  if (matItemsValid.value.length === 0) return
  const total = matTotal.value
  const description = (matTitle.value || t('invoice.wr_material_title')).trim()
  const unit = units.value.find(u => u.code === 'ks')?.code || 'ks'
  const existing = form.value.items.find(it => (it.description || '').trim() === description)
  const empty = !existing ? form.value.items.find(it => (it.description || '').trim() === '') : undefined
  const target = existing || empty
  if (target) {
    target.description = description
    target.quantity = 1
    target.unit = unit
    target.unit_price_without_vat = total
    if (matVatRateId.value != null) target.vat_rate_id = matVatRateId.value
  } else {
    form.value.items.push({
      description,
      quantity: 1,
      unit,
      unit_price_without_vat: total,
      vat_rate_id: matVatRateId.value ?? defaultVatRateId(),
      order_index: form.value.items.length,
    })
  }
}

async function deleteMaterial() {
  if (!confirm(t('invoice.wr_delete_confirm'))) return
  // Vyprázdnění materiálu se uloží přes saveWorkReportMaterials([]) při submitu;
  // tady jen lokální clear (řádka work_reports zůstává kvůli práci).
  if (invoiceId.value) {
    try {
      await invoicesApi.saveWorkReportMaterials(invoiceId.value, {
        project_id: form.value.project_id,
        material_title: matTitle.value || t('invoice.wr_material_title'),
        material_vat_rate_id: matVatRateId.value,
        materials: [],
      }, forceEdit.value)
    } catch (e: any) {
      if (e?.response?.status !== 404) {
        error.value = apiErrorMessage(e, t('invoice.wr_delete_failed'))
        return
      }
    }
  }
  matItems.value = []
  matOpen.value = false
}

// ── Přílohy faktury ────────────────────────────────────────────────────
// Nová faktura: upload potřebuje id, které vznikne až po create → soubory
//   držíme v prohlížeči (pendingAttachments) a nahrajeme je v submit() po create.
// Editace: id už existuje → načteme existující a přidání/mazání řešíme hned (jako detail).
// Limity musí sedět s api UploadAttachmentAction.
const ATTACH_MAX_FILE = 10 * 1024 * 1024   // 10 MiB / soubor
const ATTACH_MAX_TOTAL = 20 * 1024 * 1024  // 20 MiB celkem
const pendingAttachments = ref<File[]>([])          // staging u nové faktury
const attachments = ref<InvoiceAttachment[]>([])     // existující (edit mód)
const attachmentsBusy = ref(false)
const attachmentDragOver = ref(false)
const attachmentsAllowed = computed(() =>
  ['invoice', 'proforma', 'credit_note'].includes(form.value.invoice_type))

function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} kB`
  return `${(n / 1024 / 1024).toFixed(1)} MB`
}
async function loadAttachments() {
  if (!invoiceId.value) return
  try { attachments.value = await invoicesApi.listAttachments(invoiceId.value) } catch { /* ignore */ }
}
// Editace: id existuje → nahraj rovnou (server validuje mime/velikost).
async function uploadNow(files: File[]) {
  if (!invoiceId.value || files.length === 0) return
  attachmentsBusy.value = true
  try {
    const r = await invoicesApi.uploadAttachments(invoiceId.value, files)
    attachments.value = r.items
    toast.success(t('invoice.attachments.upload_done', { n: r.created.length }))
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('invoice.attachments.upload_failed')))
  } finally {
    attachmentsBusy.value = false
  }
}
// Nová faktura: ulož do prohlížeče (klientská kontrola limitů), nahraje se po create.
function stagePending(files: File[]) {
  let total = pendingAttachments.value.reduce((s, f) => s + f.size, 0)
  for (const f of files) {
    if (f.size > ATTACH_MAX_FILE) { toast.warning(t('invoice.attachments.too_large', { name: f.name })); continue }
    if (total + f.size > ATTACH_MAX_TOTAL) { toast.warning(t('invoice.attachments.total_too_large')); break }
    pendingAttachments.value.push(f)
    total += f.size
  }
}
function addAttachmentFiles(files: File[]) {
  if (files.length === 0) return
  if (isEdit.value) void uploadNow(files)
  else stagePending(files)
}
function removePendingAttachment(i: number) { pendingAttachments.value.splice(i, 1) }
async function deleteAttachment(att: InvoiceAttachment) {
  if (!invoiceId.value) return
  if (!window.confirm(t('invoice.attachments.confirm_delete', { name: att.original_name }))) return
  try {
    await invoicesApi.deleteAttachment(invoiceId.value, att.id)
    attachments.value = attachments.value.filter(a => a.id !== att.id)
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('invoice.attachments.delete_failed')))
  }
}
function onAttachmentInputChange(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files) addAttachmentFiles(Array.from(input.files))
  input.value = ''
}
function onAttachmentDrop(e: DragEvent) {
  e.preventDefault()
  attachmentDragOver.value = false
  if (e.dataTransfer?.files) addAttachmentFiles(Array.from(e.dataTransfer.files))
}

async function submit() {
  // Uzamčený doklad: fieldset[disabled] blokuje UI, ale ctrl+s hotkey volá
  // submit() přímo — guard tu musí být taky.
  if (isLocked.value) return
  // Tiše vyhoď prázdné řádky (bez popisu i bez ceny) — uživatel přidal řádek a nezapsal ho.
  // Zároveň smaž z form.value.items, ať checkWorkReportSync vidí stejnou množinu jako payload.
  form.value.items = form.value.items.filter(it =>
    (it.description || '').trim() !== '' || (Number(it.unit_price_without_vat) || 0) !== 0
  )
  form.value.items.forEach((it, i) => (it.order_index = i))

  // Detekce nesouladu mezi výkazem a položkou faktury — uživatel má šanci se vrátit
  const wrWarning = checkWorkReportSync()
  if (wrWarning && !confirm(wrWarning)) return
  const matWarning = checkMaterialReportSync()
  if (matWarning && !confirm(matWarning)) return

  if (hasNonPositiveAmountToPay.value) {
    error.value = t('invoice.amount_positive_required')
    return
  }

  submitting.value = true
  error.value = ''
  try {
    const payload: InvoicePayload = {
      invoice_type: form.value.invoice_type,
      client_id: form.value.client_id!,
      project_id: form.value.project_id,
      issue_date: form.value.issue_date,
      tax_date: form.value.invoice_type === 'proforma' ? null : form.value.tax_date,
      due_date: form.value.due_date,
      currency_id: form.value.currency_id,
      reverse_charge: form.value.reverse_charge,
      prices_include_vat: form.value.prices_include_vat,
      income_tax_exempt: form.value.income_tax_exempt,
      income_tax_exempt_reason: form.value.income_tax_exempt ? (form.value.income_tax_exempt_reason || null) : null,
      language: form.value.language,
      note_above_items: form.value.note_above_items || null,
      note_below_items: form.value.note_below_items || null,
      advance_paid_amount: form.value.advance_paid_amount,
      discount_percent: form.value.discount_percent || 0,
      payment_method: form.value.payment_method,
      auto_send_reminders: form.value.auto_send_reminders,
      // Kurz posílej jen když ho uživatel skutečně změnil — backend explicitní hodnotu
      // bere jako ruční override a nepřenačte kurz ČNB po změně DUZP / data / měny.
      exchange_rate: userChangedExchangeRate() ? form.value.exchange_rate ?? undefined : undefined,
      // Volitelný ruční varsymbol — backend ho akceptuje jen u draftu;
      // prázdný řetězec → backend uloží NULL a vygeneruje při issue automaticky.
      varsymbol: form.value.varsymbol.trim(),
      vat_classification_code: form.value.vat_classification_code,
      revenue_category: form.value.revenue_category,
      revenue_category_id: form.value.revenue_category_id,
      items: form.value.items.map((it, i) => ({
        description: it.description,
        quantity: it.quantity,
        unit: it.unit,
        unit_price_without_vat: it.unit_price_without_vat,
        vat_rate_id: it.vat_rate_id,
        order_index: i,
        oss_applicable: it.oss_applicable ?? false,
        oss_consumer_country: it.oss_applicable ? (it.oss_consumer_country || null) : null,
        oss_rate_type: it.oss_applicable ? (it.oss_rate_type || 'standard') : null,
        oss_supply_type: it.oss_applicable ? (it.oss_supply_type || 'goods') : null,
        oss_exchange_rate: it.oss_applicable ? (it.oss_exchange_rate ?? null) : null,
        oss_exchange_rate_date: it.oss_applicable ? (it.oss_exchange_rate_date ?? null) : null,
        oss_taxable_amount_return: it.oss_applicable ? (it.oss_taxable_amount_return ?? null) : null,
        oss_vat_amount_return: it.oss_applicable ? (it.oss_vat_amount_return ?? null) : null,
        oss_original_period: it.oss_applicable ? (it.oss_original_period ?? null) : null,
      })),
    }

    let saved: Invoice
    if (isEdit.value && invoiceId.value) {
      saved = await invoicesApi.update(invoiceId.value, payload, forceEdit.value)
    } else {
      saved = await invoicesApi.create(payload)
    }

    // EUR / cizí měna: backend stáhl kurz ČNB. Pokud byl použit fallback
    // (víkend, svátek nebo last-known kurz), upozorni uživatele.
    const rateMeta = saved._meta?.exchange_rate
    if (rateMeta?.fallback_used) {
      const rateStr = rateMeta.rate.toLocaleString(locale.value === 'cs' ? 'cs-CZ' : 'en-US', {
        minimumFractionDigits: 3, maximumFractionDigits: 4,
      })
      const dateStr = new Date(rateMeta.rate_date).toLocaleDateString(locale.value === 'cs' ? 'cs-CZ' : 'en-US')
      const key = rateMeta.source === 'last_known'
        ? 'invoice.czk_recap.warning_last_known'
        : 'invoice.czk_recap.warning_fallback'
      toast.warning(t(key, { rate: rateStr, currency: rateMeta.currency, date: dateStr }))
    }
    // Po uložení faktury — pokud uživatel otevřel work report, ulož ho
    // (jen řádky s vyplněným popisem; prázdné řádky tiše ignorujeme — viz wrItemsValid)
    if (wrOpen.value && wrItemsValid.value.length > 0) {
      try {
        await invoicesApi.saveWorkReport(saved.id, {
          project_id: saved.project_id,
          title: wrTitle.value,
          vat_rate_id: wrVatRateId.value,
          items: wrItemsValid.value.map((it, i) => ({
            description: it.description,
            work_date: it.work_date || null,
            hours: Number(it.hours) || 0,
            rate: Number(it.rate) || 0,
            order_index: i,
          })),
        }, forceEdit.value)
      } catch (e: any) {
        // Faktura je uložená, výkaz ne — nepokračuj v redirectu, ať uživatel nepřijde o data ve formuláři
        error.value = apiErrorMessage(e, t('invoice.wr_save_failed'))
        return
      }
    }
    // Výkaz materiálu — uloží se nezávisle (vlastní endpoint, sdílí work_reports řádku).
    if (matOpen.value && matItemsValid.value.length > 0) {
      try {
        await invoicesApi.saveWorkReportMaterials(saved.id, {
          project_id: saved.project_id,
          material_title: matTitle.value || t('invoice.wr_material_title'),
          material_vat_rate_id: matVatRateId.value,
          materials: matItemsValid.value.map((m, i) => ({
            description: m.description,
            quantity: Number(m.quantity) || 0,
            unit: (m.unit || 'ks').trim(),
            unit_price: Number(m.unit_price) || 0,
            order_index: i,
          })),
        }, forceEdit.value)
      } catch (e: any) {
        error.value = apiErrorMessage(e, t('invoice.wr_save_failed'))
        return
      }
    }
    // Přílohy nasbírané u nové faktury (držené v prohlížeči) — nahraj teď, když známe id.
    // Selhání uploadu nesmí shodit už vytvořenou fakturu → jen upozorni, pokračuj na detail.
    if (pendingAttachments.value.length > 0) {
      try {
        await invoicesApi.uploadAttachments(saved.id, pendingAttachments.value)
        pendingAttachments.value = []
      } catch (e: any) {
        toast.warning(apiErrorMessage(e, t('invoice.attachments.post_save_failed')))
      }
    }
    router.push(`/invoices/${saved.id}`)
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('common.save_failed'))
    // Toast + scroll k bannéru — uživatel může být odscrollovaný dole u tlačítka Uložit.
    toast.error(error.value)
    await nextTick()
    document.querySelector('[data-error-banner]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  } finally {
    submitting.value = false
  }
}

async function deleteDraft() {
  if (!invoiceId.value) return
  if (!confirm(t('invoice.delete_draft_confirm'))) return
  try {
    await invoicesApi.delete(invoiceId.value)
    router.push('/invoices')
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('common.delete_failed'))
  }
}
</script>

<template>
  <div v-if="!loaded" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

  <div v-else class="max-w-5xl">
    <div class="flex items-center justify-between mb-4">
      <div>
        <RouterLink to="/invoices" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('invoice.back_to_list') }}</RouterLink>
        <h1 class="text-2xl font-semibold mt-1">
          {{ editorTitle }}
          <span class="text-sm font-normal text-neutral-500 ml-2">
            <span v-if="form.invoice_type === 'proforma'" class="px-2 py-0.5 bg-accent-100 text-accent-600 rounded">{{ t('type.proforma') }}</span>
            <span v-else-if="form.invoice_type === 'credit_note'" class="px-2 py-0.5 bg-danger-50 text-danger-500 rounded">{{ t('type.credit_note') }}</span>
            <span v-else-if="editedStatus !== 'draft'" class="px-2 py-0.5 bg-warning-50 text-warning-600 rounded">{{ t(`status.${editedStatus}`) }}</span>
            <span v-else class="px-2 py-0.5 bg-neutral-100 text-neutral-600 rounded">{{ t('status.draft') }}</span>
          </span>
        </h1>
      </div>
      <button v-if="isEdit && editedStatus === 'draft'" @click="deleteDraft" class="text-sm text-danger-500 hover:text-danger-600 cursor-pointer">
        {{ t('invoice.delete_draft_btn') }}
      </button>
    </div>

    <!-- Uzamčený doklad (issued/sent/paid…): admin má tlačítko k odemčení, ostatní jen info -->
    <div v-if="isLocked" class="mb-4 rounded-md border border-warning-500/50 bg-warning-50 p-4">
      <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-warning-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"/></svg>
        <div class="text-sm text-warning-600 flex-1">
          <div class="font-semibold mb-1">{{ t('invoice.locked_title', { status: editedStatus }) }}</div>
          <p>{{ isAdmin ? t('invoice.locked_body') : t('invoice.locked_body_nonadmin') }}</p>
        </div>
        <button v-if="isAdmin" type="button" @click="unlockModalOpen = true"
          class="cursor-pointer shrink-0 h-9 px-4 bg-warning-500 hover:bg-warning-600 text-white text-sm font-medium rounded-md">
          {{ t('invoice.unlock_button') }}
        </button>
      </div>
    </div>

    <!-- Banner odemčené editace vystavené faktury (po potvrzení v modalu) -->
    <div v-else-if="forceEdit && editedStatus !== 'draft'" class="mb-4 rounded-md border border-warning-500/50 bg-warning-50 p-4">
      <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-warning-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
        <div class="text-sm text-warning-600">
          <div class="font-semibold mb-1">{{ t('invoice.edit_issued_warning') }}</div>
          <p>{{ t('invoice.edit_issued_body', { varsymbol: editedVarsymbol, status: editedStatus }) }}</p>
        </div>
      </div>
    </div>

    <form @submit.prevent="submit" class="space-y-4">
      <!-- Uzamčený doklad: fieldset[disabled] vypne všechny vstupy i submit;
           display:contents nechává grid/spacing layout beze změny. -->
      <fieldset :disabled="isLocked" class="contents">
      <!-- Klient + zakázka + datumy -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('invoice.client') }} &amp; {{ t('invoice.project') }}</h3>
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.doc_type') }} *</label>
              <select v-model="form.invoice_type" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
                <option value="invoice">{{ t('invoice.doc_invoice') }}</option>
                <option value="proforma">{{ t('invoice.doc_proforma') }}</option>
                <option value="credit_note">{{ t('invoice.doc_credit_note') }}</option>
              </select>
              <p v-if="form.invoice_type === 'credit_note'" class="text-xs text-warning-600 mt-1">
                {{ t('invoice.credit_note_warning') }}
              </p>
              <p v-if="typeWillRenumber" class="text-xs text-warning-600 mt-1">
                {{ t('invoice.type_change_renumber', { varsymbol: editedVarsymbol ?? '' }) }}
              </p>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.client') }} *</label>
              <div class="flex gap-2">
                <div class="flex-1 min-w-0">
                  <SearchableSelect
                    :model-value="form.client_id"
                    @update:model-value="(v) => { form.client_id = v; onClientChange() }"
                    remote
                    :loading="clientsLoading"
                    :options="clientOptions"
                    :selected-option="selectedClientOption"
                    @search="onClientSearch"
                    :placeholder="t('invoice.select_client')"
                    :clearable="false"
                  />
                </div>
                <button type="button" @click="clientModalOpen = true"
                  class="cursor-pointer shrink-0 h-9 px-3 inline-flex items-center gap-1.5 border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md text-sm font-medium"
                  :title="t('client.new_title')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                  </svg>
                  <span class="hidden sm:inline">{{ t('client.new_title') }}</span>
                </button>
              </div>
              <!-- VIES výsledek -->
              <div v-if="viesResult" class="mt-1 text-xs flex items-start gap-1.5">
                <template v-if="viesResult.status === 'checking'">
                  <span class="text-neutral-500">{{ t('invoice.vies.checking', { dic: viesResult.dic }) }}</span>
                </template>
                <template v-else-if="viesResult.status === 'valid'">
                  <svg class="w-4 h-4 text-success-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                  <span class="text-success-600">{{ t('invoice.vies.valid', { dic: viesResult.dic }) }}<span v-if="viesResult.name" class="text-neutral-500"> — {{ viesResult.name }}</span></span>
                </template>
                <template v-else-if="viesResult.status === 'invalid'">
                  <svg class="w-4 h-4 text-danger-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                  <span class="text-danger-500">{{ t('common.dic') }} <span class="font-mono">{{ viesResult.dic }}</span>: {{ viesResult.message }}</span>
                </template>
                <template v-else-if="viesResult.status === 'error'">
                  <span class="text-warning-600">⚠ {{ viesResult.message }}</span>
                </template>
                <template v-else-if="viesResult.status === 'no_dic'">
                  <span class="text-neutral-400">{{ t('invoice.vies.no_dic') }}</span>
                </template>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.project') }}</label>
              <div class="flex gap-2">
                <div class="flex-1 min-w-0">
                  <SearchableSelect
                    :model-value="form.project_id"
                    @update:model-value="(v) => { form.project_id = v; onProjectChange() }"
                    :options="projects.map(p => ({ value: p.id, label: p.name + (p.status !== 'active' ? ` (${p.status})` : ''), secondary: p.project_number ?? undefined }))"
                    :placeholder="t('invoice.no_project')"
                    :disabled="!form.client_id"
                  />
                </div>
                <button type="button" @click="projectModalOpen = true" :disabled="!form.client_id"
                  class="cursor-pointer shrink-0 h-9 px-3 inline-flex items-center gap-1.5 border border-primary-500/40 text-primary-700 hover:bg-primary-50 disabled:opacity-50 disabled:cursor-not-allowed rounded-md text-sm font-medium"
                  :title="t('project.new_title')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                  </svg>
                  <span class="hidden sm:inline">{{ t('invoice.new_project_short') }}</span>
                </button>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.currency') }}</label>
                <select v-model.number="form.currency_id" @change="onCurrencyChange"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
                  <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.label }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.language') }}</label>
                <select v-model="form.language" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
                  <option value="cs">CZ</option>
                  <option value="en">EN</option>
                </select>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('payment_method.label') }}</label>
              <select v-model="form.payment_method" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
                <option value="bank_transfer">{{ t('payment_method.bank_transfer') }}</option>
                <option value="card">{{ t('payment_method.card') }}</option>
                <option value="cash">{{ t('payment_method.cash') }}</option>
                <option value="other">{{ t('payment_method.other') }}</option>
              </select>
              <p v-if="form.payment_method !== 'bank_transfer'" class="text-xs text-warning-600 mt-1">
                {{ t('payment_method.hint') }}
              </p>
            </div>
            <div v-if="showReverseChargeUI">
              <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input v-model="form.reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                <span>{{ t('invoice.reverse_charge') }} ({{ t('invoice.totals.vat') }} 0 %)</span>
              </label>
              <!-- IO (#94): vysvětlení, proč na RC dokladu není sazba DPH — částky jsou
                   základ daně, samovyměřuje odběratel sazbou své země. -->
              <p v-if="!supplierIsVatPayer && form.reverse_charge" class="text-xs text-neutral-500 mt-1 ml-6">
                {{ t('invoice.reverse_charge_io_hint') }}
              </p>
            </div>
            <div v-if="showPricesIncludeVatUI">
              <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input v-model="form.prices_include_vat" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                <span>{{ t('invoice.prices_include_vat') }}</span>
              </label>
              <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('invoice.prices_include_vat_hint') }}</p>
            </div>
            <div v-if="showIncomeTaxExemptUI">
              <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input v-model="form.income_tax_exempt" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                <span>{{ t('invoice.income_tax_exempt') }}</span>
              </label>
              <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('invoice.income_tax_exempt_hint') }}</p>
              <div v-if="form.income_tax_exempt" class="ml-6 mt-2">
                <input
                  v-model="form.income_tax_exempt_reason"
                  type="text"
                  maxlength="190"
                  :placeholder="t('invoice.income_tax_exempt_reason_placeholder')"
                  class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm"
                />
              </div>
            </div>
          </div>
        </div>

        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('invoice.dates_section') }}</h3>
          <div class="space-y-3">
            <!-- Ruční override čísla faktury — jen u draftu; prázdné = vygeneruje se při Vystavení.
                 Placeholder ukazuje, jaké číslo dostane fakturu při Issue (z preview API).
                 Když není žádný template (ani per-supplier ani v cfg), ukáže warning. -->
            <div v-if="editedStatus === 'draft'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t(varsymbolLabelKey) }}</label>
              <input v-model="form.varsymbol" type="text" maxlength="20"
                :placeholder="varsymbolAutoPreview || t('invoice.varsymbol_placeholder')"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono" />
              <p v-if="!form.varsymbol && !varsymbolAutoHasTemplate" class="text-xs text-warning-600 mt-1">
                {{ t('invoice.varsymbol_no_template') }}
              </p>
              <p v-else-if="form.varsymbol.trim()" class="text-xs text-warning-600 mt-1">
                {{ t('invoice.varsymbol_manual_warning') }}
              </p>
              <p v-else class="text-xs text-neutral-500 mt-1">{{ t('invoice.varsymbol_hint') }}</p>
            </div>
            <div v-else-if="editedVarsymbol" class="rounded-md bg-neutral-50 border border-neutral-200 p-3 text-sm">
              <span class="text-neutral-500">{{ t(varsymbolLabelKey) }}:</span>
              <code class="ml-2 font-mono font-semibold">{{ editedVarsymbol }}</code>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.issue_date') }} *</label>
              <input v-model="form.issue_date" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            </div>
            <div v-if="form.invoice_type !== 'proforma'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.tax_date') }} *</label>
              <input v-model="form.tax_date" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            </div>
            <div v-else class="rounded-md bg-accent-50 border border-accent-100 p-3 text-sm text-accent-600">
              {{ t('invoice.proforma_no_tax_point') }}
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.due_date') }} *</label>
              <input v-model="form.due_date" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            </div>
            <div v-if="form.invoice_type !== 'credit_note' && remindersAvailable">
              <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input v-model="form.auto_send_reminders" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                <span>{{ t('invoice.auto_send_reminders') }}</span>
              </label>
              <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('invoice.auto_send_reminders_hint') }}</p>
            </div>
            <div v-if="form.currency !== 'CZK' && form.exchange_rate !== null && form.exchange_rate > 0">
              <label class="block text-sm font-medium text-neutral-700 mb-1">
                {{ t('invoice.exchange_rate_label', { currency: form.currency }) }}
              </label>
              <input v-model.number="form.exchange_rate" type="number" step="any" min="0"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono"
                @keydown.up.prevent @keydown.down.prevent
                @wheel="($event.target as HTMLInputElement).blur()" />
              <p class="text-xs text-neutral-500 mt-1">
                {{ t('invoice.exchange_rate_hint') }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Položky -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="px-5 py-3 border-b border-neutral-200 flex flex-wrap items-center justify-between gap-2">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.items') }}</h3>
          <div class="flex flex-wrap items-center justify-end gap-2">
            <template v-if="hasPriceList">
              <div class="w-80 max-w-full">
                <SearchableSelect
                  v-model="selectedPriceListItemId"
                  :options="priceListOptions"
                  :placeholder="t('invoice.price_list_select')"
                  :no-results-label="t('price_list.empty')"
                />
              </div>
              <button type="button" class="cursor-pointer inline-flex items-center justify-center h-8 px-3 border border-neutral-300 bg-surface hover:bg-neutral-50 disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium rounded-md" :disabled="!selectedPriceListItemId || resolvingPriceListItem" @click="addPriceListItem">
                {{ resolvingPriceListItem ? t('common.loading') : t('invoice.price_list_add') }}
              </button>
            </template>
            <button type="button" @click="addItem" class="px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md">
              {{ t('invoice.add_item') }}
            </button>
          </div>
        </div>
        <div v-if="requiresPositiveAmountToPay" class="px-5 py-3 border-b border-neutral-100 text-xs text-neutral-500">
          {{ t('invoice.negative_item_hint') }}
        </div>
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-8"></th>
              <th class="px-3 py-2 text-left font-medium">{{ t('invoice.items_table.description') }}</th>
              <th class="px-3 py-2 text-right font-medium w-20">{{ t('invoice.items_table.qty') }}</th>
              <th class="px-3 py-2 text-left font-medium w-16">{{ t('invoice.items_table.unit') }}</th>
              <th class="px-3 py-2 text-right font-medium w-32">{{ unitPriceHeaderLabel }}</th>
              <th v-if="supplierIsVatPayer" class="px-3 py-2 text-center font-medium w-24">{{ t('invoice.totals.vat') }}</th>
              <th class="px-3 py-2 text-right font-medium w-32">{{ supplierIsVatPayer ? t('invoice.items_table.total_incl_vat') : nonPayerTotalLabel }}</th>
              <th class="px-3 py-2" :class="ossAvailable ? 'w-24' : 'w-12'"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-200">
            <template v-for="(item, i) in form.items" :key="i">
            <tr :class="itemHasBothNegative(item) ? 'bg-danger-50' : ''">
              <td class="px-2 py-2 text-center text-xs text-neutral-400">
                <button type="button" @click="moveUp(i)" :disabled="i === 0" class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▲</button>
                <button type="button" @click="moveDown(i)" :disabled="i === form.items.length - 1" class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▼</button>
              </td>
              <td class="px-3 py-2">
                <textarea v-model="item.description" rows="1" data-row-input="inv-item" :placeholder="t('invoice.items_table.description')"
                  class="w-full px-2 py-1.5 border border-neutral-300 rounded text-sm resize-y min-h-[36px] focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"></textarea>
              </td>
              <td class="px-3 py-2">
                <input v-model="item.quantity" v-math type="text" inputmode="decimal"
                  :class="['w-full h-9 px-2 border rounded text-right font-mono text-sm', itemHasBothNegative(item) ? 'border-danger-400' : 'border-neutral-300']" />
              </td>
              <td class="px-3 py-2">
                <select v-model="item.unit" class="w-full h-9 px-1 border border-neutral-300 rounded text-sm bg-surface">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                  <option v-if="item.unit && !units.some(u => u.code === item.unit)" :value="item.unit">{{ item.unit }}</option>
                </select>
              </td>
              <td class="px-3 py-2">
                <input v-model="item.unit_price_without_vat" v-math type="text" inputmode="decimal"
                  :class="['w-full h-9 px-2 border rounded text-right font-mono text-sm', itemHasBothNegative(item) ? 'border-danger-400' : 'border-neutral-300']" />
              </td>
              <td v-if="supplierIsVatPayer" class="px-3 py-2">
                <select v-model.number="item.vat_rate_id" class="w-full h-9 px-1 border border-neutral-300 rounded text-sm bg-surface">
                  <option v-for="r in vatRatesForItem(item)" :key="r.id" :value="r.id">{{ vatRateLabel(r) }}</option>
                </select>
              </td>
              <td class="px-3 py-2">
                <input :value="itemTotal(item)" @change="setItemGross(item, ($event.target as HTMLInputElement).value)"
                  type="text" inputmode="decimal" :title="t('invoice.items_table.gross_edit_hint')"
                  class="w-full h-9 px-2 border border-neutral-300 rounded text-right font-mono text-sm" />
              </td>
              <td class="px-2 py-2">
                <div class="flex items-center justify-end gap-2">
                  <label v-if="ossAvailable || item.oss_applicable"
                    class="inline-flex shrink-0 items-center gap-1 text-xs text-neutral-600" :title="t('invoice.oss.enabled')">
                    <input v-model="item.oss_applicable" type="checkbox" class="rounded border-neutral-300 text-primary-600"
                      @change="onOssApplicableChange(item)" />
                    <span>{{ t('invoice.oss.enabled') }}</span>
                  </label>
                  <button type="button" @click="removeItem(i)" class="text-danger-500 hover:text-danger-600 text-lg leading-none">×</button>
                </div>
              </td>
            </tr>
            <!-- Číselníky OSS ve vlastním řádku pod položkou — inline vedle popisu by ho
                 zmáčkly na pár pixelů. Nezalamují se (flex-nowrap), při nedostatku místa
                 se vodorovně odrolují. Řádek nemá horní rámeček, ať drží u své položky. -->
            <tr v-if="item.oss_applicable" :class="['border-t-0!', itemHasBothNegative(item) ? 'bg-danger-50' : '']">
              <td></td>
              <td :colspan="supplierIsVatPayer ? 7 : 6" class="px-3 pb-2">
                <div class="flex flex-nowrap items-center gap-1.5 overflow-x-auto text-xs">
                  <input v-model="item.oss_consumer_country" type="text" maxlength="2"
                    :placeholder="t('invoice.oss.country')" :title="t('invoice.oss.country')"
                    class="w-11 h-7 shrink-0 px-1 border border-neutral-300 rounded text-xs text-center font-mono uppercase" />
                  <select v-model="item.oss_rate_type" :title="t('invoice.oss.rate_type')"
                    class="h-7 shrink-0 px-1 border border-neutral-300 rounded text-xs bg-surface">
                    <option value="standard">{{ t('invoice.oss.rate_standard') }}</option>
                    <option value="reduced">{{ t('invoice.oss.rate_reduced') }}</option>
                    <option value="second_reduced">{{ t('invoice.oss.rate_second_reduced') }}</option>
                    <option value="parking">{{ t('invoice.oss.rate_parking') }}</option>
                  </select>
                  <select v-model="item.oss_supply_type" :title="t('invoice.oss.supply_type')"
                    class="h-7 shrink-0 px-1 border border-neutral-300 rounded text-xs bg-surface">
                    <option value="goods">{{ t('invoice.oss.goods') }}</option>
                    <option value="services">{{ t('invoice.oss.services') }}</option>
                  </select>
                  <select v-model="item.oss_original_period" :title="t('invoice.oss.original_period')"
                    class="h-7 shrink-0 px-1 border border-neutral-300 rounded text-xs bg-surface">
                    <option :value="null">{{ t('invoice.oss.current_period') }}</option>
                    <option v-if="item.oss_original_period && !ossOriginalPeriodOptions.some(o => o.value === item.oss_original_period)"
                      :value="item.oss_original_period">{{ item.oss_original_period }}</option>
                    <option v-for="period in ossOriginalPeriodOptions" :key="period.value" :value="period.value">{{ period.label }}</option>
                  </select>
                </div>
              </td>
            </tr>
            </template>
            <tr v-if="form.items.length === 0">
              <td :colspan="supplierIsVatPayer ? 8 : 7" class="px-4 py-6 text-center text-neutral-400 text-sm">
                {{ t('invoice.no_items') }} <button type="button" @click="addItem" class="text-primary-600 hover:underline">{{ t('invoice.add_first') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: stack karet (každé pole na vlastním řádku, čitelné inputy) -->
        <div class="md:hidden divide-y divide-neutral-200">
          <div v-if="form.items.length === 0" class="px-4 py-6 text-center text-neutral-400 text-sm">
            {{ t('invoice.no_items') }} <button type="button" @click="addItem" class="text-primary-600 hover:underline">{{ t('invoice.add_first') }}</button>
          </div>
          <div v-for="(item, i) in form.items" :key="`m-${i}`" :class="['p-3 space-y-2', itemHasBothNegative(item) ? 'bg-danger-50' : '']">
            <div class="flex items-center justify-between text-xs text-neutral-500">
              <span class="font-mono">#{{ i + 1 }}</span>
              <div class="flex items-center gap-2">
                <button type="button" @click="moveUp(i)" :disabled="i === 0" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-neutral-300 rounded hover:bg-neutral-50 disabled:opacity-30 disabled:cursor-not-allowed">▲</button>
                <button type="button" @click="moveDown(i)" :disabled="i === form.items.length - 1" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-neutral-300 rounded hover:bg-neutral-50 disabled:opacity-30 disabled:cursor-not-allowed">▼</button>
                <button type="button" @click="removeItem(i)" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded text-lg leading-none">×</button>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.description') }}</label>
              <textarea v-model="item.description" rows="2" data-row-input="inv-item" :placeholder="t('invoice.items_table.description')"
                class="w-full px-3 py-2 border border-neutral-300 rounded text-sm resize-y min-h-[44px] focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"></textarea>
            </div>
            <div v-if="ossAvailable || item.oss_applicable" class="border border-neutral-200 rounded-md p-2">
              <label class="inline-flex items-center gap-2 text-sm">
                <input v-model="item.oss_applicable" type="checkbox" class="rounded border-neutral-300 text-primary-600"
                  @change="onOssApplicableChange(item)" />
                <span>{{ t('invoice.oss.enabled') }}</span>
              </label>
              <div v-if="item.oss_applicable" class="grid grid-cols-2 gap-2 mt-2">
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.oss.country') }}</label>
                  <input v-model="item.oss_consumer_country" type="text" maxlength="2"
                    class="w-full h-10 px-3 border border-neutral-300 rounded text-sm font-mono uppercase" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.oss.supply_type') }}</label>
                  <select v-model="item.oss_supply_type" class="w-full h-10 px-2 border border-neutral-300 rounded text-sm bg-surface">
                    <option value="goods">{{ t('invoice.oss.goods') }}</option>
                    <option value="services">{{ t('invoice.oss.services') }}</option>
                  </select>
                </div>
                <div class="col-span-2">
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.oss.rate_type') }}</label>
                  <select v-model="item.oss_rate_type" class="w-full h-10 px-2 border border-neutral-300 rounded text-sm bg-surface">
                    <option value="standard">{{ t('invoice.oss.rate_standard') }}</option>
                    <option value="reduced">{{ t('invoice.oss.rate_reduced') }}</option>
                    <option value="second_reduced">{{ t('invoice.oss.rate_second_reduced') }}</option>
                    <option value="parking">{{ t('invoice.oss.rate_parking') }}</option>
                  </select>
                </div>
                <div class="col-span-2">
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.oss.original_period') }}</label>
                  <select v-model="item.oss_original_period" class="w-full h-10 px-2 border border-neutral-300 rounded text-sm bg-surface">
                    <option :value="null">{{ t('invoice.oss.current_period') }}</option>
                    <option v-if="item.oss_original_period && !ossOriginalPeriodOptions.some(o => o.value === item.oss_original_period)"
                      :value="item.oss_original_period">{{ item.oss_original_period }}</option>
                    <option v-for="period in ossOriginalPeriodOptions" :key="period.value" :value="period.value">{{ period.label }}</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.qty') }}</label>
                <input v-model="item.quantity" v-math type="text" inputmode="decimal"
                  :class="['w-full h-10 px-3 border rounded text-right font-mono text-sm', itemHasBothNegative(item) ? 'border-danger-400' : 'border-neutral-300']" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.unit') }}</label>
                <select v-model="item.unit" class="w-full h-10 px-2 border border-neutral-300 rounded text-sm bg-surface">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                  <option v-if="item.unit && !units.some(u => u.code === item.unit)" :value="item.unit">{{ item.unit }}</option>
                </select>
              </div>
            </div>
            <div :class="supplierIsVatPayer ? 'grid grid-cols-2 gap-2' : ''">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ unitPriceHeaderLabel }}</label>
                <input v-model="item.unit_price_without_vat" v-math type="text" inputmode="decimal"
                  :class="['w-full h-10 px-3 border rounded text-right font-mono text-sm', itemHasBothNegative(item) ? 'border-danger-400' : 'border-neutral-300']" />
              </div>
              <div v-if="supplierIsVatPayer">
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.totals.vat') }}</label>
                <select v-model.number="item.vat_rate_id" class="w-full h-10 px-2 border border-neutral-300 rounded text-sm bg-surface">
                  <option v-for="r in vatRatesForItem(item)" :key="r.id" :value="r.id">{{ vatRateLabel(r) }}</option>
                </select>
              </div>
            </div>
            <div class="flex items-baseline justify-between pt-1 border-t border-neutral-200">
              <span class="text-xs font-medium text-neutral-500 uppercase tracking-wide">{{ supplierIsVatPayer ? t('invoice.items_table.total_incl_vat') : nonPayerTotalLabel }}</span>
              <input :value="itemTotal(item)" @change="setItemGross(item, ($event.target as HTMLInputElement).value)"
                type="text" inputmode="decimal" :title="t('invoice.items_table.gross_edit_hint')"
                class="w-32 h-9 px-2 border border-neutral-300 rounded text-right font-mono text-sm font-semibold" />
            </div>
          </div>
        </div>
      </div>

      <!-- Klasifikace (VAT pro DPH přiznání + volitelný revenue tag) -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-3">{{ t('invoice.classification.title') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('invoice.classification.vat_classification') }}</label>
            <select v-model="form.vat_classification_code" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option :value="null">— {{ t('invoice.classification.no_vat_class') }} —</option>
              <option v-for="vc in vatClassifications" :key="vc.id" :value="vc.code">
                {{ vc.code }} — {{ vc.label.length > 60 ? vc.label.slice(0, 60) + '…' : vc.label }}
              </option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('invoice.classification.vat_classification_hint') }}</p>
          </div>
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('invoice.classification.revenue_category') }}</label>
            <select v-model="form.revenue_category_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option :value="null">— {{ t('invoice.classification.revenue_category_none') }} —</option>
              <option v-for="rc in revenueCategories" :key="rc.id" :value="rc.id">
                {{ rc.label }} ({{ rc.code }})
              </option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('invoice.classification.revenue_category_hint') }}</p>
          </div>
        </div>
      </div>

      <!-- Sumace + poznámky -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="md:col-span-2 space-y-4">
          <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.note_above') }}</label>
            <textarea v-model="form.note_above_items" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.note_below') }}</label>
            <textarea v-model="form.note_below_items" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          </div>
        </div>

        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('invoice.summary') }}</h3>
          <div class="flex items-center justify-between gap-3 mb-3 pb-3 border-b border-neutral-100">
            <label for="discount_percent" class="text-sm text-neutral-700">{{ t('invoice.discount.label') }}</label>
            <div class="relative w-28">
              <input id="discount_percent" v-model.number="form.discount_percent" type="number" min="0" max="100" step="0.01"
                class="w-full h-9 pl-2 pr-7 border border-neutral-300 rounded text-right font-mono text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <span class="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 text-sm pointer-events-none">%</span>
            </div>
          </div>
          <dl class="space-y-1.5 text-sm">
            <div v-if="computed_totals.discount_amount > 0" class="flex justify-between text-warning-700 pb-1">
              <dt>{{ t('invoice.discount.applied') }} {{ formatPercent(computed_totals.discount_percent) }}</dt>
              <dd class="font-mono">−{{ formatMoney(computed_totals.discount_amount, form.currency) }}</dd>
            </div>
            <template v-if="supplierIsVatPayer">
              <div v-for="b in computed_totals.breakdown" :key="b.rate" class="flex justify-between text-neutral-600">
                <dt>{{ t('invoice.totals.base') }} {{ formatPercent(b.rate) }}</dt>
                <dd class="font-mono">{{ formatMoney(b.base, form.currency) }}</dd>
              </div>
              <div v-for="b in computed_totals.breakdown" :key="'v'+b.rate" v-show="b.vat > 0" class="flex justify-between text-neutral-600">
                <dt>{{ t('invoice.totals.vat') }} {{ formatPercent(b.rate) }}</dt>
                <dd class="font-mono">{{ formatMoney(b.vat, form.currency) }}</dd>
              </div>
              <div class="flex justify-between border-t border-neutral-200 pt-2 mt-2 font-semibold">
                <dt>{{ t('invoice.totals.without_vat') }}</dt>
                <dd class="font-mono">{{ formatMoney(computed_totals.without_vat, form.currency) }}</dd>
              </div>
              <div class="flex justify-between font-semibold">
                <dt>{{ t('invoice.totals.vat_total') }}</dt>
                <dd class="font-mono">{{ formatMoney(computed_totals.vat, form.currency) }}</dd>
              </div>
            </template>
            <div class="flex justify-between border-t border-neutral-300 pt-2 mt-2 text-lg font-semibold text-primary-700">
              <dt>{{ t('invoice.totals.total') }}</dt>
              <dd class="font-mono">{{ formatMoney(computed_totals.with_vat, form.currency) }}</dd>
            </div>
            <div v-if="form.advance_paid_amount > 0" class="flex justify-between text-sm text-neutral-600 pt-2">
              <dt>{{ t('invoice.totals.advance_deduction') }}</dt>
              <dd class="font-mono">−{{ formatMoney(form.advance_paid_amount, form.currency) }}</dd>
            </div>
            <div v-if="form.advance_paid_amount > 0" class="flex justify-between text-base font-semibold pt-1">
              <dt>{{ t('invoice.totals.amount_due') }}</dt>
              <dd class="font-mono">{{ formatMoney(computed_totals.amount_to_pay, form.currency) }}</dd>
            </div>
            <div v-if="hasNonPositiveAmountToPay" class="rounded-md bg-warning-50 border border-warning-200 px-3 py-2 text-xs text-warning-700 mt-3">
              {{ t('invoice.amount_positive_required') }}
            </div>
            <div v-if="loadedRate" class="text-xs text-neutral-500 pt-3 border-t border-neutral-200 mt-2">
              {{ t('invoice.czk_recap.rate_info', {
                rate: loadedRate.rate.toLocaleString(locale === 'cs' ? 'cs-CZ' : 'en-US', { minimumFractionDigits: 3, maximumFractionDigits: 4 }),
                currency: loadedRate.currency,
                date: new Date(loadedRate.date).toLocaleDateString(locale === 'cs' ? 'cs-CZ' : 'en-US'),
              }) }}
            </div>
          </dl>
        </div>
      </div>

      <!-- Výkaz víceprací -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.work_report') }}</h3>
          <div class="flex items-center gap-2">
            <button v-if="!wrOpen" type="button" @click="openWorkReport"
              class="cursor-pointer px-4 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
              {{ t('invoice.wr_add') }}
            </button>
            <button v-if="wrOpen && wrItems.length > 0" type="button" @click="pushWrToInvoiceItem"
              class="cursor-pointer px-4 h-9 text-sm bg-success-600 hover:bg-success-600 text-white font-semibold rounded-md inline-flex items-center gap-1.5 shadow-sm">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
              {{ t('invoice.wr_push_to_item') }}
            </button>
            <button v-if="wrOpen && wrItems.length > 0" type="button" @click="deleteWorkReport"
              class="cursor-pointer px-3 h-8 text-xs border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md">
              {{ t('invoice.wr_delete') }}
            </button>
          </div>
        </header>
        <div v-if="wrOpen" class="p-5 space-y-3">
          <div class="flex flex-col sm:flex-row gap-2">
            <input v-model="wrTitle" type="text" :placeholder="t('invoice.wr_title')"
              class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            <select v-if="supplierIsVatPayer" v-model.number="wrVatRateId"
              :title="t('invoice.wr_vat_rate')"
              class="h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface sm:w-48">
              <option v-for="r in domesticVatRates" :key="r.id" :value="r.id">{{ vatRateLabel(r) }}</option>
            </select>
          </div>
          <!-- Desktop: tabulka -->
          <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-2 py-2 w-12"></th>
                <th class="px-3 py-2 text-left font-medium">{{ t('invoice.wr_description') }}</th>
                <th class="px-3 py-2 text-left font-medium w-36">{{ t('invoice.wr_date') }}</th>
                <th class="px-3 py-2 text-right font-medium w-24">{{ t('invoice.wr_hours') }}</th>
                <th class="px-3 py-2 text-right font-medium w-28">{{ t('invoice.wr_rate') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('invoice.wr_total') }}</th>
                <th class="px-2 py-2 w-10"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200">
              <tr v-for="(it, i) in wrItems" :key="i">
                <td class="px-2 py-2 text-center text-xs text-neutral-400">
                  <button type="button" @click="moveWrItem(i, -1)" :disabled="i === 0"
                          :title="t('invoice.wr_move_up')"
                          class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▲</button>
                  <button type="button" @click="moveWrItem(i, 1)" :disabled="i === wrItems.length - 1"
                          :title="t('invoice.wr_move_down')"
                          class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▼</button>
                </td>
                <td class="px-2 py-1.5">
                  <input v-model="it.description" type="text" data-row-input="inv-wr" class="w-full h-9 px-2 border border-neutral-300 rounded text-sm" />
                </td>
                <td class="px-2 py-1.5">
                  <input v-model="it.work_date" type="date" class="w-full h-9 px-2 border border-neutral-300 rounded text-sm font-mono" />
                </td>
                <td class="px-2 py-1.5">
                  <input v-model.number="it.hours" type="number" step="0.25" min="0" class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                </td>
                <td class="px-2 py-1.5">
                  <input v-model.number="it.rate" type="number" step="1" min="0" class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                </td>
                <td class="px-3 py-1.5 text-right font-mono text-neutral-700">
                  {{ formatMoney((Number(it.hours) || 0) * (Number(it.rate) || 0), form.currency) }}
                </td>
                <td class="px-2 py-1.5 text-center">
                  <button type="button" @click="removeWrItem(i)" :title="t('common.delete')"
                          class="cursor-pointer text-danger-500 hover:text-danger-600 text-lg leading-none">×</button>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 font-semibold">
              <tr>
                <td colspan="3" class="p-2">
                  <button type="button" @click="addWrItem"
                    class="cursor-pointer px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    {{ t('invoice.wr_add_row') }}
                  </button>
                </td>
                <td v-if="wrItems.length > 0" class="px-3 py-2 text-right font-mono">
                  <span class="text-neutral-400 font-normal mr-2">Σ</span>{{ wrTotalHours.toFixed(2) }} h
                </td>
                <td v-else></td>
                <td></td>
                <td v-if="wrItems.length > 0" class="px-3 py-2 text-right font-mono whitespace-nowrap" colspan="2">
                  {{ formatMoney(wrTotalAmount, form.currency) }}
                </td>
                <td v-else colspan="2"></td>
              </tr>
            </tfoot>
          </table>
          </div>

          <!-- Mobile: stack karet -->
          <div class="md:hidden space-y-2">
            <div v-for="(it, i) in wrItems" :key="`m-${i}`"
              class="border border-neutral-200 rounded-md p-3 space-y-2 bg-neutral-50/30">
              <div class="flex items-center justify-between text-xs text-neutral-500">
                <span class="font-mono">#{{ i + 1 }}</span>
                <div class="flex items-center gap-1">
                  <button type="button" @click="moveWrItem(i, -1)" :disabled="i === 0"
                          :title="t('invoice.wr_move_up')"
                          class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-neutral-300 text-neutral-600 hover:bg-neutral-50 rounded disabled:opacity-30 disabled:cursor-not-allowed">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                  </button>
                  <button type="button" @click="moveWrItem(i, 1)" :disabled="i === wrItems.length - 1"
                          :title="t('invoice.wr_move_down')"
                          class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-neutral-300 text-neutral-600 hover:bg-neutral-50 rounded disabled:opacity-30 disabled:cursor-not-allowed">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                  </button>
                  <button type="button" @click="removeWrItem(i)" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded text-lg leading-none">×</button>
                </div>
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.wr_description') }}</label>
                <input v-model="it.description" type="text" data-row-input="inv-wr" class="w-full h-10 px-3 border border-neutral-300 rounded text-sm bg-surface" />
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.wr_date') }}</label>
                  <input v-model="it.work_date" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded text-sm font-mono bg-surface" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.wr_hours') }}</label>
                  <input v-model.number="it.hours" type="number" inputmode="decimal" step="0.25" min="0" class="w-full h-10 px-3 border border-neutral-300 rounded text-right font-mono text-sm bg-surface" />
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2 items-end">
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.wr_rate') }}</label>
                  <input v-model.number="it.rate" type="number" inputmode="decimal" step="1" min="0" class="w-full h-10 px-3 border border-neutral-300 rounded text-right font-mono text-sm bg-surface" />
                </div>
                <div class="text-right pb-2">
                  <div class="text-xs font-medium text-neutral-500 uppercase tracking-wide">{{ t('invoice.wr_total') }}</div>
                  <div class="font-mono text-sm font-semibold">
                    {{ formatMoney((Number(it.hours) || 0) * (Number(it.rate) || 0), form.currency) }}
                  </div>
                </div>
              </div>
            </div>
            <button type="button" @click="addWrItem"
              class="cursor-pointer w-full h-10 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center justify-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
              {{ t('invoice.wr_add_row') }}
            </button>
            <div v-if="wrItems.length > 0" class="bg-neutral-50 rounded-md px-3 py-2 flex items-center justify-between font-semibold text-sm">
              <span class="font-mono">Σ {{ wrTotalHours.toFixed(2) }} h</span>
              <span class="font-mono">{{ formatMoney(wrTotalAmount, form.currency) }}</span>
            </div>
          </div>

          <p class="text-xs text-neutral-500">
            {{ t('invoice.wr_hint', { title: wrTitle, hours: wrTotalHours.toFixed(2), rate: wrItems[0]?.rate || 0, currency: form.currency }) }}
          </p>
        </div>
      </div>

      <!-- Výkaz materiálu -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.work_report_material') }}</h3>
          <div class="flex items-center gap-2">
            <button v-if="!matOpen" type="button" @click="openMaterial"
              class="cursor-pointer px-4 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
              {{ t('invoice.wr_material_add') }}
            </button>
            <button v-if="matOpen && matItems.length > 0" type="button" @click="pushMatToInvoiceItem"
              class="cursor-pointer px-4 h-9 text-sm bg-success-600 hover:bg-success-600 text-white font-semibold rounded-md inline-flex items-center gap-1.5 shadow-sm">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
              {{ t('invoice.wr_push_to_item') }}
            </button>
            <button v-if="matOpen && matItems.length > 0" type="button" @click="deleteMaterial"
              class="cursor-pointer px-3 h-8 text-xs border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md">
              {{ t('invoice.wr_delete') }}
            </button>
          </div>
        </header>
        <div v-if="matOpen" class="p-5 space-y-3">
          <div class="flex flex-col sm:flex-row gap-2">
            <input v-model="matTitle" type="text" :placeholder="t('invoice.wr_material_title')"
              class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            <select v-if="supplierIsVatPayer" v-model.number="matVatRateId"
              :title="t('invoice.wr_vat_rate')"
              class="h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface sm:w-48">
              <option v-for="r in domesticVatRates" :key="r.id" :value="r.id">{{ vatRateLabel(r) }}</option>
            </select>
          </div>
          <p class="text-xs text-neutral-500">
            {{ (form.prices_include_vat && supplierIsVatPayer) ? t('invoice.wr_material_price_incl') : t('invoice.wr_material_price_excl') }}
          </p>
          <!-- Desktop: tabulka -->
          <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-2 py-2 w-12"></th>
                <th class="px-3 py-2 text-left font-medium">{{ t('invoice.wr_description') }}</th>
                <th class="px-3 py-2 text-right font-medium w-24">{{ t('invoice.wr_material_qty') }}</th>
                <th class="px-3 py-2 text-left font-medium w-24">{{ t('invoice.wr_material_unit') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ unitPriceHeaderLabel }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('invoice.wr_total') }}</th>
                <th class="px-2 py-2 w-10"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200">
              <tr v-for="(m, i) in matItems" :key="i">
                <td class="px-2 py-2 text-center text-xs text-neutral-400">
                  <button type="button" @click="moveMatItem(i, -1)" :disabled="i === 0"
                          :title="t('invoice.wr_move_up')"
                          class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▲</button>
                  <button type="button" @click="moveMatItem(i, 1)" :disabled="i === matItems.length - 1"
                          :title="t('invoice.wr_move_down')"
                          class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▼</button>
                </td>
                <td class="px-2 py-1.5">
                  <input v-model="m.description" type="text" data-row-input="inv-mat" class="w-full h-9 px-2 border border-neutral-300 rounded text-sm" />
                </td>
                <td class="px-2 py-1.5">
                  <input v-model.number="m.quantity" type="number" step="0.001" min="0" class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                </td>
                <td class="px-2 py-1.5">
                  <select v-model="m.unit" class="w-full h-9 px-2 border border-neutral-300 rounded text-sm bg-surface">
                    <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                    <option v-if="m.unit && !units.some(u => u.code === m.unit)" :value="m.unit">{{ m.unit }}</option>
                  </select>
                </td>
                <td class="px-2 py-1.5">
                  <input v-model.number="m.unit_price" type="number" step="0.01" min="0" class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                </td>
                <td class="px-3 py-1.5 text-right font-mono text-neutral-700">
                  {{ formatMoney((Number(m.quantity) || 0) * (Number(m.unit_price) || 0), form.currency) }}
                </td>
                <td class="px-2 py-1.5 text-center">
                  <button type="button" @click="removeMatItem(i)" :title="t('common.delete')"
                          class="cursor-pointer text-danger-500 hover:text-danger-600 text-lg leading-none">×</button>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 font-semibold">
              <tr>
                <td colspan="4" class="p-2">
                  <button type="button" @click="addMatItem"
                    class="cursor-pointer px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    {{ t('invoice.wr_material_add_item') }}
                  </button>
                </td>
                <td v-if="matItems.length > 0" class="px-3 py-2 text-right font-mono whitespace-nowrap" colspan="2">
                  {{ formatMoney(matTotal, form.currency) }}
                </td>
                <td v-else colspan="2"></td>
              </tr>
            </tfoot>
          </table>
          </div>

          <!-- Mobile: stack karet -->
          <div class="md:hidden space-y-2">
            <div v-for="(m, i) in matItems" :key="`mm-${i}`"
              class="border border-neutral-200 rounded-md p-3 space-y-2 bg-neutral-50/30">
              <div class="flex items-center justify-between text-xs text-neutral-500">
                <span class="font-mono">#{{ i + 1 }}</span>
                <div class="flex items-center gap-1">
                  <button type="button" @click="moveMatItem(i, -1)" :disabled="i === 0"
                          class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-neutral-300 text-neutral-600 hover:bg-neutral-50 rounded disabled:opacity-30 disabled:cursor-not-allowed">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                  </button>
                  <button type="button" @click="moveMatItem(i, 1)" :disabled="i === matItems.length - 1"
                          class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-neutral-300 text-neutral-600 hover:bg-neutral-50 rounded disabled:opacity-30 disabled:cursor-not-allowed">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                  </button>
                  <button type="button" @click="removeMatItem(i)" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded text-lg leading-none">×</button>
                </div>
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.wr_description') }}</label>
                <input v-model="m.description" type="text" data-row-input="inv-mat" class="w-full h-10 px-3 border border-neutral-300 rounded text-sm bg-surface" />
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.wr_material_qty') }}</label>
                  <input v-model.number="m.quantity" type="number" inputmode="decimal" step="0.001" min="0" class="w-full h-10 px-3 border border-neutral-300 rounded text-right font-mono text-sm bg-surface" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.wr_material_unit') }}</label>
                  <select v-model="m.unit" class="w-full h-10 px-3 border border-neutral-300 rounded text-sm bg-surface">
                    <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                    <option v-if="m.unit && !units.some(u => u.code === m.unit)" :value="m.unit">{{ m.unit }}</option>
                  </select>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2 items-end">
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ unitPriceHeaderLabel }}</label>
                  <input v-model.number="m.unit_price" type="number" inputmode="decimal" step="0.01" min="0" class="w-full h-10 px-3 border border-neutral-300 rounded text-right font-mono text-sm bg-surface" />
                </div>
                <div class="text-right pb-2">
                  <div class="text-xs font-medium text-neutral-500 uppercase tracking-wide">{{ t('invoice.wr_total') }}</div>
                  <div class="font-mono text-sm font-semibold">
                    {{ formatMoney((Number(m.quantity) || 0) * (Number(m.unit_price) || 0), form.currency) }}
                  </div>
                </div>
              </div>
            </div>
            <button type="button" @click="addMatItem"
              class="cursor-pointer w-full h-10 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center justify-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
              {{ t('invoice.wr_material_add_item') }}
            </button>
            <div v-if="matItems.length > 0" class="bg-neutral-50 rounded-md px-3 py-2 flex items-center justify-end font-semibold text-sm">
              <span class="font-mono">{{ formatMoney(matTotal, form.currency) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Přílohy — u nové faktury držené v prohlížeči (nahrají se po vytvoření),
           u existující faktury rovnou nahrávané / mazané -->
      <div v-if="attachmentsAllowed"
           class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <div>
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.attachments.title') }}</h3>
            <p class="text-xs text-neutral-500 mt-0.5">{{ isEdit ? t('invoice.attachments.hint') : t('invoice.attachments.pending_hint') }}</p>
          </div>
          <span class="text-xs text-neutral-400">{{ attachments.length + pendingAttachments.length }}</span>
        </header>

        <!-- Existující přílohy (editace) -->
        <ul v-if="attachments.length > 0" class="divide-y divide-neutral-100">
          <li v-for="a in attachments" :key="a.id" class="px-5 py-2.5 text-sm flex items-center gap-3">
            <svg class="w-4 h-4 text-neutral-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 1 0 2.828 2.828l6.414-6.414a4 4 0 1 0-5.656-5.656L5.05 11.05a6 6 0 1 0 8.486 8.486L20 13"/>
            </svg>
            <span class="text-neutral-700 text-xs flex-1 truncate" :title="a.original_name">{{ a.original_name }}</span>
            <span class="text-neutral-400 text-xs whitespace-nowrap">{{ formatBytes(a.size_bytes) }}</span>
            <a :href="invoicesApi.attachmentUrl(invoiceId!, a.id, false)" target="_blank"
               class="text-xs text-primary-600 hover:text-primary-700 font-medium">{{ t('common.view') }}</a>
            <a :href="invoicesApi.attachmentUrl(invoiceId!, a.id, true)"
               class="text-xs text-primary-600 hover:text-primary-700 font-medium">{{ t('common.download') }}</a>
            <button @click="deleteAttachment(a)" type="button"
                    class="text-xs text-danger-500 hover:text-danger-600 cursor-pointer">{{ t('common.delete') }}</button>
          </li>
        </ul>

        <!-- Nové soubory (čekají na vytvoření faktury) -->
        <ul v-if="pendingAttachments.length > 0" class="divide-y divide-neutral-100">
          <li v-for="(f, i) in pendingAttachments" :key="`p-${f.name}-${i}`" class="px-5 py-2.5 text-sm flex items-center gap-3">
            <svg class="w-4 h-4 text-neutral-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 1 0 2.828 2.828l6.414-6.414a4 4 0 1 0-5.656-5.656L5.05 11.05a6 6 0 1 0 8.486 8.486L20 13"/>
            </svg>
            <span class="text-neutral-700 text-xs flex-1 truncate" :title="f.name">{{ f.name }}</span>
            <span class="text-neutral-400 text-xs whitespace-nowrap">{{ formatBytes(f.size) }}</span>
            <button @click="removePendingAttachment(i)" type="button"
                    class="text-xs text-danger-500 hover:text-danger-600 cursor-pointer">{{ t('common.delete') }}</button>
          </li>
        </ul>

        <div class="px-5 py-3"
             :class="attachmentDragOver ? 'bg-primary-50' : 'bg-neutral-50/50'"
             @dragover.prevent="attachmentDragOver = true"
             @dragleave.prevent="attachmentDragOver = false"
             @drop="onAttachmentDrop">
          <label class="flex flex-col md:flex-row items-stretch md:items-center gap-2 md:gap-3 cursor-pointer">
            <input type="file" multiple class="hidden" @change="onAttachmentInputChange" />
            <span class="inline-flex items-center justify-center px-3 h-9 text-sm border border-primary-300 rounded-md text-primary-600 hover:bg-primary-50">
              <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
              </svg>
              {{ attachmentsBusy ? t('invoice.attachments.uploading') : t('invoice.attachments.add') }}
            </span>
            <span class="text-xs text-neutral-500">{{ t('invoice.attachments.drop_here') }}</span>
          </label>
        </div>
      </div>

      <div v-if="error" data-error-banner class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ error }}
      </div>

      <!-- Action bar -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 flex justify-between items-center shadow-sm">
        <RouterLink to="/invoices" class="px-4 py-2 text-sm text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100 rounded-lg transition-colors">{{ t('common.back') }}</RouterLink>
        <button type="submit" :disabled="submitting"
          class="px-5 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
          {{ submitting ? t('common.saving') : (isEdit ? t('common.save') : t('common.create')) }}
        </button>
      </div>
      </fieldset>
    </form>

    <!-- Potvrzení odemčení uzamčeného dokladu — vyžaduje zaškrtnutí, ne jen OK -->
    <Modal v-if="unlockModalOpen" :title="t('invoice.unlock_modal_title')" width-class="max-w-lg" @close="unlockModalOpen = false; unlockAck = false">
      <p class="text-sm text-neutral-700">{{ t('invoice.unlock_modal_intro', { varsymbol: editedVarsymbol ?? '—', status: editedStatus }) }}</p>
      <ul class="mt-3 text-sm text-neutral-700 list-disc list-inside space-y-1">
        <li>{{ t('invoice.unlock_c_varsymbol') }}</li>
        <li>{{ t('invoice.unlock_c_snapshots') }}</li>
        <li>{{ t('invoice.unlock_c_vat_reports') }}</li>
        <li>{{ t('invoice.unlock_c_audit') }}</li>
      </ul>
      <label class="mt-4 flex items-start gap-2 text-sm text-neutral-800 cursor-pointer">
        <input v-model="unlockAck" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-primary-600" />
        <span>{{ t('invoice.unlock_ack') }}</span>
      </label>
      <template #footer>
        <button type="button" @click="unlockModalOpen = false; unlockAck = false"
          class="cursor-pointer h-9 px-4 border border-neutral-300 text-neutral-700 text-sm font-medium rounded-md hover:bg-neutral-50">
          {{ t('common.cancel') }}
        </button>
        <button type="button" :disabled="!unlockAck" @click="confirmUnlock"
          class="cursor-pointer h-9 px-4 bg-warning-500 hover:bg-warning-600 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
          {{ t('invoice.unlock_button') }}
        </button>
      </template>
    </Modal>

    <!-- Inline create modaly — neopouštějí editor, po save se entita auto-vybere -->
    <ClientFormModal v-if="clientModalOpen"
      @created="onClientCreatedInModal"
      @close="clientModalOpen = false" />
    <ProjectFormModal v-if="projectModalOpen && form.client_id"
      :client-id="form.client_id"
      @created="onProjectCreatedInModal"
      @close="projectModalOpen = false" />
  </div>
</template>

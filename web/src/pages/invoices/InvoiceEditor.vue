<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { invoicesApi, type Invoice, type InvoicePayload, type InvoiceItem, type WorkReportItem } from '@/api/invoices'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'

const { t, locale } = useI18n()
const toast = useToast()

useHotkey('ctrl+s', (e) => { e.preventDefault(); submit() })
import { clientsApi, type Client, type ViesLookupResult } from '@/api/clients'
import { projectsApi, type Project } from '@/api/projects'
import { codebooksApi, type VatRate, type Currency, type Unit } from '@/api/codebooks'
import { vatClassificationsApi, type VatClassification } from '@/api/vatClassifications'
import { formatMoney, formatPercent } from '@/composables/useFormat'
import { evalMath } from '@/directives/vMath'
import { apiErrorMessage } from '@/api/errors'
import { useSupplierStore } from '@/stores/supplier'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import ClientFormModal from '@/components/modals/ClientFormModal.vue'
import ProjectFormModal from '@/components/modals/ProjectFormModal.vue'

const supplierStore = useSupplierStore()

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const invoiceId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const loaded = ref(false)
const submitting = ref(false)
const loadedRate = ref<{ rate: number; date: string; currency: string } | null>(null)
const error = ref('')
const isForce = computed(() => route.query.force === '1')
const editedStatus = ref<string>('draft')
const editedVarsymbol = ref<string | null>(null)
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

const clients = ref<Client[]>([])
const projects = ref<Project[]>([])
const vatRates = ref<VatRate[]>([])
const vatClassifications = ref<VatClassification[]>([])
const currencies = ref<Currency[]>([])
const units = ref<Unit[]>([])

// Default jednotka pro běžnou položku — z číselníku (is_default), fallback 'ks'.
function defaultItemUnit(): string {
  return units.value.find(u => u.is_default)?.code || units.value[0]?.code || 'ks'
}

// Aktivní dodavatel — pokud není plátce DPH, fakturuje bez DPH (žádné DPH UI ani v PDF).
const supplierIsVatPayer = computed(() => supplierStore.currentSupplier?.is_vat_payer ?? true)

// RC zobrazit jen když:
//   - dodavatel je plátce DPH (neplátce nemůže RC vystavit) A
//   - klient není vybraný NEBO má RC povolenou v profilu
const showReverseChargeUI = computed(() => {
  if (!supplierIsVatPayer.value) return false
  if (!form.value.client_id) return true
  const c = clients.value.find(c => c.id === form.value.client_id)
  return !!c?.reverse_charge
})

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
  language: 'cs' | 'en'
  note_above_items: string
  note_below_items: string
  advance_paid_amount: number
  discount_percent: number
  payment_method: 'bank_transfer' | 'card' | 'cash' | 'other'
  exchange_rate: number | null
  varsymbol: string  // Ruční override čísla faktury (prázdný = generuje se při issue)
  vat_classification_code: string | null
  revenue_category: string | null
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
  language: 'cs',
  note_above_items: '',
  note_below_items: '',
  advance_paid_amount: 0,
  discount_percent: 0,
  payment_method: 'bank_transfer',
  exchange_rate: null,
  varsymbol: '',
  vat_classification_code: null,
  revenue_category: null,
  items: [],
})

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

function defaultVatRateId(reverseCharge = false): number {
  // Neplátce DPH → vždy 0% Osvobozeno (rate_percent=0, !is_reverse_charge).
  if (!supplierIsVatPayer.value) {
    const zero = vatRates.value.find(v => Number(v.rate_percent) === 0 && !v.is_reverse_charge)
    if (zero) return zero.id
  }
  if (reverseCharge) {
    const rc = vatRates.value.find(v => v.is_reverse_charge)
    if (rc) return rc.id
  }
  const def = vatRates.value.find(v => v.is_default)
  return def?.id ?? vatRates.value[0]?.id ?? 0
}

// Když se přepne RC (z klienta nebo ručním checkboxem) nebo je dodavatel neplátce,
// sjednoť vat_rate_id všech položek s novým defaultem — display by jinak ukazoval
// 21 % zatímco totals už počítají 0 %.
function syncItemsVatRateToReverseCharge() {
  const target = defaultVatRateId(form.value.reverse_charge)
  if (!target) return
  for (const it of form.value.items) it.vat_rate_id = target
}

function vatRateLabel(r: VatRate): string {
  if (Number(r.rate_percent) > 0) return `${r.rate_percent} %`
  if (r.is_reverse_charge) return t('invoice.vat_rate_label.reverse_charge')
  return t('invoice.vat_rate_label.exempt')
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
    vat_rate_id: defaultVatRateId(form.value.reverse_charge),
    order_index: form.value.items.length,
  }
}

// Ruční toggle RC checkboxu → resync vat_rate_id u položek s aktuálním defaultem.
// Loaded guard chrání edit-mode init před přepsáním uložených sazeb.
watch(() => form.value.reverse_charge, (newVal, oldVal) => {
  if (loaded.value && newVal !== oldVal) syncItemsVatRateToReverseCharge()
})

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
// u vystavených dokladů. Projekt má jen `payment_due_days` (vždy v dnech), klient a
// supplier mají i `unit` ('days' nebo 'month').
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
  // Klient s vlastní hodnotou → jeho jednotka (bez vlastní = dny, ne supplier),
  // jinak plně dědí supplier default (hodnotu i jednotku).
  const c = form.value.client_id ? clients.value.find(x => x.id === form.value.client_id) : null
  if (c && typeof c.payment_due_default === 'number') {
    form.value.due_date = computeDueDate(newIssue, c.payment_due_default, (c.payment_due_unit ?? 'days') as DueUnit)
  } else {
    form.value.due_date = supplierDueDate(newIssue)
  }
})

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
  const [vr, cur, un, vc] = await Promise.all([
    codebooksApi.vatRates('CZ'),
    codebooksApi.currencies(),
    codebooksApi.units(),
    vatClassificationsApi.list('sale'),
  ])
  vatRates.value = vr
  currencies.value = cur
  units.value = un
  vatClassifications.value = vc
  if (form.value.currency_id === 0) {
    const def = cur.find(c => c.is_default && c.code === 'CZK') || cur[0]
    if (def) {
      form.value.currency_id = def.id
      form.value.currency = def.code
    }
  }

  // Load clients (for dropdown)
  const cl = await clientsApi.list({ archived: false })
  clients.value = cl.data

  if (isEdit.value && invoiceId.value) {
    const inv = await invoicesApi.get(invoiceId.value)
    editedStatus.value = inv.status
    editedVarsymbol.value = inv.varsymbol
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
      language: inv.language,
      note_above_items: inv.note_above_items ?? '',
      note_below_items: inv.note_below_items ?? '',
      advance_paid_amount: inv.advance_paid_amount,
      discount_percent: inv.discount_percent ?? 0,
      payment_method: inv.payment_method ?? 'bank_transfer',
      // Slevové položky (item_kind='discount') jsou generované z discount_percent —
      // do editovatelného seznamu nepatří (jinak by se editovaly / zdvojily při uložení).
      items: inv.items.filter(i => i.item_kind !== 'discount').map(i => ({ ...i })),
      exchange_rate: inv.exchange_rate ?? null,
      varsymbol: inv.varsymbol ?? '',
      vat_classification_code: (inv as any).vat_classification_code ?? null,
      revenue_category: (inv as any).revenue_category ?? null,
    })
    loadedRate.value = (inv.exchange_rate && inv.currency !== 'CZK')
      ? { rate: inv.exchange_rate, date: (inv.exchange_rate_date ?? inv.issue_date).slice(0, 10), currency: inv.currency }
      : null
    if (inv.client_id) {
      await loadProjects(inv.client_id)
      await verifyClientVies(inv.client_id)
    }
    // Načti existující work_report (pokud existuje)
    await loadWorkReport()
    if (editedStatus.value === 'draft') await loadVarsymbolPreview()
  } else {
    // New invoice — pre-select from query
    if (route.query.client_id) {
      form.value.client_id = Number(route.query.client_id)
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

  loaded.value = true
})

async function loadProjects(clientId: number) {
  projects.value = await projectsApi.listForClient(clientId)
}

// Inline client/project creation přes modal — UX zlepšení, žádné opouštění editoru.
const clientModalOpen = ref(false)
const projectModalOpen = ref(false)

async function onClientCreatedInModal(client: Client) {
  // Vlož na začátek pole (typicky čerstvě přidaný klient bývá vybraný),
  // setni v editoru a spusť defaults/projects/VIES.
  clients.value = [client, ...clients.value.filter(c => c.id !== client.id)]
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
    await loadProjects(form.value.client_id)
    await applyClientDefaults(form.value.client_id)
    await verifyClientVies(form.value.client_id)
    if (projects.value.length === 1) {
      form.value.project_id = projects.value[0].id
      await applyProjectDefaults(form.value.project_id)
    }
  } else {
    viesResult.value = null
  }
}

async function applyClientDefaults(clientId: number) {
  const c = clients.value.find(c => c.id === clientId)
  if (!c) return
  form.value.currency_id = c.currency_default_id
  form.value.currency = c.currency_default
  form.value.language = c.language
  // Neplátce DPH nikdy nevystavuje RC fakturu — ignorujeme klientský flag.
  const newRc = supplierIsVatPayer.value ? c.reverse_charge : false
  const rcChanged = form.value.reverse_charge !== newRc
  form.value.reverse_charge = newRc
  if (rcChanged) syncItemsVatRateToReverseCharge()
  // Klient s vlastní hodnotou → jeho jednotka (bez vlastní = dny, ne supplier),
  // jinak plně dědí supplier default (hodnotu i jednotku).
  if (typeof c.payment_due_default === 'number') {
    form.value.due_date = computeDueDate(form.value.issue_date, c.payment_due_default, (c.payment_due_unit ?? 'days') as DueUnit)
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
  const breakdown = new Map<number, { rate: number; base: number; vat: number }>()

  for (const item of form.value.items) {
    const vatRate = (form.value.reverse_charge || !supplierIsVatPayer.value)
      ? 0
      : vatRates.value.find(v => v.id === item.vat_rate_id)?.rate_percent ?? 0
    const base = round2(item.quantity * item.unit_price_without_vat)
    const vat = round2(base * (vatRate / 100))

    if (!breakdown.has(vatRate)) {
      breakdown.set(vatRate, { rate: vatRate, base: 0, vat: 0 })
    }
    const b = breakdown.get(vatRate)!
    b.base += base
    b.vat += vat
  }

  // Sleva na úrovni dokladu — odečte se na každé sazbě zvlášť (zrcadlí backend
  // materializaci záporné položky „Sleva X %" na každou sazbu). Server přepočítá
  // autoritativně při uložení; tohle je jen live náhled.
  const pct = Math.min(100, Math.max(0, form.value.discount_percent || 0))
  let discountAmount = 0
  if (pct > 0) {
    for (const b of breakdown.values()) {
      const disc = round2(b.base * (pct / 100))
      if (disc === 0) continue
      b.base = round2(b.base - disc)
      b.vat = round2(b.vat - round2(disc * (b.rate / 100)))
      discountAmount = round2(discountAmount + disc)
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
  const base = round2(Number(item.quantity) * Number(item.unit_price_without_vat))
  const vatRate = (form.value.reverse_charge || !supplierIsVatPayer.value)
    ? 0
    : (vatRates.value.find(v => v.id === item.vat_rate_id)?.rate_percent ?? 0)
  return round2(base + round2(base * (vatRate / 100)))
}

/**
 * Zadání částky s DPH na řádku → zpětný dopočet jednotkové ceny bez DPH
 * (gross / (1 + sazba) / množství). Podporuje výrazy přes evalMath.
 */
function setItemGross(item: InvoiceItem, raw: string): void {
  const gross = evalMath(raw)
  if (gross === null) return
  const qty = Number(item.quantity) || 0
  if (qty === 0) return
  const vatRate = (form.value.reverse_charge || !supplierIsVatPayer.value)
    ? 0
    : Number(vatRates.value.find(v => v.id === item.vat_rate_id)?.rate_percent ?? 0)
  item.unit_price_without_vat = round2(gross / (1 + vatRate / 100) / qty)
}

// ─── WORK REPORT ────────────────────────────────────────────────
const wrOpen = ref(false)
const wrTitle = ref('')
const wrItems = ref<WorkReportItem[]>([])

async function loadWorkReport() {
  if (!invoiceId.value) return
  const wr = await invoicesApi.getWorkReport(invoiceId.value)
  if (wr) {
    wrTitle.value = wr.title
    wrItems.value = wr.items.map(i => ({ ...i }))
    wrOpen.value = true
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
  const defaultVatId = defaultVatRateId(form.value.reverse_charge)
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
    // vat_rate_id záměrně neměníme — uživatel ho mohl ručně změnit
  } else {
    form.value.items.push({
      description,
      quantity: 1,
      unit,
      unit_price_without_vat: totalAmount,
      vat_rate_id: defaultVatId,
      order_index: form.value.items.length,
    })
  }
}

async function deleteWorkReport() {
  if (!confirm(t('invoice.wr_delete_confirm'))) return
  // Pokud je faktura už uložená, smaž i z DB; jinak jen lokálně.
  if (invoiceId.value) {
    try {
      await invoicesApi.deleteWorkReport(invoiceId.value, isForce.value)
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

async function submit() {
  // Tiše vyhoď prázdné řádky (bez popisu i bez ceny) — uživatel přidal řádek a nezapsal ho.
  // Zároveň smaž z form.value.items, ať checkWorkReportSync vidí stejnou množinu jako payload.
  form.value.items = form.value.items.filter(it =>
    (it.description || '').trim() !== '' || (Number(it.unit_price_without_vat) || 0) !== 0
  )
  form.value.items.forEach((it, i) => (it.order_index = i))

  // Detekce nesouladu mezi výkazem a položkou faktury — uživatel má šanci se vrátit
  const wrWarning = checkWorkReportSync()
  if (wrWarning && !confirm(wrWarning)) return

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
      language: form.value.language,
      note_above_items: form.value.note_above_items || null,
      note_below_items: form.value.note_below_items || null,
      advance_paid_amount: form.value.advance_paid_amount,
      discount_percent: form.value.discount_percent || 0,
      payment_method: form.value.payment_method,
      // Pošli kurz jen pokud uživatel ho má nastavený a měna není CZK — backend bere
      // explicit hodnotu jako manuální override (nepřepočítá z ČNB).
      exchange_rate: (form.value.currency !== 'CZK' && form.value.exchange_rate && form.value.exchange_rate > 0)
        ? form.value.exchange_rate : undefined,
      // Volitelný ruční varsymbol — backend ho akceptuje jen u draftu;
      // prázdný řetězec → backend uloží NULL a vygeneruje při issue automaticky.
      varsymbol: form.value.varsymbol.trim(),
      vat_classification_code: form.value.vat_classification_code,
      revenue_category: form.value.revenue_category,
      items: form.value.items.map((it, i) => ({
        description: it.description,
        quantity: it.quantity,
        unit: it.unit,
        unit_price_without_vat: it.unit_price_without_vat,
        vat_rate_id: it.vat_rate_id,
        order_index: i,
      })),
    }

    let saved: Invoice
    if (isEdit.value && invoiceId.value) {
      saved = await invoicesApi.update(invoiceId.value, payload, isForce.value)
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
          items: wrItemsValid.value.map((it, i) => ({
            description: it.description,
            work_date: it.work_date || null,
            hours: Number(it.hours) || 0,
            rate: Number(it.rate) || 0,
            order_index: i,
          })),
        }, isForce.value)
      } catch (e: any) {
        // Faktura je uložená, výkaz ne — nepokračuj v redirectu, ať uživatel nepřijde o data ve formuláři
        error.value = apiErrorMessage(e, t('invoice.wr_save_failed'))
        return
      }
    }
    router.push(`/invoices/${saved.id}`)
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('common.save_failed'))
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

    <!-- Banner pro úpravu vystavené faktury (admin force=1) -->
    <div v-if="isForce && editedStatus !== 'draft'" class="mb-4 rounded-md border border-warning-500/50 bg-warning-50 p-4">
      <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-warning-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
        <div class="text-sm text-warning-600">
          <div class="font-semibold mb-1">{{ t('invoice.edit_issued_warning') }}</div>
          <p>{{ t('invoice.edit_issued_body', { varsymbol: editedVarsymbol, status: editedStatus }) }}</p>
        </div>
      </div>
    </div>

    <form @submit.prevent="submit" class="space-y-4">
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
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.client') }} *</label>
              <div class="flex gap-2">
                <div class="flex-1 min-w-0">
                  <SearchableSelect
                    :model-value="form.client_id"
                    @update:model-value="(v) => { form.client_id = v; onClientChange() }"
                    :options="clients.filter(c => c.is_customer !== false).map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))"
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
            <label v-if="showReverseChargeUI" class="flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="form.reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span>{{ t('invoice.reverse_charge') }} ({{ t('invoice.totals.vat') }} 0 %)</span>
            </label>
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
            <div v-if="form.currency !== 'CZK' && form.exchange_rate !== null && form.exchange_rate > 0">
              <label class="block text-sm font-medium text-neutral-700 mb-1">
                {{ t('invoice.exchange_rate_label', { currency: form.currency }) }}
              </label>
              <input v-model.number="form.exchange_rate" type="number" step="0.0001" min="0"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono" />
              <p class="text-xs text-neutral-500 mt-1">
                {{ t('invoice.exchange_rate_hint') }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Položky -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.items') }}</h3>
          <button type="button" @click="addItem" class="px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md">
            {{ t('invoice.add_item') }}
          </button>
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
              <th class="px-3 py-2 text-right font-medium w-32">{{ t('invoice.items_table.unit_price') }}</th>
              <th v-if="supplierIsVatPayer" class="px-3 py-2 text-center font-medium w-24">{{ t('invoice.totals.vat') }}</th>
              <th class="px-3 py-2 text-right font-medium w-32">{{ supplierIsVatPayer ? t('invoice.items_table.total_incl_vat') : t('invoice.totals.total') }}</th>
              <th class="px-3 py-2 w-12"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(item, i) in form.items" :key="i" :class="itemHasBothNegative(item) ? 'bg-danger-50' : ''">
              <td class="px-2 py-2 text-center text-xs text-neutral-400">
                <button type="button" @click="moveUp(i)" :disabled="i === 0" class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▲</button>
                <button type="button" @click="moveDown(i)" :disabled="i === form.items.length - 1" class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▼</button>
              </td>
              <td class="px-3 py-2">
                <textarea v-model="item.description" rows="1" :placeholder="t('invoice.items_table.description')"
                  class="w-full px-2 py-1.5 border border-neutral-200 rounded text-sm resize-y min-h-[36px] focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"></textarea>
              </td>
              <td class="px-3 py-2">
                <input v-model="item.quantity" v-math type="text" inputmode="decimal"
                  :class="['w-full h-9 px-2 border rounded text-right font-mono text-sm', itemHasBothNegative(item) ? 'border-danger-400' : 'border-neutral-200']" />
              </td>
              <td class="px-3 py-2">
                <select v-model="item.unit" class="w-full h-9 px-1 border border-neutral-200 rounded text-sm bg-surface">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                  <option v-if="item.unit && !units.some(u => u.code === item.unit)" :value="item.unit">{{ item.unit }}</option>
                </select>
              </td>
              <td class="px-3 py-2">
                <input v-model="item.unit_price_without_vat" v-math type="text" inputmode="decimal"
                  :class="['w-full h-9 px-2 border rounded text-right font-mono text-sm', itemHasBothNegative(item) ? 'border-danger-400' : 'border-neutral-200']" />
              </td>
              <td v-if="supplierIsVatPayer" class="px-3 py-2">
                <select v-model.number="item.vat_rate_id" class="w-full h-9 px-1 border border-neutral-200 rounded text-sm bg-surface">
                  <option v-for="r in vatRates" :key="r.id" :value="r.id">{{ vatRateLabel(r) }}</option>
                </select>
              </td>
              <td class="px-3 py-2">
                <input :value="itemTotal(item)" @change="setItemGross(item, ($event.target as HTMLInputElement).value)"
                  type="text" inputmode="decimal" :title="t('invoice.items_table.gross_edit_hint')"
                  class="w-full h-9 px-2 border border-neutral-200 rounded text-right font-mono text-sm" />
              </td>
              <td class="px-2 py-2 text-center">
                <button type="button" @click="removeItem(i)" class="text-danger-500 hover:text-danger-600 text-lg leading-none">×</button>
              </td>
            </tr>
            <tr v-if="form.items.length === 0">
              <td :colspan="supplierIsVatPayer ? 8 : 7" class="px-4 py-6 text-center text-neutral-400 text-sm">
                {{ t('invoice.no_items') }} <button type="button" @click="addItem" class="text-primary-600 hover:underline">{{ t('invoice.add_first') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: stack karet (každé pole na vlastním řádku, čitelné inputy) -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-if="form.items.length === 0" class="px-4 py-6 text-center text-neutral-400 text-sm">
            {{ t('invoice.no_items') }} <button type="button" @click="addItem" class="text-primary-600 hover:underline">{{ t('invoice.add_first') }}</button>
          </div>
          <div v-for="(item, i) in form.items" :key="`m-${i}`" :class="['p-3 space-y-2', itemHasBothNegative(item) ? 'bg-danger-50' : '']">
            <div class="flex items-center justify-between text-xs text-neutral-500">
              <span class="font-mono">#{{ i + 1 }}</span>
              <div class="flex items-center gap-2">
                <button type="button" @click="moveUp(i)" :disabled="i === 0" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-neutral-200 rounded hover:bg-neutral-50 disabled:opacity-30 disabled:cursor-not-allowed">▲</button>
                <button type="button" @click="moveDown(i)" :disabled="i === form.items.length - 1" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-neutral-200 rounded hover:bg-neutral-50 disabled:opacity-30 disabled:cursor-not-allowed">▼</button>
                <button type="button" @click="removeItem(i)" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded text-lg leading-none">×</button>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.description') }}</label>
              <textarea v-model="item.description" rows="2" :placeholder="t('invoice.items_table.description')"
                class="w-full px-3 py-2 border border-neutral-200 rounded text-sm resize-y min-h-[44px] focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.qty') }}</label>
                <input v-model="item.quantity" v-math type="text" inputmode="decimal"
                  :class="['w-full h-10 px-3 border rounded text-right font-mono text-sm', itemHasBothNegative(item) ? 'border-danger-400' : 'border-neutral-200']" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.unit') }}</label>
                <select v-model="item.unit" class="w-full h-10 px-2 border border-neutral-200 rounded text-sm bg-surface">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                  <option v-if="item.unit && !units.some(u => u.code === item.unit)" :value="item.unit">{{ item.unit }}</option>
                </select>
              </div>
            </div>
            <div :class="supplierIsVatPayer ? 'grid grid-cols-2 gap-2' : ''">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.unit_price') }}</label>
                <input v-model="item.unit_price_without_vat" v-math type="text" inputmode="decimal"
                  :class="['w-full h-10 px-3 border rounded text-right font-mono text-sm', itemHasBothNegative(item) ? 'border-danger-400' : 'border-neutral-200']" />
              </div>
              <div v-if="supplierIsVatPayer">
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.totals.vat') }}</label>
                <select v-model.number="item.vat_rate_id" class="w-full h-10 px-2 border border-neutral-200 rounded text-sm bg-surface">
                  <option v-for="r in vatRates" :key="r.id" :value="r.id">{{ vatRateLabel(r) }}</option>
                </select>
              </div>
            </div>
            <div class="flex items-baseline justify-between pt-1 border-t border-neutral-100">
              <span class="text-xs font-medium text-neutral-500 uppercase tracking-wide">{{ supplierIsVatPayer ? t('invoice.items_table.total_incl_vat') : t('invoice.totals.total') }}</span>
              <input :value="itemTotal(item)" @change="setItemGross(item, ($event.target as HTMLInputElement).value)"
                type="text" inputmode="decimal" :title="t('invoice.items_table.gross_edit_hint')"
                class="w-32 h-9 px-2 border border-neutral-200 rounded text-right font-mono text-sm font-semibold" />
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
            <input v-model="form.revenue_category" type="text" maxlength="40"
                   class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm"
                   :placeholder="t('invoice.classification.revenue_category_placeholder')" />
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
                class="w-full h-9 pl-2 pr-7 border border-neutral-200 rounded text-right font-mono text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
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
          <input v-model="wrTitle" type="text" :placeholder="t('invoice.wr_title')"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
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
            <tbody class="divide-y divide-neutral-100">
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
                  <input v-model="it.description" type="text" class="w-full h-9 px-2 border border-neutral-200 rounded text-sm" />
                </td>
                <td class="px-2 py-1.5">
                  <input v-model="it.work_date" type="date" class="w-full h-9 px-2 border border-neutral-200 rounded text-sm font-mono" />
                </td>
                <td class="px-2 py-1.5">
                  <input v-model.number="it.hours" type="number" step="0.25" min="0" class="w-full h-9 px-2 border border-neutral-200 rounded text-sm text-right font-mono" />
                </td>
                <td class="px-2 py-1.5">
                  <input v-model.number="it.rate" type="number" step="1" min="0" class="w-full h-9 px-2 border border-neutral-200 rounded text-sm text-right font-mono" />
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
                    class="cursor-pointer px-3 h-8 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md inline-flex items-center gap-1">
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
                <input v-model="it.description" type="text" class="w-full h-10 px-3 border border-neutral-200 rounded text-sm bg-surface" />
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.wr_date') }}</label>
                  <input v-model="it.work_date" type="date" class="w-full h-10 px-3 border border-neutral-200 rounded text-sm font-mono bg-surface" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.wr_hours') }}</label>
                  <input v-model.number="it.hours" type="number" inputmode="decimal" step="0.25" min="0" class="w-full h-10 px-3 border border-neutral-200 rounded text-right font-mono text-sm bg-surface" />
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2 items-end">
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.wr_rate') }}</label>
                  <input v-model.number="it.rate" type="number" inputmode="decimal" step="1" min="0" class="w-full h-10 px-3 border border-neutral-200 rounded text-right font-mono text-sm bg-surface" />
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
              class="cursor-pointer w-full h-10 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md inline-flex items-center justify-center gap-1.5">
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

      <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ error }}
      </div>

      <!-- Action bar -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 flex justify-between items-center sticky bottom-3 shadow-md">
        <RouterLink to="/invoices" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('common.cancel') }}</RouterLink>
        <button type="submit" :disabled="submitting"
          class="px-5 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
          {{ submitting ? t('common.saving') : (isEdit ? t('common.save') : t('common.create')) }}
        </button>
      </div>
    </form>

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

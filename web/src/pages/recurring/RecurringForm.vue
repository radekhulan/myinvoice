<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { recurringApi, type RecurringTemplate, type RecurringTemplatePayload, type Frequency } from '@/api/recurring'
import { clientsApi, type Client, type ViesLookupResult } from '@/api/clients'
import { projectsApi, type Project } from '@/api/projects'
import { codebooksApi, type VatRate, type Currency, type Unit } from '@/api/codebooks'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import ClientFormModal from '@/components/modals/ClientFormModal.vue'
import ProjectFormModal from '@/components/modals/ProjectFormModal.vue'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const tplId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const loading = ref(false)
const submitting = ref(false)
const error = ref('')

const clients = ref<Client[]>([])
const projects = ref<Project[]>([])
const currencies = ref<Currency[]>([])
const vatRates = ref<VatRate[]>([])
const units = ref<Unit[]>([])

function defaultItemUnit(): string {
  return units.value.find(u => u.is_default)?.code || units.value[0]?.code || 'ks'
}

type FormItem = {
  description: string
  quantity: number
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  order_index: number
}

const form = ref<{
  client_id: number | null
  project_id: number | null
  name: string
  frequency: Frequency
  day_of_month: number | null
  end_of_month: boolean
  anchor_date: string
  end_date: string | null
  invoice_type: 'invoice' | 'proforma'
  currency_id: number
  language: 'cs' | 'en'
  payment_method: 'bank_transfer' | 'card' | 'cash' | 'other'
  reverse_charge: boolean
  discount_percent: number
  payment_due_days: number
  tax_date_mode: 'same_as_issue' | 'previous_month_last_day'
  draft_open_mode: 'at_issue' | 'period_start'
  reminder_days_before: number
  note_above_items: string
  note_below_items: string
  increment_month_in_descriptions: boolean
  auto_issue: boolean
  auto_send_email: boolean
  items: FormItem[]
}>({
  client_id: null,
  project_id: null,
  name: '',
  frequency: 'monthly',
  day_of_month: null,
  end_of_month: false,
  anchor_date: today(),
  end_date: null,
  invoice_type: 'invoice',
  currency_id: 0,
  language: 'cs',
  payment_method: 'bank_transfer',
  reverse_charge: false,
  discount_percent: 0,
  payment_due_days: 14,
  tax_date_mode: 'same_as_issue',
  draft_open_mode: 'at_issue',
  reminder_days_before: 1,
  note_above_items: '',
  note_below_items: '',
  increment_month_in_descriptions: true,
  auto_issue: true,
  auto_send_email: true,
  items: [],
})

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function dayFromDate(date: string): number | null {
  const day = parseInt(date.slice(8, 10), 10)
  if (!Number.isFinite(day) || day < 1) return null
  return Math.min(28, day)
}

const formLoaded = ref(false)

function defaultVatRateId(): number {
  const def = vatRates.value.find(v => v.is_default)
  return def?.id ?? vatRates.value[0]?.id ?? 0
}

function blankItem(): FormItem {
  return {
    description: '',
    quantity: 1,
    unit: defaultItemUnit(),
    unit_price_without_vat: 0,
    vat_rate_id: defaultVatRateId(),
    order_index: form.value.items.length,
  }
}

function addItem() {
  form.value.items.push(blankItem())
}
function removeItem(idx: number) {
  form.value.items.splice(idx, 1)
}

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

const computedAmountToPay = computed(() => {
  const buckets = new Map<number, { base: number; vat: number }>()
  for (const item of form.value.items) {
    const vatRate = form.value.reverse_charge
      ? 0
      : vatRates.value.find(v => v.id === item.vat_rate_id)?.rate_percent ?? 0
    const base = round2((Number(item.quantity) || 0) * (Number(item.unit_price_without_vat) || 0))
    const vat = round2(base * (vatRate / 100))
    if (!buckets.has(vatRate)) buckets.set(vatRate, { base: 0, vat: 0 })
    const b = buckets.get(vatRate)!
    b.base += base
    b.vat += vat
  }
  // Sleva na úrovni dokladu — odečte se na každé sazbě (zrcadlí materializaci
  // záporné položky „Sleva X %" v generátoru).
  const pct = Math.min(100, Math.max(0, Number(form.value.discount_percent) || 0))
  let totalBase = 0
  let totalVat = 0
  for (const b of buckets.values()) {
    let base = b.base
    let vat = b.vat
    if (pct > 0) {
      const disc = round2(base * (pct / 100))
      const rate = base !== 0 ? (b.vat / b.base) * 100 : 0
      base = round2(base - disc)
      vat = round2(vat - round2(disc * (rate / 100)))
    }
    totalBase = round2(totalBase + base)
    totalVat = round2(totalVat + vat)
  }
  return round2(totalBase + totalVat)
})

const currencyCode = computed(() =>
  currencies.value.find(c => c.id === form.value.currency_id)?.code ?? 'CZK'
)

const computedDiscountAmount = computed(() => {
  const pct = Math.min(100, Math.max(0, Number(form.value.discount_percent) || 0))
  if (pct <= 0) return 0
  let disc = 0
  const buckets = new Map<number, number>()
  for (const item of form.value.items) {
    const vatRate = form.value.reverse_charge
      ? 0
      : vatRates.value.find(v => v.id === item.vat_rate_id)?.rate_percent ?? 0
    const base = round2((Number(item.quantity) || 0) * (Number(item.unit_price_without_vat) || 0))
    buckets.set(vatRate, round2((buckets.get(vatRate) ?? 0) + base))
  }
  for (const base of buckets.values()) disc = round2(disc + round2(base * (pct / 100)))
  return disc
})

// Šablony nemají advance ani parent_invoice_id (vždy generují fresh invoice/proforma),
// takže stačí kontrola na total amount. Pokud se to v budoucnu změní, sjednotit s
// InvoiceEditor.requiresPositiveAmountToPay.
const hasNonPositiveAmountToPay = computed(() =>
  form.value.items.length > 0 && computedAmountToPay.value <= 0
)

function itemHasBothNegative(item: { quantity: number, unit_price_without_vat: number }): boolean {
  return Number(item.quantity) < 0 && Number(item.unit_price_without_vat) < 0
}

async function loadProjectsForClient(clientId: number) {
  if (!clientId) {
    projects.value = []
    return
  }
  projects.value = await projectsApi.listForClient(clientId)
}

// VIES ověření DIČ vybraného klienta (jen pokud má DIČ) — zrcadlí InvoiceEditor.
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

watch(() => form.value.client_id, async (newId) => {
  if (newId) {
    await loadProjectsForClient(newId)
    const c = clients.value.find(x => x.id === newId)
    if (c) {
      if (!form.value.name) form.value.name = c.company_name
      // Po výběru klienta převzít jeho default splatnosti. Až po prvotním načtení
      // (formLoaded), aby se v edit módu nepřepsala uložená hodnota šablony.
      // Pokud má klient default a zvolí se i zakázka, projektová splatnost níže přebije.
      if (formLoaded.value && typeof c.payment_due_default === 'number') {
        form.value.payment_due_days = c.payment_due_default
      }
    }
    await verifyClientVies(newId)
  } else {
    projects.value = []
    viesResult.value = null
  }
})

// Po výběru zakázky převzít její splatnost. Až po prvotním načtení formuláře,
// aby se v edit módu nepřepsala uložená hodnota při hydrataci.
watch(() => form.value.project_id, (newId) => {
  if (!formLoaded.value) return
  if (!newId) return
  const p = projects.value.find(x => x.id === newId)
  if (p && typeof p.payment_due_days === 'number') {
    form.value.payment_due_days = p.payment_due_days
  }
})

// Inline create modaly — žádné opouštění editoru pravidelné fakturace.
const clientModalOpen = ref(false)
const projectModalOpen = ref(false)

async function onClientCreatedInModal(client: Client) {
  clients.value = [client, ...clients.value.filter(c => c.id !== client.id)]
  form.value.client_id = client.id
  clientModalOpen.value = false
  // watch na client_id zavolá loadProjectsForClient + nastaví name
}

async function onProjectCreatedInModal(project: Project) {
  projects.value = [project, ...projects.value.filter(p => p.id !== project.id)]
  form.value.project_id = project.id
  if (typeof project.payment_due_days === 'number') {
    form.value.payment_due_days = project.payment_due_days
  }
  projectModalOpen.value = false
}

watch(() => form.value.end_of_month, (eom) => {
  if (eom) form.value.day_of_month = null
})

watch(() => form.value.anchor_date, (newDate) => {
  if (!formLoaded.value) return
  if (!newDate) return
  if (form.value.end_of_month) return
  if (form.value.day_of_month != null) return
  form.value.day_of_month = dayFromDate(newDate)
})

watch(() => form.value.auto_issue, (ai) => {
  if (!ai) form.value.auto_send_email = false
})

// „Otevřený koncept" (period_start) zatím podporujeme jen pro měsíční periodicitu
// a vyžaduje auto-vystavení (koncept se na konci období uzavře sám).
watch(() => form.value.frequency, (f) => {
  if (f !== 'monthly' && form.value.draft_open_mode === 'period_start') {
    form.value.draft_open_mode = 'at_issue'
  }
})
watch(() => form.value.draft_open_mode, (m) => {
  if (m === 'period_start') form.value.auto_issue = true
})

onMounted(async () => {
  loading.value = true
  try {
    const [cl, cur, vat, un] = await Promise.all([
      clientsApi.list({ archived: false }),
      codebooksApi.currencies(),
      codebooksApi.vatRates(),
      codebooksApi.units(),
    ])
    clients.value = cl.data
    currencies.value = cur
    vatRates.value = vat
    units.value = un

    if (form.value.currency_id === 0) {
      const def = cur.find(c => c.is_default && c.code === 'CZK') || cur[0]
      if (def) form.value.currency_id = def.id
    }

    // Initialize first item only when creating (not editing)
    if (!isEdit.value && form.value.items.length === 0) {
      form.value.items.push(blankItem())
    }

    // Pre-fill day_of_month from anchor_date for new templates so the user sees the
    // effective day instead of an empty field (server-side null fallback uses anchor's day too).
    if (!isEdit.value && form.value.day_of_month == null && !form.value.end_of_month) {
      form.value.day_of_month = dayFromDate(form.value.anchor_date)
    }

    // Pre-fill client_id from ?client_id=N (např. z ClientDetail "+ Nová šablona")
    const queryClientId = Number(route.query.client_id)
    if (!isEdit.value && queryClientId > 0) {
      form.value.client_id = queryClientId
      const c = clients.value.find(x => x.id === queryClientId)
      if (c) form.value.name = c.company_name
      await loadProjectsForClient(queryClientId)
    }

    // Pre-fill from existing invoice (?from_invoice=ID)
    const fromInvoice = route.query.from_invoice
    if (!isEdit.value && fromInvoice) {
      try {
        const mod = await import('@/api/invoices')
        const inv = await mod.invoicesApi.get(Number(fromInvoice))
        form.value.client_id = inv.client_id
        form.value.project_id = inv.project_id
        form.value.name = inv.client_company_name ?? ''
        form.value.invoice_type = inv.invoice_type === 'proforma' ? 'proforma' : 'invoice'
        form.value.currency_id = inv.currency_id
        form.value.language = inv.language
        form.value.payment_method = inv.payment_method ?? 'bank_transfer'
        form.value.reverse_charge = inv.reverse_charge
        form.value.discount_percent = inv.discount_percent ?? 0
        form.value.note_above_items = inv.note_above_items ?? ''
        form.value.note_below_items = inv.note_below_items ?? ''
        // Slevová položka se do šablony nepřenáší — drží se jako discount_percent.
        form.value.items = inv.items.filter(it => it.item_kind !== 'discount').map((it, i) => ({
          description: it.description,
          quantity: it.quantity,
          unit: it.unit,
          unit_price_without_vat: it.unit_price_without_vat,
          vat_rate_id: it.vat_rate_id,
          order_index: i,
        }))
        if (inv.client_id) await loadProjectsForClient(inv.client_id)
      } catch {
        // ignore — proceed with empty form
      }
    }

    if (isEdit.value && tplId.value) {
      const tpl: RecurringTemplate = await recurringApi.get(tplId.value)
      Object.assign(form.value, {
        client_id: tpl.client_id,
        project_id: tpl.project_id,
        name: tpl.name,
        frequency: tpl.frequency,
        day_of_month: tpl.day_of_month,
        end_of_month: tpl.end_of_month,
        anchor_date: tpl.anchor_date.slice(0, 10),
        end_date: tpl.end_date ? tpl.end_date.slice(0, 10) : null,
        invoice_type: tpl.invoice_type,
        currency_id: tpl.currency_id,
        language: tpl.language,
        payment_method: tpl.payment_method,
        reverse_charge: tpl.reverse_charge,
        discount_percent: tpl.discount_percent ?? 0,
        payment_due_days: tpl.payment_due_days,
        tax_date_mode: tpl.tax_date_mode ?? 'same_as_issue',
        draft_open_mode: tpl.draft_open_mode ?? 'at_issue',
        reminder_days_before: tpl.reminder_days_before ?? 1,
        note_above_items: tpl.note_above_items ?? '',
        note_below_items: tpl.note_below_items ?? '',
        increment_month_in_descriptions: tpl.increment_month_in_descriptions,
        auto_issue: tpl.auto_issue,
        auto_send_email: tpl.auto_send_email,
        items: (tpl.items ?? []).map(it => ({
          description: it.description,
          quantity: it.quantity,
          unit: it.unit,
          unit_price_without_vat: it.unit_price_without_vat,
          vat_rate_id: it.vat_rate_id,
          order_index: it.order_index,
        })),
      })
      if (tpl.client_id) await loadProjectsForClient(tpl.client_id)
    }
  } finally {
    loading.value = false
    formLoaded.value = true
  }
})

async function submit() {
  error.value = ''
  if (!form.value.client_id) { error.value = 'Klient je povinný'; return }
  if (!form.value.name.trim()) { error.value = t('recurring.name_required'); return }
  if (form.value.items.length === 0) { error.value = t('recurring.items_required'); return }
  if (form.value.auto_send_email && !form.value.auto_issue) {
    error.value = t('recurring.auto_send_requires_issue')
    return
  }
  if (form.value.draft_open_mode === 'period_start') {
    if (form.value.frequency !== 'monthly') {
      error.value = t('recurring.period_start_requires_monthly')
      return
    }
    if (!form.value.auto_issue) {
      error.value = t('recurring.period_start_requires_auto_issue')
      return
    }
  }
  if (hasNonPositiveAmountToPay.value) {
    error.value = t('invoice.amount_positive_required')
    return
  }

  submitting.value = true
  try {
    const payload: RecurringTemplatePayload = {
      client_id: form.value.client_id!,
      project_id: form.value.project_id,
      name: form.value.name.trim(),
      frequency: form.value.frequency,
      day_of_month: form.value.end_of_month ? null : form.value.day_of_month,
      end_of_month: form.value.end_of_month,
      anchor_date: form.value.anchor_date,
      end_date: form.value.end_date || null,
      invoice_type: form.value.invoice_type,
      currency_id: form.value.currency_id,
      language: form.value.language,
      payment_method: form.value.payment_method,
      reverse_charge: form.value.reverse_charge,
      discount_percent: form.value.discount_percent || 0,
      payment_due_days: form.value.payment_due_days,
      tax_date_mode: form.value.tax_date_mode,
      draft_open_mode: form.value.draft_open_mode,
      reminder_days_before: form.value.reminder_days_before,
      note_above_items: form.value.note_above_items || null,
      note_below_items: form.value.note_below_items || null,
      increment_month_in_descriptions: form.value.increment_month_in_descriptions,
      auto_issue: form.value.auto_issue,
      auto_send_email: form.value.auto_send_email,
      items: form.value.items.map((it, i) => ({
        description: it.description,
        quantity: it.quantity,
        unit: it.unit,
        unit_price_without_vat: it.unit_price_without_vat,
        vat_rate_id: it.vat_rate_id,
        order_index: i,
      })),
    }
    if (isEdit.value && tplId.value) {
      await recurringApi.update(tplId.value, payload)
    } else {
      await recurringApi.create(payload)
    }
    toast.success(t('recurring.saved'))
    router.push({ name: 'recurring' })
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message ?? 'Error'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="max-w-5xl">
    <h1 class="text-2xl font-bold text-neutral-900 mb-5">
      {{ isEdit ? t('recurring.form_title_edit') : t('recurring.form_title_new') }}
    </h1>

    <div v-if="loading" class="text-center py-12 text-neutral-400">…</div>
    <form v-else @submit.prevent="submit" class="space-y-5">
      <!-- Basics -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.name') }} *</label>
          <input v-model="form.name" type="text" maxlength="200"
            :placeholder="t('recurring.name_placeholder')"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.client') }} *</label>
            <div class="flex gap-2">
              <div class="flex-1 min-w-0">
                <SearchableSelect
                  :model-value="form.client_id"
                  @update:model-value="(v) => { form.client_id = v }"
                  :options="clients.filter(c => c.is_customer !== false).map(c => ({ value: c.id, label: c.company_name }))"
                  :placeholder="t('recurring.client')"
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
            <!-- VIES výsledek — zrcadlí InvoiceEditor -->
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
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.project') }}</label>
            <div class="flex gap-2">
              <div class="flex-1 min-w-0">
                <SearchableSelect
                  :model-value="form.project_id"
                  @update:model-value="(v) => { form.project_id = v }"
                  :options="projects.map(p => ({ value: p.id, label: p.name }))"
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
        </div>
      </div>

      <!-- Periodicity -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('recurring.section_periodicity') }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.frequency') }} *</label>
            <select v-model="form.frequency" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option value="monthly">{{ t('recurring.frequency_monthly') }}</option>
              <option value="quarterly">{{ t('recurring.frequency_quarterly') }}</option>
              <option value="semi_annually">{{ t('recurring.frequency_semi_annually') }}</option>
              <option value="annually">{{ t('recurring.frequency_annually') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.day_of_month') }}</label>
            <input v-model.number="form.day_of_month" type="number" min="1" max="28"
              :disabled="form.end_of_month"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md disabled:bg-neutral-50 disabled:text-neutral-400" />
          </div>
        </div>
        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input v-model="form.end_of_month" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" />
          <span>
            <span class="font-medium">{{ t('recurring.end_of_month') }}</span>
            <span class="block text-xs text-neutral-500">{{ t('recurring.day_of_month_hint') }}</span>
          </span>
        </label>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.anchor_date') }} *</label>
            <input v-model="form.anchor_date" type="date"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.end_date') }}</label>
            <input v-model="form.end_date" type="date"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </div>
        </div>
        <p class="text-xs text-neutral-500 -mt-2">{{ t('recurring.end_date_hint') }}</p>
      </div>

      <!-- Invoice metadata -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('recurring.section_invoice_meta') }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.invoice_type') }}</label>
            <select v-model="form.invoice_type" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option value="invoice">{{ t('type.invoice') }}</option>
              <option value="proforma">{{ t('type.proforma') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.currency') }}</label>
            <select v-model.number="form.currency_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.language') }}</label>
            <select v-model="form.language" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option value="cs">CZ</option>
              <option value="en">EN</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('payment_method.label') }}</label>
            <select v-model="form.payment_method" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option value="bank_transfer">{{ t('payment_method.bank_transfer') }}</option>
              <option value="card">{{ t('payment_method.card') }}</option>
              <option value="cash">{{ t('payment_method.cash') }}</option>
              <option value="other">{{ t('payment_method.other') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.payment_due_days') }}</label>
            <input v-model.number="form.payment_due_days" type="number" min="0"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </div>
          <div>
            <label for="rec_discount_percent" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.discount.label') }}</label>
            <div class="relative">
              <input id="rec_discount_percent" v-model.number="form.discount_percent" type="number" min="0" max="100" step="0.01"
                class="w-full h-10 pl-3 pr-8 border border-neutral-300 rounded-md text-right font-mono" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-400 pointer-events-none">%</span>
            </div>
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.tax_date_mode') }}</label>
            <select v-model="form.tax_date_mode"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option value="same_as_issue">{{ t('recurring.tax_date_mode_same_as_issue') }}</option>
              <option value="previous_month_last_day">{{ t('recurring.tax_date_mode_previous_month_last_day') }}</option>
            </select>
            <p class="mt-1 text-xs text-neutral-500">{{ t('recurring.tax_date_mode_hint') }}</p>
          </div>
        </div>
      </div>

      <!-- Items -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('recurring.items') }}</h3>
          <button type="button" @click="addItem"
            class="cursor-pointer px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md font-medium">
            {{ t('invoice.add_item') }}
          </button>
        </div>
        <p class="mb-3 text-xs text-neutral-500">{{ t('invoice.negative_item_hint') }}</p>
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-xs text-neutral-500">
            <tr>
              <th class="text-left">{{ t('invoice.items_table.description') }}</th>
              <th class="text-right" style="width:8%">{{ t('invoice.items_table.qty') }}</th>
              <th class="text-left" style="width:8%">{{ t('invoice.items_table.unit') }}</th>
              <th class="text-right" style="width:15%">{{ t('invoice.items_table.unit_price') }}</th>
              <th class="text-left" style="width:14%">{{ t('invoice.items_table.vat') ?? 'DPH' }}</th>
              <th style="width:30px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(it, idx) in form.items" :key="idx" :class="['border-t border-neutral-100', itemHasBothNegative(it) ? 'bg-danger-50' : '']">
              <td class="py-1.5 pr-2"><input v-model="it.description" type="text" class="w-full h-8 px-2 border border-neutral-200 rounded" /></td>
              <td class="py-1.5 pr-2"><input v-model="it.quantity" v-math type="text" inputmode="decimal" :class="['w-full h-8 px-2 border rounded text-right font-mono', itemHasBothNegative(it) ? 'border-danger-400' : 'border-neutral-200']" /></td>
              <td class="py-1.5 pr-2">
                <select v-model="it.unit" class="w-full h-8 px-1 border border-neutral-200 rounded bg-surface text-sm">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                  <option v-if="it.unit && !units.some(u => u.code === it.unit)" :value="it.unit">{{ it.unit }}</option>
                </select>
              </td>
              <td class="py-1.5 pr-2"><input v-model="it.unit_price_without_vat" v-math type="text" inputmode="decimal" :class="['w-full h-8 px-2 border rounded text-right font-mono', itemHasBothNegative(it) ? 'border-danger-400' : 'border-neutral-200']" /></td>
              <td class="py-1.5 pr-2">
                <select v-model.number="it.vat_rate_id" class="w-full h-8 px-2 border border-neutral-200 rounded bg-surface">
                  <option v-for="r in vatRates" :key="r.id" :value="r.id">
                    {{ Number(r.rate_percent) > 0 ? r.rate_percent + ' %' : (r.is_reverse_charge ? 'RC' : '0 %') }}
                  </option>
                </select>
              </td>
              <td class="py-1.5 text-right">
                <button type="button" @click="removeItem(idx)" class="cursor-pointer text-danger-500 hover:text-danger-700 text-lg">×</button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: stack karet (každé pole na vlastním řádku, čitelné inputy) -->
        <div v-if="form.items.length > 0" class="md:hidden divide-y divide-neutral-100 border-t border-neutral-100">
          <div v-for="(it, idx) in form.items" :key="`m-${idx}`" :class="['py-3 space-y-2', itemHasBothNegative(it) ? 'bg-danger-50' : '']">
            <div class="flex items-center justify-between text-xs text-neutral-500">
              <span class="font-mono">#{{ idx + 1 }}</span>
              <button type="button" @click="removeItem(idx)" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded text-lg leading-none">×</button>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.description') }}</label>
              <input v-model="it.description" type="text" class="w-full h-10 px-3 border border-neutral-200 rounded text-sm" />
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.qty') }}</label>
                <input v-model="it.quantity" v-math type="text" inputmode="decimal" :class="['w-full h-10 px-3 border rounded text-right font-mono text-sm', itemHasBothNegative(it) ? 'border-danger-400' : 'border-neutral-200']" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.unit') }}</label>
                <select v-model="it.unit" class="w-full h-10 px-2 border border-neutral-200 rounded bg-surface text-sm">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                  <option v-if="it.unit && !units.some(u => u.code === it.unit)" :value="it.unit">{{ it.unit }}</option>
                </select>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.unit_price') }}</label>
                <input v-model="it.unit_price_without_vat" v-math type="text" inputmode="decimal" :class="['w-full h-10 px-3 border rounded text-right font-mono text-sm', itemHasBothNegative(it) ? 'border-danger-400' : 'border-neutral-200']" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.vat') ?? 'DPH' }}</label>
                <select v-model.number="it.vat_rate_id" class="w-full h-10 px-2 border border-neutral-200 rounded bg-surface text-sm">
                  <option v-for="r in vatRates" :key="r.id" :value="r.id">
                    {{ Number(r.rate_percent) > 0 ? r.rate_percent + ' %' : (r.is_reverse_charge ? 'RC' : '0 %') }}
                  </option>
                </select>
              </div>
            </div>
          </div>
        </div>
        <dl v-if="form.items.length > 0" class="mt-4 ml-auto max-w-xs space-y-1 text-sm">
          <div v-if="computedDiscountAmount > 0" class="flex justify-between text-warning-700">
            <dt>{{ t('invoice.discount.applied') }} {{ form.discount_percent }} %</dt>
            <dd class="font-mono">−{{ formatMoney(computedDiscountAmount, currencyCode) }}</dd>
          </div>
          <div class="flex justify-between font-semibold border-t border-neutral-200 pt-1">
            <dt>{{ t('invoice.totals.total') }}</dt>
            <dd class="font-mono">{{ formatMoney(computedAmountToPay, currencyCode) }}</dd>
          </div>
        </dl>
        <div v-if="hasNonPositiveAmountToPay" class="mt-3 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-xs text-warning-700">
          {{ t('invoice.amount_positive_required') }}
        </div>
      </div>

      <!-- Automation -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-3">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('recurring.section_automation') }}</h3>

        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.draft_open_mode') }}</label>
          <select v-model="form.draft_open_mode"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
            <option value="at_issue">{{ t('recurring.draft_open_mode_at_issue') }}</option>
            <option value="period_start" :disabled="form.frequency !== 'monthly'">
              {{ t('recurring.draft_open_mode_period_start') }}
            </option>
          </select>
          <p class="mt-1 text-xs text-neutral-500">
            {{ form.frequency === 'monthly' ? t('recurring.draft_open_mode_hint') : t('recurring.draft_open_mode_monthly_only') }}
          </p>
        </div>

        <div v-if="form.draft_open_mode === 'period_start'">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.reminder_days_before') }}</label>
          <input v-model.number="form.reminder_days_before" type="number" min="0" max="14"
            class="w-full md:w-40 h-10 px-3 border border-neutral-300 rounded-md" />
          <p class="mt-1 text-xs text-neutral-500">{{ t('recurring.reminder_days_before_hint') }}</p>
        </div>

        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input v-model="form.increment_month_in_descriptions" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" />
          <span>{{ t('recurring.increment_month') }}</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input v-model="form.auto_issue" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" />
          <span>{{ t('recurring.auto_issue') }}</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input v-model="form.auto_send_email" type="checkbox" :disabled="!form.auto_issue"
            class="mt-1 rounded border-neutral-300 text-primary-600 disabled:opacity-50" />
          <span :class="!form.auto_issue ? 'text-neutral-400' : ''">{{ t('recurring.auto_send_email') }}</span>
        </label>
      </div>

      <div v-if="error" class="p-3 bg-danger-50 border border-danger-200 text-danger-700 rounded text-sm">{{ error }}</div>

      <div class="flex justify-end gap-3">
        <button type="button" @click="router.push({ name: 'recurring' })"
          class="cursor-pointer px-4 h-10 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">
          {{ t('common.cancel') }}
        </button>
        <button type="submit" :disabled="submitting"
          class="cursor-pointer px-4 h-10 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ submitting ? '…' : t('common.save') }}
        </button>
      </div>
    </form>

    <!-- Inline create modaly — žádné opouštění editoru -->
    <ClientFormModal v-if="clientModalOpen"
      @created="onClientCreatedInModal"
      @close="clientModalOpen = false" />
    <ProjectFormModal v-if="projectModalOpen && form.client_id"
      :client-id="form.client_id"
      @created="onProjectCreatedInModal"
      @close="projectModalOpen = false" />
  </div>
</template>

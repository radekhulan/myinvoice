<script setup lang="ts">
import { ref, onMounted, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type Supplier, type CurrencyAccount } from '@/api/settings'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { renderVarsymbolTemplate, hasCounterPlaceholder } from '@/utils/varsymbol'

const { t } = useI18n()
const toast = useToast()

const supplier = ref<Supplier | null>(null)
const currencies = ref<CurrencyAccount[]>([])
const loading = ref(true)

const editingCurrency = ref<number | null>(null)
const editingCurrencyLabel = ref<string>('')
const currencyDraft = reactive<Partial<CurrencyAccount>>({})

useHotkey('escape', () => { if (editingCurrency.value !== null) editingCurrency.value = null })

// Live preview pro číslování faktur — okamžitá zpětná vazba pod každým polem.
// Chybějící counter → červený error; jinak „Náhled: JD2026-01".
function validateAndPreview(template: string | null) {
  const tmpl = (template ?? '').trim()
  if (tmpl === '') return { error: '', preview: '' }
  if (!hasCounterPlaceholder(tmpl)) return { error: t('settings.numbering_must_have_counter'), preview: '' }
  return { error: '', preview: renderVarsymbolTemplate(tmpl, new Date(), 1) }
}
// Výchozí splatnost — UI preset ('7' / '14' / 'month' / 'custom') je odvozen z dvojice
// (default_payment_due_days, default_payment_due_unit). 'month' znamená přesně 1 kalendářní
// měsíc (days=1, unit='month'); 'custom' nechá volný číselný input v dnech.
type DuePreset = '7' | '14' | 'month' | 'custom'
// 'custom' musí být „sticky" i když hodnota náhodou odpovídá presetu (7/14) — jinak
// by getter spadl zpět na preset a číselný input by se nikdy neukázal.
const dueCustom = ref(false)
const dueSelectValue = computed<DuePreset>({
  get() {
    if (!supplier.value) return '7'
    if (dueCustom.value) return 'custom'
    const d = supplier.value.default_payment_due_days
    const u = supplier.value.default_payment_due_unit
    if (u === 'month' && d === 1) return 'month'
    if (u === 'days' && d === 7)  return '7'
    if (u === 'days' && d === 14) return '14'
    return 'custom'
  },
  set(v: DuePreset) {
    if (!supplier.value) return
    dueCustom.value = (v === 'custom')
    if (v === '7') {
      supplier.value.default_payment_due_days = 7
      supplier.value.default_payment_due_unit = 'days'
    } else if (v === '14') {
      supplier.value.default_payment_due_days = 14
      supplier.value.default_payment_due_unit = 'days'
    } else if (v === 'month') {
      supplier.value.default_payment_due_days = 1
      supplier.value.default_payment_due_unit = 'month'
    } else {
      supplier.value.default_payment_due_unit = 'days'
      // days zachovat — pokud byl 7/14 user dostane editovatelnou hodnotu k úpravě
    }
  },
})

const invoicePreview        = computed(() => validateAndPreview(supplier.value?.invoice_number_format ?? null).preview)
const invoiceFormatError    = computed(() => validateAndPreview(supplier.value?.invoice_number_format ?? null).error)
const proformaPreview       = computed(() => validateAndPreview(supplier.value?.proforma_number_format ?? null).preview)
const proformaFormatError   = computed(() => validateAndPreview(supplier.value?.proforma_number_format ?? null).error)
const creditNotePreview     = computed(() => validateAndPreview(supplier.value?.credit_note_number_format ?? null).preview)
const creditNoteFormatError = computed(() => validateAndPreview(supplier.value?.credit_note_number_format ?? null).error)

async function load() {
  loading.value = true
  try {
    [supplier.value, currencies.value] = await Promise.all([
      settingsApi.getSupplier(),
      settingsApi.listCurrencies(),
    ])
    // První render preview hned po loadu supplier
    bumpPreview()
  } finally { loading.value = false }
}

onMounted(load)

async function saveSupplier() {
  if (!supplier.value) return
  // Klient-side guard pro varsymbol formáty — stejná pravidla jako backend, ale uživatel
  // dostane okamžitou zpětnou vazbu (hláška u pole) místo toastu, který zmizí.
  const errs = [invoiceFormatError.value, proformaFormatError.value, creditNoteFormatError.value].filter(Boolean)
  if (errs.length > 0) {
    toast.error(errs[0])
    return
  }
  try {
    supplier.value = await settingsApi.updateSupplier({
      company_name: supplier.value.company_name,
      display_name: supplier.value.display_name,
      street: supplier.value.street,
      city: supplier.value.city,
      zip: supplier.value.zip,
      ic: supplier.value.ic,
      dic: supplier.value.dic,
      is_vat_payer: supplier.value.is_vat_payer,
      email: supplier.value.email,
      phone: supplier.value.phone,
      web: supplier.value.web,
      tagline: supplier.value.tagline,
      commercial_register: supplier.value.commercial_register,
      default_payment_due_days: supplier.value.default_payment_due_days,
      default_payment_due_unit: supplier.value.default_payment_due_unit,
      default_hourly_rate: supplier.value.default_hourly_rate,
      auto_send_reminders: supplier.value.auto_send_reminders,
      auto_generate_recurring: supplier.value.auto_generate_recurring,
      embed_isdoc: supplier.value.embed_isdoc,
      pohoda_account_code: supplier.value.pohoda_account_code,
      pohoda_centre_code: supplier.value.pohoda_centre_code,
      pohoda_activity_code: supplier.value.pohoda_activity_code,
      pohoda_contract_code: supplier.value.pohoda_contract_code,
      invoice_number_format: supplier.value.invoice_number_format,
      proforma_number_format: supplier.value.proforma_number_format,
      credit_note_number_format: supplier.value.credit_note_number_format,
      invoice_number_period: supplier.value.invoice_number_period,
      email_branding_enabled: supplier.value.email_branding_enabled,
      email_accent_color: supplier.value.email_accent_color,
      // Tax settings (EPO výkazy DPH/KH)
      taxpayer_type: (supplier.value as any).taxpayer_type ?? null,
      vat_period: (supplier.value as any).vat_period ?? null,
      flat_tax_band: (supplier.value as any).flat_tax_band ?? 'none',
      financial_office_code: (supplier.value as any).financial_office_code ?? null,
      workplace_code: (supplier.value as any).workplace_code ?? null,
      cz_nace_code: (supplier.value as any).cz_nace_code ?? null,
      data_box_type: (supplier.value as any).data_box_type ?? null,
      data_box_id: (supplier.value as any).data_box_id ?? null,
      sest_jmeno: (supplier.value as any).sest_jmeno ?? null,
      sest_prijmeni: (supplier.value as any).sest_prijmeni ?? null,
      sest_telefon: (supplier.value as any).sest_telefon ?? null,
      sest_email: (supplier.value as any).sest_email ?? null,
      sest_funkce: (supplier.value as any).sest_funkce ?? null,
      // Doplňky pro DPH/KH XML VetaP
      street_number_pop: (supplier.value as any).street_number_pop ?? null,
      street_number_orient: (supplier.value as any).street_number_orient ?? null,
      opr_jmeno: (supplier.value as any).opr_jmeno ?? null,
      opr_prijmeni: (supplier.value as any).opr_prijmeni ?? null,
      opr_postaveni: (supplier.value as any).opr_postaveni ?? null,
    })
    toast.success(t('common.saved'))
    bumpPreview()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// === Email branding ===========================================================
const previewLocale = ref<'cs' | 'en'>('cs')
const previewHtml = ref<string>('')
async function bumpPreview() {
  if (!supplier.value) return
  try {
    previewHtml.value = await settingsApi.emailPreviewHtml(previewLocale.value)
  } catch (e: any) {
    previewHtml.value = `<pre style="color:red">${e?.message || 'Preview failed'}</pre>`
  }
}

// Uloží jen branding pole (email_branding_enabled + email_accent_color),
// nešahá na zbytek supplier formuláře. Logo se ukládá samo při uploadu.
// silent=true: žádný success toast (auto-save z watcheru — bylo by chatty).
async function saveBranding(silent = false) {
  if (!supplier.value) return
  if (!/^#[0-9A-Fa-f]{6}$/.test(supplier.value.email_accent_color || '')) {
    if (!silent) toast.error(t('settings.branding_color_invalid'))
    return
  }
  try {
    const updated = await settingsApi.updateSupplier({
      email_branding_enabled: supplier.value.email_branding_enabled,
      email_accent_color: supplier.value.email_accent_color,
      pdf_logo_show_name: supplier.value.pdf_logo_show_name,
    })
    // Merge response do reactive supplier (zachová local-only fields jako has_email_logo)
    supplier.value = { ...supplier.value, ...updated }
    if (!silent) toast.success(t('common.saved'))
    bumpPreview()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
// Auto-load při změně locale; první load triggernout po načtení supplier (v load()).
watch(previewLocale, () => { if (supplier.value) bumpPreview() })

// Auto-save toggle (okamžitě) a accent color (debounce 500 ms — color picker fires
// kontinuálně při tažení). Po každém uloženém průchodu se obnoví preview iframe.
// Guarded `watching` flag, ať initial load supplier z load() netriggerne save.
let watching = false
watch(supplier, () => {
  // První zápis do supplier.value (initial load) by neměl spustit save.
  // Aktivujeme watcher až v dalším tickeu po vyřešení load().
  setTimeout(() => { watching = true }, 0)
}, { once: true })

let colorTimer: ReturnType<typeof setTimeout> | null = null
watch(() => supplier.value?.email_branding_enabled, () => { if (watching) saveBranding(true) })
watch(() => supplier.value?.pdf_logo_show_name, () => { if (watching) saveBranding(true) })
watch(() => supplier.value?.email_accent_color, () => {
  if (!watching) return
  if (colorTimer) clearTimeout(colorTimer)
  colorTimer = setTimeout(() => saveBranding(true), 500)
})
const logoFileInput = ref<HTMLInputElement | null>(null)
const logoUploading = ref(false)
function pickLogo() { logoFileInput.value?.click() }
async function onLogoSelected(ev: Event) {
  const f = (ev.target as HTMLInputElement).files?.[0]
  if (!f || !supplier.value) return
  if (f.size > 1_048_576) {
    toast.error(t('settings.branding_logo_too_large'))
    if (logoFileInput.value) logoFileInput.value.value = ''
    return
  }
  logoUploading.value = true
  try {
    const result = await settingsApi.uploadEmailLogo(f)
    supplier.value.logo_path = result.logo_path
    supplier.value.has_email_logo = true
    toast.success(t('settings.branding_logo_uploaded'))
    bumpPreview()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    logoUploading.value = false
    if (logoFileInput.value) logoFileInput.value.value = ''
  }
}
async function removeLogo() {
  if (!supplier.value) return
  if (!window.confirm(t('settings.branding_logo_remove_confirm'))) return
  try {
    await settingsApi.deleteEmailLogo()
    supplier.value.logo_path = null
    supplier.value.has_email_logo = false
    toast.success(t('settings.branding_logo_removed'))
    bumpPreview()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function startEditCurrency(c: CurrencyAccount) {
  editingCurrency.value = c.id
  editingCurrencyLabel.value = c.label
  Object.assign(currencyDraft, { ...c })
}

async function saveCurrency() {
  if (editingCurrency.value === null) return
  try {
    const updated = await settingsApi.updateCurrency(editingCurrency.value, {
      label: currencyDraft.label,
      is_active: currencyDraft.is_active,
      is_default: currencyDraft.is_default,
      account_number: currencyDraft.account_number || null,
      bank_code: currencyDraft.bank_code || null,
      bank_name: currencyDraft.bank_name || null,
      iban: currencyDraft.iban || null,
      bic: currencyDraft.bic || null,
    })
    currencies.value = await settingsApi.listCurrencies()
    editingCurrency.value = null
    toast.success(`${updated.code} (${updated.label}) — ${t('common.saved')}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function addCurrencyAccount(code: string) {
  const label = window.prompt(t('settings.add_account_prompt', { code }), t('settings.add_account_default_label', { code }))
  if (!label) return
  try {
    await settingsApi.createCurrency({ code, label, is_active: true })
    currencies.value = await settingsApi.listCurrencies()
    toast.success(`${label} — ${t('common.saved')}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeCurrency(c: CurrencyAccount) {
  if (!window.confirm(t('settings.delete_account_confirm', { label: c.label }))) return
  try {
    await settingsApi.deleteCurrency(c.id)
    currencies.value = await settingsApi.listCurrencies()
    toast.success(`${c.label} — ${t('common.deleted')}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('settings.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('settings.subtitle') }}</p>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="supplier" class="space-y-6">
      <!-- Supplier -->
      <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.supplier') }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.company_name') }} *</label>
            <input v-model="supplier.company_name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.display_name') }}</label>
            <input v-model="supplier.display_name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.street') }}</label>
            <input v-model="supplier.street" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.zip') }}</label>
              <input v-model="supplier.zip" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.city') }}</label>
              <input v-model="supplier.city" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.ic') }}</label>
            <input v-model="supplier.ic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.dic') }}</label>
            <input v-model="supplier.dic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="flex items-center gap-2 text-sm mt-7">
              <input v-model="supplier.is_vat_payer" type="checkbox" class="rounded border-neutral-300 text-primary-600"
                @change="supplier.is_vat_payer && ((supplier as any).flat_tax_band = 'none')" />
              {{ t('settings.is_vat_payer') }}
            </label>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.email') }} *</label>
            <input v-model="supplier.email" type="email" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.phone') }}</label>
            <input v-model="supplier.phone" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.web') }}</label>
            <input v-model="supplier.web" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.tagline') }}</label>
            <input v-model="supplier.tagline" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.commercial_register') }}</label>
            <input v-model="supplier.commercial_register" type="text"
              :placeholder="t('settings.commercial_register_placeholder')"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('settings.commercial_register_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.default_due_label') }}</label>
            <div class="flex gap-2">
              <select v-model="dueSelectValue" class="h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface" :class="dueSelectValue === 'custom' ? 'w-40' : 'w-full'">
                <option value="7">{{ t('settings.default_due_preset_7') }}</option>
                <option value="14">{{ t('settings.default_due_preset_14') }}</option>
                <option value="month">{{ t('settings.default_due_preset_month') }}</option>
                <option value="custom">{{ t('settings.default_due_preset_custom') }}</option>
              </select>
              <div v-if="dueSelectValue === 'custom'" class="flex items-center gap-2 flex-1">
                <input v-model.number="supplier.default_payment_due_days" type="number" min="0" class="w-24 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                <span class="text-sm text-neutral-500">{{ t('settings.default_due_custom_days_suffix') }}</span>
              </div>
            </div>
            <p v-if="dueSelectValue === 'month'" class="text-xs text-neutral-500 mt-1">{{ t('settings.default_due_month_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.default_hourly_rate') }} ({{ supplier.default_currency }})</label>
            <input v-model.number="supplier.default_hourly_rate" type="number" step="0.01" min="0" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="supplier.auto_send_reminders" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('settings.auto_send_reminders') }}
            </label>
            <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.auto_send_reminders_hint') }}</p>
          </div>
          <div class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="supplier.auto_generate_recurring" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('settings.auto_generate_recurring') }}
            </label>
            <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.auto_generate_recurring_hint') }}</p>
          </div>
          <div class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="supplier.embed_isdoc" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('settings.embed_isdoc') }}
            </label>
            <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.embed_isdoc_hint') }}</p>
          </div>
        </div>

      </section>

      <!-- Číslování faktur — samostatný box -->
      <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.numbering_section') }}</h2>
        <div>
          <h3 class="sr-only">{{ t('settings.numbering_section') }}</h3>
          <p class="text-xs text-neutral-500 mb-1">{{ t('settings.numbering_hint_intro') }}</p>
          <ul class="text-xs text-neutral-500 mb-3 space-y-0.5 ml-2">
            <li><code class="bg-neutral-100 px-1 rounded">{YYYY}</code> &mdash; {{ t('settings.numbering_hint_yyyy') }} <span class="text-neutral-400">(2026)</span></li>
            <li><code class="bg-neutral-100 px-1 rounded">{YY}</code> &mdash; {{ t('settings.numbering_hint_yy') }} <span class="text-neutral-400">(26)</span></li>
            <li><code class="bg-neutral-100 px-1 rounded">{MM}</code> &mdash; {{ t('settings.numbering_hint_mm') }} <span class="text-neutral-400">(05)</span></li>
            <li><code class="bg-neutral-100 px-1 rounded">{CC}</code>, <code class="bg-neutral-100 px-1 rounded">{CCC}</code>&hellip; &mdash; {{ t('settings.numbering_hint_c') }} <span class="text-neutral-400">(01, 001…)</span></li>
          </ul>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.invoice_number_format') }}</label>
              <input v-model="supplier.invoice_number_format" type="text"
                :placeholder="supplier.cfg_varsymbol_fallback?.invoice || '{YY}{MM}{CCC}'" maxlength="60"
                class="w-full h-9 px-3 border rounded-md text-sm font-mono"
                :class="invoiceFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
              <p v-if="invoiceFormatError" class="text-xs text-danger-500 mt-1">{{ invoiceFormatError }}</p>
              <p v-else-if="invoicePreview" class="text-xs text-success-600 mt-1">
                {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ invoicePreview }}</code>
              </p>
              <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.invoice_number_period') }}</label>
              <select v-model="supplier.invoice_number_period" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm">
                <option value="year">{{ t('settings.numbering_period_year') }}</option>
                <option value="month">{{ t('settings.numbering_period_month') }}</option>
                <option value="none">{{ t('settings.numbering_period_none') }}</option>
              </select>
              <p class="text-xs text-neutral-400 mt-1">{{ t('settings.invoice_number_period_hint') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.proforma_number_format') }}</label>
              <input v-model="supplier.proforma_number_format" type="text"
                :placeholder="supplier.cfg_varsymbol_fallback?.proforma || '9{YY}{MM}{CCC}'" maxlength="60"
                class="w-full h-9 px-3 border rounded-md text-sm font-mono"
                :class="proformaFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
              <p v-if="proformaFormatError" class="text-xs text-danger-500 mt-1">{{ proformaFormatError }}</p>
              <p v-else-if="proformaPreview" class="text-xs text-success-600 mt-1">
                {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ proformaPreview }}</code>
              </p>
              <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.credit_note_number_format') }}</label>
              <input v-model="supplier.credit_note_number_format" type="text"
                :placeholder="supplier.cfg_varsymbol_fallback?.credit_note || '7{YY}{MM}{CCC}'" maxlength="60"
                class="w-full h-9 px-3 border rounded-md text-sm font-mono"
                :class="creditNoteFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
              <p v-if="creditNoteFormatError" class="text-xs text-danger-500 mt-1">{{ creditNoteFormatError }}</p>
              <p v-else-if="creditNotePreview" class="text-xs text-success-600 mt-1">
                {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ creditNotePreview }}</code>
              </p>
              <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
            </div>
          </div>
        </div>

      </section>

      <!-- Daňové nastavení (EPO výkazy DPH/KH/DPFO/DPPO) — samostatný box -->
      <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.tax_section') }}</h2>
        <div>
          <h3 class="sr-only">{{ t('settings.tax_section') }}</h3>
          <p class="text-xs text-neutral-500 mb-3">{{ t('settings.tax_hint') }}</p>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.taxpayer_type') }}</label>
              <select v-model="supplier.taxpayer_type" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="null">— {{ t('common.unset') }} —</option>
                <option value="fo">{{ t('settings.taxpayer_fo') }}</option>
                <option value="po">{{ t('settings.taxpayer_po') }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.vat_period') }}</label>
              <select v-model="supplier.vat_period" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="null">— {{ t('common.unset') }} —</option>
                <option value="monthly">{{ t('settings.vat_monthly') }}</option>
                <option value="quarterly">{{ t('settings.vat_quarterly') }}</option>
              </select>
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.vat_period_hint') }}</p>
            </div>
            <div v-if="!supplier.is_vat_payer">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.flat_tax_band') }}</label>
              <select v-model="(supplier as any).flat_tax_band" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="none">{{ t('settings.flat_tax_none') }}</option>
                <option value="band1">{{ t('settings.flat_tax_band1') }}</option>
                <option value="band2">{{ t('settings.flat_tax_band2') }}</option>
                <option value="band3">{{ t('settings.flat_tax_band3') }}</option>
              </select>
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.flat_tax_hint') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.financial_office_code') }}</label>
              <input v-model="supplier.financial_office_code" type="text" maxlength="8" placeholder="451"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.financial_office_hint') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.workplace_code') }}</label>
              <input v-model="supplier.workplace_code" type="text" maxlength="8"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.cz_nace_code') }}</label>
              <input v-model="supplier.cz_nace_code" type="text" maxlength="8" placeholder="62.01"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.cz_nace_hint') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.data_box_id') }}</label>
              <input v-model="supplier.data_box_id" type="text" maxlength="16"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.street_number_pop') }}</label>
              <input v-model="supplier.street_number_pop" type="text" maxlength="20" placeholder="1104"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.street_number_hint') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.street_number_orient') }}</label>
              <input v-model="supplier.street_number_orient" type="text" maxlength="20" placeholder="36"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
          </div>

          <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mt-5 mb-2">{{ t('settings.opr_section') }}</h4>
          <p class="text-xs text-neutral-500 mb-3">{{ t('settings.opr_hint') }}</p>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.opr_jmeno') }}</label>
              <input v-model="supplier.opr_jmeno" type="text" maxlength="60"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.opr_prijmeni') }}</label>
              <input v-model="supplier.opr_prijmeni" type="text" maxlength="60"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.opr_postaveni') }}</label>
              <input v-model="supplier.opr_postaveni" type="text" maxlength="60" placeholder="jednatel"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>

          <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mt-5 mb-2">{{ t('settings.sest_section') }}</h4>
          <p class="text-xs text-neutral-500 mb-3">{{ t('settings.sest_hint') }}</p>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.sest_jmeno') }}</label>
              <input v-model="supplier.sest_jmeno" type="text" maxlength="100"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.sest_prijmeni') }}</label>
              <input v-model="supplier.sest_prijmeni" type="text" maxlength="100"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.sest_funkce') }}</label>
              <input v-model="supplier.sest_funkce" type="text" maxlength="80"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.sest_telefon') }}</label>
              <input v-model="supplier.sest_telefon" type="text" maxlength="40"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.sest_email') }}</label>
              <input v-model="supplier.sest_email" type="email" maxlength="120"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
        </div>

      </section>

      <!-- Pohoda XML export config (volitelné) — samostatný box -->
      <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.pohoda_section') }}</h2>
        <div>
          <h3 class="sr-only">{{ t('settings.pohoda_section') }}</h3>
          <p class="text-xs text-neutral-500 mb-3">{{ t('settings.pohoda_hint') }}</p>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_account_code') }}</label>
              <input v-model="supplier.pohoda_account_code" type="text" placeholder="KB" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_centre_code') }}</label>
              <input v-model="supplier.pohoda_centre_code" type="text" placeholder="STR1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_activity_code') }}</label>
              <input v-model="supplier.pohoda_activity_code" type="text" placeholder="ACT1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_contract_code') }}</label>
              <input v-model="supplier.pohoda_contract_code" type="text" placeholder="ZAK1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button @click="saveSupplier" class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
            {{ t('settings.save_supplier') }}
          </button>
        </div>
      </section>

      <!-- Email branding (M16) -->
      <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-center justify-between mb-1">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('settings.branding_title') }}</h2>
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input v-model="supplier.email_branding_enabled" type="checkbox" class="h-4 w-4 accent-primary-600" />
            <span class="text-sm text-neutral-700">{{ t('settings.branding_enabled') }}</span>
          </label>
        </div>
        <p class="text-xs text-neutral-500 mb-4">{{ t('settings.branding_subtitle') }}</p>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
          <!-- Form -->
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.branding_logo') }}</label>
              <p class="text-xs text-neutral-500 mb-2">{{ t('settings.branding_logo_hint') }}</p>
              <div class="flex items-center gap-3">
                <button
                  @click="pickLogo" type="button"
                  :disabled="logoUploading || !supplier.email_branding_enabled"
                  class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50 disabled:cursor-not-allowed">
                  {{ logoUploading ? t('common.loading') : (supplier.has_email_logo ? t('settings.branding_logo_replace') : t('settings.branding_logo_upload')) }}
                </button>
                <button
                  v-if="supplier.has_email_logo" @click="removeLogo" type="button"
                  class="cursor-pointer text-sm text-danger-600 hover:text-danger-700">
                  {{ t('common.remove') }}
                </button>
                <input ref="logoFileInput" @change="onLogoSelected" type="file" accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml" class="hidden" />
              </div>
              <label class="inline-flex items-center gap-2 mt-3 cursor-pointer">
                <input
                  v-model="supplier.pdf_logo_show_name" type="checkbox"
                  :disabled="!supplier.email_branding_enabled || !supplier.has_email_logo"
                  class="h-4 w-4 accent-primary-600 disabled:opacity-50" />
                <span class="text-sm text-neutral-700">{{ t('settings.branding_logo_show_name') }}</span>
              </label>
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.branding_logo_show_name_hint') }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.branding_accent_color') }}</label>
              <p class="text-xs text-neutral-500 mb-2">{{ t('settings.branding_accent_color_hint') }}</p>
              <div class="flex items-center gap-3">
                <input
                  v-model="supplier.email_accent_color" type="color"
                  :disabled="!supplier.email_branding_enabled"
                  class="h-10 w-14 cursor-pointer rounded border border-neutral-300 disabled:opacity-50" />
                <input
                  v-model="supplier.email_accent_color" type="text" placeholder="#3B2D83" pattern="^#[0-9A-Fa-f]{6}$"
                  :disabled="!supplier.email_branding_enabled"
                  class="h-10 w-32 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:opacity-50" />
                <button
                  @click="supplier.email_accent_color = '#3B2D83'" type="button"
                  :disabled="!supplier.email_branding_enabled"
                  class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed">
                  {{ t('settings.branding_accent_reset') }}
                </button>
              </div>
            </div>

            <p class="text-xs text-neutral-500">
              {{ t('settings.branding_save_hint') }}
            </p>

            <div class="pt-2">
              <button @click="() => saveBranding(false)" class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
                {{ t('settings.branding_save') }}
              </button>
            </div>
          </div>

          <!-- Preview -->
          <div>
            <div class="flex items-center justify-between mb-2">
              <label class="block text-sm font-medium text-neutral-700">{{ t('settings.branding_preview') }}</label>
              <div class="flex items-center gap-1 text-xs">
                <button @click="previewLocale = 'cs'" type="button"
                  :class="previewLocale === 'cs' ? 'text-primary-600 font-semibold' : 'text-neutral-500 hover:text-neutral-700'"
                  class="cursor-pointer px-2">CS</button>
                <span class="text-neutral-300">|</span>
                <button @click="previewLocale = 'en'" type="button"
                  :class="previewLocale === 'en' ? 'text-primary-600 font-semibold' : 'text-neutral-500 hover:text-neutral-700'"
                  class="cursor-pointer px-2">EN</button>
                <button @click="bumpPreview" type="button"
                  class="cursor-pointer ml-2 px-2 text-neutral-500 hover:text-neutral-700" :title="t('common.refresh')">↻</button>
              </div>
            </div>
            <iframe :srcdoc="previewHtml" sandbox="allow-same-origin" class="w-full h-[420px] border border-neutral-200 rounded-md bg-neutral-50" />
          </div>
        </div>
      </section>

      <!-- Currencies / Bank accounts -->
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('settings.currencies_banks') }}</h2>
        </header>
        <div class="overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.currency') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.account_th') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.account_cz') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.iban') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.bic') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('common.default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('settings.active') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in currencies" :key="c.id">
              <td class="px-3 py-2 font-mono">{{ c.code }} <span class="text-xs text-neutral-500">{{ c.symbol }}</span></td>
              <td class="px-3 py-2">{{ c.label }}</td>
              <td class="px-3 py-2 font-mono text-xs">
                {{ c.account_number }}<span v-if="c.bank_code"> / {{ c.bank_code }}</span>
              </td>
              <td class="px-3 py-2 font-mono text-xs">{{ c.iban || '—' }}</td>
              <td class="px-3 py-2 font-mono text-xs">{{ c.bic || '—' }}</td>
              <td class="px-3 py-2 text-center">
                <span v-if="c.is_default" class="text-primary-600">✓</span>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-3 py-2 text-center">
                <span v-if="c.is_active" class="text-success-600">✓</span>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-3 py-2 text-right">
                <button @click="startEditCurrency(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs">{{ t('common.edit') }}</button>
                <button v-if="(c.invoices_count ?? 0) === 0" @click="removeCurrency(c)"
                  class="cursor-pointer text-danger-600 hover:text-danger-700 text-xs ml-2">{{ t('common.delete') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>
        <div class="px-5 py-3 border-t border-neutral-200 bg-neutral-50 text-xs text-neutral-600 flex flex-wrap gap-3 items-center">
          <span>{{ t('settings.add_another_account') }}</span>
          <button v-for="code in [...new Set(currencies.map(c => c.code))]" :key="code"
            @click="addCurrencyAccount(code)"
            class="cursor-pointer px-2 h-7 border border-neutral-300 rounded text-xs hover:bg-surface">
            + {{ code }}
          </button>
        </div>
      </section>
    </div>

    <!-- Modal — currency edit -->
    <div v-if="editingCurrency" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ t('settings.edit_currency_label_full', { label: editingCurrencyLabel }) }}</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.account_label_form') }}</label>
            <input v-model="currencyDraft.label" type="text" placeholder="CZK — Fio Bank"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.currency_account_cz') }}</label>
            <input v-model="currencyDraft.account_number" type="text" placeholder="1000000005"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.currency_bank_code') }}</label>
            <input v-model="currencyDraft.bank_code" type="text" placeholder="0100"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.currency_bank_name') }}</label>
            <input v-model="currencyDraft.bank_name" type="text" placeholder="KB"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.iban') }}</label>
            <input v-model="currencyDraft.iban" type="text" placeholder="CZ65 0100 0000 0019 2000 1453"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.currency_bic') }}</label>
            <input v-model="currencyDraft.bic" type="text" placeholder="KOMBCZPP"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="currencyDraft.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('settings.currency_active_hint') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="currencyDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('codebooks.is_default_account_hint') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="editingCurrency = null" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="saveCurrency" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

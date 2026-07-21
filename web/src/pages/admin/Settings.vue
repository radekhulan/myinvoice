<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type Supplier, type SelfCopyType, type SelfCopyMode } from '@/api/settings'
import { adminApi, type SampleDataStatus } from '@/api/admin'
import { clientsApi } from '@/api/clients'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { renderVarsymbolTemplate, hasCounterPlaceholder } from '@/utils/varsymbol'
import BrandingProfilesSettings from '@/components/settings/BrandingProfilesSettings.vue'

const { t } = useI18n()
const toast = useToast()
const supplierStore = useSupplierStore()

// Po uložení propsat změny do supplier store (brief z /me) — jinak editor faktur
// čte stale is_vat_payer/defaulty až do hard refreshe (issue #94).
function syncSupplierStore(s: Supplier) {
  supplierStore.patchSupplier(s.id, {
    company_name: s.company_name,
    ic: s.ic,
    is_vat_payer: s.is_vat_payer,
    is_identified: s.is_identified ?? false,
    oss_enabled: s.oss_enabled ?? false,
    taxpayer_type: s.taxpayer_type ?? null,
    default_payment_due_days: s.default_payment_due_days,
    default_payment_due_unit: s.default_payment_due_unit,
    default_prices_include_vat: s.default_prices_include_vat,
    auto_send_reminders: s.auto_send_reminders,
    payment_thanks_enabled: s.payment_thanks_enabled,
    payment_thanks_default_checked: s.payment_thanks_default_checked,
  })
}

const supplier = ref<Supplier | null>(null)
const loading = ref(true)

// Práh dní pro první upomínku — preset (3 / týden / měsíc) + „vlastní". Stejný „sticky custom"
// idiom jako dueSelectValue níže: flag drží „vlastní" i když hodnota náhodou odpovídá presetu,
// jinak by getter spadl zpět na preset a číselný input by se nikdy neukázal.
const REMINDER_DAYS_PRESETS = [3, 7, 30]
const reminderCustom = ref(false)
const reminderDaysSelect = computed<number | 'custom'>({
  get() {
    if (reminderCustom.value) return 'custom'
    const d = supplier.value?.reminder_days_after_due ?? 3
    return REMINDER_DAYS_PRESETS.includes(d) ? d : 'custom'
  },
  set(v) {
    reminderCustom.value = (v === 'custom')
    if (v !== 'custom' && supplier.value) supplier.value.reminder_days_after_due = v
  },
})

// ARES → spisová značka (commercial_register) podle IČ
const crLoading = ref(false)
async function loadCommercialRegister() {
  const ic = (supplier.value?.ic || '').replace(/\D/g, '')
  if (!/^\d{8}$/.test(ic)) { toast.error(t('supplier.ares_invalid_ic')); return }
  crLoading.value = true
  try {
    const r = await clientsApi.lookupAres(ic)
    if (r.found && r.data?.commercial_register && supplier.value) {
      supplier.value.commercial_register = r.data.commercial_register
      toast.success(t('settings.commercial_register_loaded'))
    } else if (r.found && r.data?.taxpayer_type === 'fo') {
      // OSVČ (fyzická osoba) není v obchodním rejstříku → spisová značka neexistuje.
      // Není to chyba (issue #76), jen neutrální info.
      toast.info(t('settings.commercial_register_none_fo'))
    } else {
      toast.error(t('settings.commercial_register_not_found'))
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('supplier.ares_failed'))
  } finally {
    crLoading.value = false
  }
}

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
const purchasePreview       = computed(() => validateAndPreview(supplier.value?.purchase_invoice_number_format ?? null).preview)
const purchaseFormatError   = computed(() => validateAndPreview(supplier.value?.purchase_invoice_number_format ?? null).error)

// Kopie odchozích e-mailů dodavateli (migrace 0102) — UI stav 'inherit' znamená
// „klíč v self_copy chybí" = živý fallback na cfg flagy (vzor číslování faktur).
// Explicitní volba klíč zapíše; zpět na 'inherit' ho smaže. Prázdný objekt → null.
function selfCopyComputed(ct: SelfCopyType) {
  return computed<SelfCopyMode | 'inherit'>({
    get: () => supplier.value?.self_copy?.[ct] ?? 'inherit',
    set: (v) => {
      if (!supplier.value) return
      const sc = { ...(supplier.value.self_copy ?? {}) }
      if (v === 'inherit') delete sc[ct]
      else sc[ct] = v
      supplier.value.self_copy = Object.keys(sc).length ? sc : null
    },
  })
}
const selfCopyDocuments = selfCopyComputed('documents')
const selfCopyReminders = selfCopyComputed('reminders')
const selfCopyApprovals = selfCopyComputed('approvals')

/** Efektivní cfg hodnota pro volbu „dle konfigurace" — u schvalování může mít
 *  žádost a upomínka v cfg různé flagy, pak ukážeme obě. */
function selfCopyFallbackLabel(ct: SelfCopyType): string {
  const fb = supplier.value?.cfg_self_copy_fallback
  if (!fb) return ''
  const lbl = (m: SelfCopyMode) => m === 'off' ? t('settings.self_copy.mode_off') : m.toUpperCase()
  if (ct === 'approvals' && fb.approvals !== fb.approval_reminders) {
    return t('settings.self_copy.inherit_split', { request: lbl(fb.approvals), reminder: lbl(fb.approval_reminders) })
  }
  return lbl(fb[ct])
}

async function load() {
  loading.value = true
  try {
    supplier.value = await settingsApi.getSupplier()
    bumpPreview()
  } finally { loading.value = false }
  loadSampleStatus()
}

onMounted(load)

// ── Ukázková (sample) data — sekce se zobrazí jen když nějaká evidovaná existují (issue #162) ──
const sampleStatus = ref<SampleDataStatus | null>(null)
const showSampleConfirm = ref(false)
const sampleDeleting = ref(false)

async function loadSampleStatus() {
  try {
    sampleStatus.value = await adminApi.sampleDataStatus()
  } catch {
    sampleStatus.value = null  // 403 (ne-admin) / chyba → sekci nezobrazuj
  }
}

const sampleSummaryLine = computed(() => {
  const c = sampleStatus.value?.counts ?? {}
  const parts: string[] = []
  const push = (n: number, key: string) => { if (n > 0) parts.push(`${n} ${t(key)}`) }
  push((c.client ?? 0) + (c.vendor ?? 0), 'settings.sample_data.unit_clients')
  push((c.invoice ?? 0) + (c.credit_note ?? 0), 'settings.sample_data.unit_invoices')
  push(c.purchase_invoice ?? 0, 'settings.sample_data.unit_purchase')
  push(c.project ?? 0, 'settings.sample_data.unit_projects')
  return parts.join(', ')
})

async function removeSampleData() {
  sampleDeleting.value = true
  try {
    await adminApi.deleteSampleData()
    toast.success(t('settings.sample_data.removed'))
    showSampleConfirm.value = false
    await loadSampleStatus()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    sampleDeleting.value = false
  }
}

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
      is_identified: supplier.value.is_identified ?? false,
      email: supplier.value.email,
      phone: supplier.value.phone,
      web: supplier.value.web,
      tagline: supplier.value.tagline,
      commercial_register: supplier.value.commercial_register,
      default_payment_due_days: supplier.value.default_payment_due_days,
      default_payment_due_unit: supplier.value.default_payment_due_unit,
      default_prices_include_vat: supplier.value.default_prices_include_vat,
      default_hourly_rate: supplier.value.default_hourly_rate,
      auto_send_reminders: supplier.value.auto_send_reminders,
      reminder_days_after_due: supplier.value.reminder_days_after_due,
      payment_thanks_enabled: supplier.value.payment_thanks_enabled,
      payment_thanks_auto_send: supplier.value.payment_thanks_auto_send,
      payment_thanks_default_checked: supplier.value.payment_thanks_default_checked,
      payment_thanks_attach_paid_pdf: supplier.value.payment_thanks_attach_paid_pdf,
      self_copy: supplier.value.self_copy ?? null,
      auto_generate_recurring: supplier.value.auto_generate_recurring,
      embed_isdoc: supplier.value.embed_isdoc,
      pohoda_account_code: supplier.value.pohoda_account_code,
      pohoda_centre_code: supplier.value.pohoda_centre_code,
      pohoda_activity_code: supplier.value.pohoda_activity_code,
      pohoda_contract_code: supplier.value.pohoda_contract_code,
      invoice_number_format: supplier.value.invoice_number_format,
      proforma_number_format: supplier.value.proforma_number_format,
      credit_note_number_format: supplier.value.credit_note_number_format,
      purchase_invoice_number_format: supplier.value.purchase_invoice_number_format,
      invoice_number_period: supplier.value.invoice_number_period,
      email_branding_enabled: supplier.value.email_branding_enabled,
      email_accent_color: supplier.value.email_accent_color,
      branding_profiles_enabled: supplier.value.branding_profiles_enabled,
      // Tax settings (EPO výkazy DPH/KH)
      taxpayer_type: (supplier.value as any).taxpayer_type ?? null,
      vat_period: (supplier.value as any).vat_period ?? null,
      flat_tax_band: (supplier.value as any).flat_tax_band ?? 'none',
      oss_enabled: (supplier.value as any).oss_enabled ?? false,
      oss_valid_from: (supplier.value as any).oss_valid_from ?? null,
      oss_valid_to: (supplier.value as any).oss_valid_to ?? null,
      oss_identification_country: (supplier.value as any).oss_identification_country ?? null,
      oss_return_currency: (supplier.value as any).oss_return_currency ?? 'EUR',
      financial_office_code: (supplier.value as any).financial_office_code ?? null,
      workplace_code: (supplier.value as any).workplace_code ?? null,
      cz_nace_code: (supplier.value as any).cz_nace_code ?? null,
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
    syncSupplierStore(supplier.value)
    toast.success(t('common.saved'))
    bumpPreview()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

const previewLocale = ref<'cs' | 'en'>('cs')
const previewHtml = ref('')
const logoFileInput = ref<HTMLInputElement | null>(null)
const logoUploading = ref(false)

async function bumpPreview() {
  if (!supplier.value || supplier.value.branding_profiles_enabled) return
  try {
    previewHtml.value = await settingsApi.emailPreviewHtml(previewLocale.value)
  } catch (e: any) {
    previewHtml.value = `<pre style="color:red">${e?.message || 'Preview failed'}</pre>`
  }
}

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
    supplier.value = { ...supplier.value, ...updated }
    syncSupplierStore(supplier.value)
    if (!silent) toast.success(t('common.saved'))
    bumpPreview()
  } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
}

watch(previewLocale, bumpPreview)

function pickLogo() { logoFileInput.value?.click() }
async function onLogoSelected(ev: Event) {
  const file = (ev.target as HTMLInputElement).files?.[0]
  if (!file || !supplier.value) return
  if (file.size > 1_048_576) {
    toast.error(t('settings.branding_logo_too_large'))
    if (logoFileInput.value) logoFileInput.value.value = ''
    return
  }
  logoUploading.value = true
  try {
    const result = await settingsApi.uploadEmailLogo(file)
    supplier.value.logo_path = result.logo_path
    supplier.value.has_email_logo = true
    toast.success(t('settings.branding_logo_uploaded'))
    bumpPreview()
  } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
  finally {
    logoUploading.value = false
    if (logoFileInput.value) logoFileInput.value.value = ''
  }
}

async function removeLogo() {
  if (!supplier.value || !window.confirm(t('settings.branding_logo_remove_confirm'))) return
  try {
    await settingsApi.deleteEmailLogo()
    supplier.value.logo_path = null
    supplier.value.has_email_logo = false
    toast.success(t('settings.branding_logo_removed'))
    bumpPreview()
  } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
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
                @change="supplier.is_vat_payer && (((supplier as any).flat_tax_band = 'none'), (supplier.is_identified = false))" />
              {{ t('settings.is_vat_payer') }}
            </label>
            <!-- Identifikovaná osoba (§ 6g–6l ZDPH, #94) — jen pro neplátce; plátce ji vypne. -->
            <label v-if="!supplier.is_vat_payer" class="flex items-center gap-2 text-sm mt-2">
              <input v-model="supplier.is_identified" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('settings.is_identified') }}
            </label>
            <p v-if="!supplier.is_vat_payer && supplier.is_identified" class="text-xs text-neutral-500 mt-1 ml-6">
              {{ t('settings.is_identified_hint') }}
            </p>
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
            <div class="flex items-center justify-between mb-1 gap-2">
              <label class="block text-sm font-medium text-neutral-700">{{ t('settings.commercial_register') }}</label>
              <button type="button" @click="loadCommercialRegister" :disabled="crLoading || !supplier.ic"
                class="cursor-pointer h-7 px-2.5 text-xs bg-surface border border-primary-300 text-primary-700 rounded-md hover:bg-primary-50 disabled:opacity-50 shrink-0">
                {{ crLoading ? '…' : t('settings.commercial_register_load_ares') }}
              </button>
            </div>
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
          <div v-if="supplier.is_vat_payer" class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="supplier.default_prices_include_vat" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span class="font-medium">{{ t('settings.default_prices_include_vat') }}</span>
            </label>
            <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.default_prices_include_vat_hint') }}</p>
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
          <div class="md:col-span-2" v-if="supplier.auto_send_reminders">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.reminder_days_after_due') }}</label>
            <div class="flex items-center gap-2 flex-wrap">
              <select v-model="reminderDaysSelect" class="h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="3">{{ t('settings.reminder_days_preset.d3') }}</option>
                <option :value="7">{{ t('settings.reminder_days_preset.week') }}</option>
                <option :value="30">{{ t('settings.reminder_days_preset.month') }}</option>
                <option value="custom">{{ t('settings.reminder_days_preset.custom') }}</option>
              </select>
              <template v-if="reminderDaysSelect === 'custom'">
                <input v-model.number="supplier.reminder_days_after_due" type="number" min="1" max="365"
                       class="w-24 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                <span class="text-sm text-neutral-500">{{ t('settings.reminder_days_unit') }}</span>
              </template>
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ t('settings.reminder_days_after_due_hint') }}</p>
          </div>
          <div class="md:col-span-2 border-t border-neutral-200 pt-3">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="supplier.payment_thanks_enabled" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span class="font-medium">{{ t('settings.payment_thanks_enabled') }}</span>
            </label>
            <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.payment_thanks_enabled_hint') }}</p>
            <div v-if="supplier.payment_thanks_enabled" class="ml-6 mt-2 space-y-2">
              <label class="flex items-center gap-2 text-sm">
                <input v-model="supplier.payment_thanks_auto_send" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                {{ t('settings.payment_thanks_auto_send') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model="supplier.payment_thanks_default_checked" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                {{ t('settings.payment_thanks_default_checked') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model="supplier.payment_thanks_attach_paid_pdf" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                {{ t('settings.payment_thanks_attach_paid_pdf') }}
              </label>
            </div>
          </div>
          <div class="md:col-span-2 border-t border-neutral-200 pt-3">
            <p class="text-sm font-medium text-neutral-700">{{ t('settings.self_copy.title') }}</p>
            <p class="text-xs text-neutral-500 mt-1">{{ t('settings.self_copy.hint', { email: supplier.email }) }}</p>
            <div class="mt-2 grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.self_copy.type_documents') }}</label>
                <select v-model="selfCopyDocuments" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                  <option value="inherit">{{ t('settings.self_copy.inherit', { value: selfCopyFallbackLabel('documents') }) }}</option>
                  <option value="off">{{ t('settings.self_copy.mode_off') }}</option>
                  <option value="cc">{{ t('settings.self_copy.mode_cc') }}</option>
                  <option value="bcc">{{ t('settings.self_copy.mode_bcc') }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.self_copy.type_reminders') }}</label>
                <select v-model="selfCopyReminders" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                  <option value="inherit">{{ t('settings.self_copy.inherit', { value: selfCopyFallbackLabel('reminders') }) }}</option>
                  <option value="off">{{ t('settings.self_copy.mode_off') }}</option>
                  <option value="cc">{{ t('settings.self_copy.mode_cc') }}</option>
                  <option value="bcc">{{ t('settings.self_copy.mode_bcc') }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.self_copy.type_approvals') }}</label>
                <select v-model="selfCopyApprovals" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                  <option value="inherit">{{ t('settings.self_copy.inherit', { value: selfCopyFallbackLabel('approvals') }) }}</option>
                  <option value="off">{{ t('settings.self_copy.mode_off') }}</option>
                  <option value="cc">{{ t('settings.self_copy.mode_cc') }}</option>
                  <option value="bcc">{{ t('settings.self_copy.mode_bcc') }}</option>
                </select>
              </div>
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ t('settings.self_copy.approvals_note') }}</p>
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

      <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <label class="flex items-center gap-2 text-sm">
          <input v-model="supplier.branding_profiles_enabled" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('settings.branding_profiles.module_enabled') }}
        </label>
        <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.branding_profiles.module_enabled_hint') }}</p>
      </section>

      <BrandingProfilesSettings v-if="supplier.branding_profiles_enabled" @changed="load" />

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
            <li><code class="bg-neutral-100 px-1 rounded">{PP}</code> &mdash; {{ t('settings.numbering_hint_pp') }} <span class="text-neutral-400">(PF/PN/KU/KN/NU/NN)</span></li>
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
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.purchase_invoice_number_format') }}</label>
              <input v-model="supplier.purchase_invoice_number_format" type="text"
                :placeholder="supplier.cfg_varsymbol_fallback?.purchase || '{PP}{YY}{MM}{CCC}'" maxlength="60"
                class="w-full h-9 px-3 border rounded-md text-sm font-mono"
                :class="purchaseFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
              <p v-if="purchaseFormatError" class="text-xs text-danger-500 mt-1">{{ purchaseFormatError }}</p>
              <p v-else-if="purchasePreview" class="text-xs text-success-600 mt-1">
                {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ purchasePreview }}</code>
              </p>
              <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
              <p class="text-xs text-neutral-400 mt-1">{{ t('settings.purchase_invoice_number_format_hint') }}</p>
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
            <div class="md:col-span-2 border border-neutral-200 rounded-md p-3">
              <label class="flex items-center gap-2 text-sm">
                <input v-model="(supplier as any).oss_enabled" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                <span>{{ t('settings.oss_enabled') }}</span>
              </label>
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.oss_hint') }}</p>
              <div v-if="(supplier as any).oss_enabled" class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-3">
                <div>
                  <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.oss_identification_country') }}</label>
                  <input v-model="(supplier as any).oss_identification_country" type="text" maxlength="2" placeholder="CZ"
                    class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.oss_return_currency') }}</label>
                  <input v-model="(supplier as any).oss_return_currency" type="text" maxlength="3" placeholder="EUR"
                    class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.oss_valid_from') }}</label>
                  <input v-model="(supplier as any).oss_valid_from" type="date"
                    class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.oss_valid_to') }}</label>
                  <input v-model="(supplier as any).oss_valid_to" type="date"
                    class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                </div>
              </div>
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

      <section v-if="!supplier.branding_profiles_enabled" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-center justify-between mb-1">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('settings.branding_title') }}</h2>
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input v-model="supplier.email_branding_enabled" type="checkbox" class="h-4 w-4 accent-primary-600" />
            <span class="text-sm text-neutral-700">{{ t('settings.branding_enabled') }}</span>
          </label>
        </div>
        <p class="text-xs text-neutral-500 mb-4">{{ t('settings.branding_subtitle') }}</p>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.branding_logo') }}</label>
              <p class="text-xs text-neutral-500 mb-2">{{ t('settings.branding_logo_hint') }}</p>
              <div class="flex items-center gap-3">
                <button type="button" :disabled="logoUploading || !supplier.email_branding_enabled"
                  class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50" @click="pickLogo">
                  {{ logoUploading ? t('common.loading') : (supplier.has_email_logo ? t('settings.branding_logo_replace') : t('settings.branding_logo_upload')) }}
                </button>
                <button v-if="supplier.has_email_logo" type="button" class="text-sm text-danger-600" @click="removeLogo">{{ t('common.remove') }}</button>
                <input ref="logoFileInput" type="file" accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml" class="hidden" @change="onLogoSelected" />
              </div>
              <label class="inline-flex items-center gap-2 mt-3 cursor-pointer">
                <input v-model="supplier.pdf_logo_show_name" type="checkbox"
                  :disabled="!supplier.email_branding_enabled || !supplier.has_email_logo" class="h-4 w-4 accent-primary-600 disabled:opacity-50" />
                <span class="text-sm text-neutral-700">{{ t('settings.branding_logo_show_name') }}</span>
              </label>
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.branding_logo_show_name_hint') }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.branding_accent_color') }}</label>
              <p class="text-xs text-neutral-500 mb-2">{{ t('settings.branding_accent_color_hint') }}</p>
              <div class="flex items-center gap-3">
                <input v-model="supplier.email_accent_color" type="color" :disabled="!supplier.email_branding_enabled"
                  class="h-10 w-14 cursor-pointer rounded border border-neutral-300 disabled:opacity-50" />
                <input v-model="supplier.email_accent_color" type="text" placeholder="#3B2D83" pattern="^#[0-9A-Fa-f]{6}$"
                  :disabled="!supplier.email_branding_enabled" class="h-10 w-32 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:opacity-50" />
                <button type="button" :disabled="!supplier.email_branding_enabled" class="text-xs text-neutral-500 disabled:opacity-50"
                  @click="supplier.email_accent_color = '#3B2D83'">{{ t('settings.branding_accent_reset') }}</button>
              </div>
            </div>
            <button class="px-4 h-10 bg-primary-600 text-white text-sm font-medium rounded-md" @click="saveBranding(false)">{{ t('settings.branding_save') }}</button>
          </div>

          <div>
            <div class="flex items-center justify-between mb-2">
              <label class="text-sm font-medium text-neutral-700">{{ t('settings.branding_preview') }}</label>
              <div class="flex items-center gap-2 text-xs">
                <button :class="previewLocale === 'cs' ? 'font-semibold text-primary-600' : 'text-neutral-500'" @click="previewLocale = 'cs'">CS</button>
                <button :class="previewLocale === 'en' ? 'font-semibold text-primary-600' : 'text-neutral-500'" @click="previewLocale = 'en'">EN</button>
                <button class="text-neutral-500" :title="t('common.refresh')" @click="bumpPreview">↻</button>
              </div>
            </div>
            <iframe :srcdoc="previewHtml" sandbox="allow-same-origin" class="w-full h-[420px] border border-neutral-200 rounded-md bg-neutral-50" />
          </div>
        </div>
      </section>

      <!-- Ukázková data — jen pokud nějaká evidovaná existují (issue #162) -->
      <section v-if="sampleStatus?.has" class="bg-surface border border-warning-500/40 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-warning-600 mb-2">{{ t('settings.sample_data.title') }}</h2>
        <p class="text-sm text-neutral-600 mb-1">{{ t('settings.sample_data.description') }}</p>
        <p v-if="sampleSummaryLine" class="text-xs text-neutral-500 mb-4">{{ t('settings.sample_data.contains') }}: {{ sampleSummaryLine }}</p>
        <button type="button" @click="showSampleConfirm = true"
          class="cursor-pointer h-10 px-4 text-sm font-medium rounded-md border border-danger-500/50 text-danger-600 hover:bg-danger-50 inline-flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
          {{ t('settings.sample_data.remove_button') }}
        </button>
      </section>
    </div>

    <!-- Potvrzení odebrání ukázkových dat -->
    <div v-if="showSampleConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-md p-6">
        <div class="flex items-start gap-3 mb-4">
          <div class="w-10 h-10 rounded-full bg-danger-50 flex items-center justify-center shrink-0">
            <svg class="w-5 h-5 text-danger-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.34 16a2 2 0 001.73 3z"/></svg>
          </div>
          <div>
            <h3 class="text-base font-semibold text-neutral-900">{{ t('settings.sample_data.confirm_title') }}</h3>
            <p class="text-sm text-neutral-600 mt-1">{{ t('settings.sample_data.confirm_text') }}</p>
            <p v-if="sampleSummaryLine" class="text-xs text-neutral-500 mt-2">{{ sampleSummaryLine }}</p>
          </div>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="showSampleConfirm = false" :disabled="sampleDeleting"
            class="cursor-pointer h-10 px-4 text-sm rounded-md border border-neutral-300 text-neutral-700 hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button type="button" @click="removeSampleData" :disabled="sampleDeleting"
            class="cursor-pointer h-10 px-4 text-sm font-medium rounded-md bg-danger-600 hover:bg-danger-700 disabled:opacity-60 text-white">
            {{ sampleDeleting ? '…' : t('settings.sample_data.confirm_button') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

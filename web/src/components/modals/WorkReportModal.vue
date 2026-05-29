<script setup lang="ts">
/**
 * Modální editor výkazu víceprací (work report) pro DRAFT fakturu se zakázkou.
 *
 * Použití:
 *   <WorkReportModal v-model="open" :invoice-id="id" @saved="reload" />
 *
 * Flow:
 *   1. Při open načte invoice + existing work_report (pokud existuje)
 *   2. Editor řádků (description, work_date, hours, rate)
 *   3. Save:
 *      a. PUT /api/invoices/{id}/work-report (saveWorkReport)
 *      b. PUT /api/invoices/{id} s úpravou items — přidá/aktualizuje jednu položku
 *         se sumou výkazu (description = title výkazu, qty = 1, cena = total)
 *   4. emit('saved') → parent reload
 *
 * Sdílí logiku s InvoiceEditor.vue (oddělené pro UX: list/detail tlačítko otevře jen WR
 * bez nutnosti přejít na full editor).
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { invoicesApi, type WorkReportItem } from '@/api/invoices'
import { codebooksApi, type Unit } from '@/api/codebooks'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const toast = useToast()

const props = defineProps<{
  modelValue: boolean
  invoiceId: number
}>()
const emit = defineEmits<{
  (e: 'update:modelValue', open: boolean): void
  (e: 'saved'): void
}>()

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const wrTitle = ref('')
const wrItems = ref<WorkReportItem[]>([])
const projectId = ref<number | null>(null)
const defaultRate = ref(1500)
const defaultVatRateId = ref<number | null>(null)
const currency = ref('CZK')
const units = ref<Unit[]>([])

const itemsValid = computed(() =>
  wrItems.value.filter(i => (i.description || '').trim() !== '' && Number(i.hours) > 0)
)
const totalHours = computed(() => itemsValid.value.reduce((s, i) => s + Number(i.hours || 0), 0))
const totalAmount = computed(() =>
  itemsValid.value.reduce((s, i) => s + Number(i.hours || 0) * Number(i.rate || 0), 0)
)
const canSave = computed(() => wrTitle.value.trim() !== '' && itemsValid.value.length > 0 && !saving.value)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [inv, wr, unitsList] = await Promise.all([
      invoicesApi.get(props.invoiceId),
      invoicesApi.getWorkReport(props.invoiceId).catch(() => null),
      codebooksApi.units().catch(() => [] as Unit[]),
    ])
    projectId.value = inv.project_id ?? null
    currency.value = inv.currency || 'CZK'
    units.value = unitsList
    // Default VAT pro nový invoice item — vezmi z prvního existujícího řádku
    if (inv.items && inv.items.length > 0) {
      defaultVatRateId.value = inv.items[0].vat_rate_id ?? null
    }
    if (wr) {
      wrTitle.value = wr.title
      wrItems.value = wr.items.map(i => ({ ...i }))
    } else {
      // Pre-fill: title odvozený z měsíce DUZP/issue, jedna prázdná položka
      const date = (inv.tax_date || inv.issue_date || '').slice(0, 7) // YYYY-MM
      wrTitle.value = date ? t('invoice.wr_title_with_date', { date }) : t('invoice.work_report')
      wrItems.value = []
      addItem()
    }
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}

function addItem() {
  wrItems.value.push({
    description: '',
    work_date: null,
    hours: 1,
    rate: defaultRate.value,
    order_index: wrItems.value.length,
  })
}

function removeItem(idx: number) {
  wrItems.value.splice(idx, 1)
}

function moveItem(idx: number, dir: -1 | 1) {
  const newIdx = idx + dir
  if (newIdx < 0 || newIdx >= wrItems.value.length) return
  const [item] = wrItems.value.splice(idx, 1)
  wrItems.value.splice(newIdx, 0, item)
}

function close() {
  emit('update:modelValue', false)
}

async function save() {
  if (!canSave.value) return
  saving.value = true
  error.value = ''
  try {
    // 1. Uložit work report
    await invoicesApi.saveWorkReport(props.invoiceId, {
      project_id: projectId.value,
      title: wrTitle.value.trim(),
      items: itemsValid.value.map((it, idx) => ({
        description: it.description,
        work_date: it.work_date || null,
        hours: Number(it.hours),
        rate: Number(it.rate),
        order_index: idx,
      })),
    })

    // 2. Sync položku v faktuře — najít existující se shodným popisem, nebo přidat novou.
    //    Update kompletního invoice payloadu přes PUT /api/invoices/{id}.
    const inv = await invoicesApi.get(props.invoiceId)
    // Slevové položky (item_kind='discount') jsou generované z discount_percent —
    // do payloadu nepatří (backend je stejně ignoruje), jinak by se zdvojily / zamrzly.
    inv.items = inv.items.filter(it => it.item_kind !== 'discount')
    const desc = wrTitle.value.trim() || t('invoice.work_report')
    const unit = units.value.find(u => u.code === 'ks')?.code || 'ks'
    const existingIdx = inv.items.findIndex(it => (it.description || '').trim() === desc)
    const emptyIdx = existingIdx < 0
      ? inv.items.findIndex(it => (it.description || '').trim() === '')
      : -1
    const targetIdx = existingIdx >= 0 ? existingIdx : emptyIdx

    if (targetIdx >= 0) {
      inv.items[targetIdx].description = desc
      inv.items[targetIdx].quantity = 1
      inv.items[targetIdx].unit = unit
      inv.items[targetIdx].unit_price_without_vat = totalAmount.value
    } else {
      inv.items.push({
        description: desc,
        quantity: 1,
        unit,
        unit_price_without_vat: totalAmount.value,
        vat_rate_id: defaultVatRateId.value ?? (inv.items[0]?.vat_rate_id ?? null),
        order_index: inv.items.length,
      } as any)
    }

    await invoicesApi.update(props.invoiceId, {
      invoice_type: inv.invoice_type,
      client_id: inv.client_id,
      project_id: inv.project_id,
      issue_date: inv.issue_date,
      tax_date: inv.tax_date,
      due_date: inv.due_date,
      currency_id: inv.currency_id,
      reverse_charge: !!inv.reverse_charge,
      language: inv.language,
      varsymbol: inv.varsymbol,
      payment_method: inv.payment_method,
      note_above_items: inv.note_above_items,
      note_below_items: inv.note_below_items,
      discount_percent: inv.discount_percent ?? 0,
      items: inv.items.map((it, idx) => ({
        description: it.description,
        quantity: it.quantity,
        unit: it.unit,
        unit_price_without_vat: it.unit_price_without_vat,
        vat_rate_id: it.vat_rate_id,
        order_index: idx,
      })) as any,
    } as any)

    toast.success(t('invoice.wr_saved_and_synced'))
    emit('saved')
    close()
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('invoice.wr_save_failed'))
  } finally {
    saving.value = false
  }
}

// Reload pokaždé, když se modal otevře (různé invoiceId / nový WR po smazání).
watch(() => props.modelValue, (open) => {
  if (open && props.invoiceId > 0) load()
})
onMounted(() => {
  if (props.modelValue && props.invoiceId > 0) load()
})
</script>

<template>
  <div v-if="modelValue" class="fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto">
    <div class="bg-surface rounded-xl shadow-lg max-w-4xl w-full my-4">
      <header class="px-5 py-4 border-b border-neutral-200 flex items-baseline justify-between gap-3">
        <h3 class="text-lg font-semibold">{{ t('invoice.work_report') }}</h3>
        <button @click="close" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-2xl leading-none">&times;</button>
      </header>

      <div v-if="loading" class="p-8 text-center text-neutral-500">{{ t('common.loading') }}</div>

      <div v-else class="p-5 space-y-4">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.wr_title') }} *</label>
          <input v-model="wrTitle" type="text" maxlength="100" required
                 class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-2 py-2 w-12"></th>
                <th class="px-3 py-2 text-left font-medium">{{ t('invoice.wr_description') }}</th>
                <th class="px-3 py-2 text-left font-medium w-28">{{ t('invoice.wr_date') }}</th>
                <th class="px-3 py-2 text-right font-medium w-24">{{ t('invoice.wr_hours') }}</th>
                <th class="px-3 py-2 text-right font-medium w-28">{{ t('invoice.wr_rate') }}</th>
                <th class="px-3 py-2 text-right font-medium w-28">{{ t('invoice.totals.total') }}</th>
                <th class="px-2 py-2 w-10"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(it, i) in wrItems" :key="i">
                <td class="px-2 py-2 text-center text-xs text-neutral-400">
                  <button type="button" @click="moveItem(i, -1)" :disabled="i === 0"
                          :title="t('invoice.wr_move_up')"
                          class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▲</button>
                  <button type="button" @click="moveItem(i, 1)" :disabled="i === wrItems.length - 1"
                          :title="t('invoice.wr_move_down')"
                          class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▼</button>
                </td>
                <td class="px-3 py-1.5">
                  <input v-model="it.description" type="text" maxlength="500"
                         class="w-full h-9 px-2 border border-neutral-300 rounded text-sm" />
                </td>
                <td class="px-3 py-1.5">
                  <input v-model="it.work_date" type="date"
                         class="w-full h-9 px-2 border border-neutral-300 rounded text-sm" />
                </td>
                <td class="px-3 py-1.5">
                  <input v-model.number="it.hours" type="number" step="0.25" min="0"
                         class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                </td>
                <td class="px-3 py-1.5">
                  <input v-model.number="it.rate" type="number" step="1" min="0"
                         class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                </td>
                <td class="px-3 py-1.5 text-right font-mono text-neutral-700">
                  {{ ((Number(it.hours)||0) * (Number(it.rate)||0)).toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                </td>
                <td class="px-2 py-1.5 text-center">
                  <button type="button" @click="removeItem(i)" :title="t('common.delete')"
                          class="cursor-pointer text-danger-500 hover:text-danger-600 text-lg leading-none">&times;</button>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 font-semibold">
              <tr>
                <td colspan="3" class="p-2">
                  <button type="button" @click="addItem"
                          class="cursor-pointer px-3 h-8 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    {{ t('invoice.wr_add_item') }}
                  </button>
                </td>
                <td v-if="wrItems.length > 0" class="px-3 py-2 text-right font-mono">
                  <span class="text-neutral-400 font-normal mr-2">Σ</span>{{ totalHours.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} h
                </td>
                <td v-else></td>
                <td></td>
                <td v-if="wrItems.length > 0" class="px-3 py-2 text-right font-mono whitespace-nowrap" colspan="2">
                  {{ totalAmount.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} {{ currency }}
                </td>
                <td v-else colspan="2"></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </div>

        <p class="text-xs text-neutral-500">
          ℹ {{ t('invoice.wr_sync_note') }}
        </p>
      </div>

      <footer class="px-5 py-4 border-t border-neutral-200 flex items-center justify-between">
        <button @click="close"
                class="cursor-pointer h-10 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">
          {{ t('common.cancel') }}
        </button>
        <button @click="save" :disabled="!canSave"
                class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ saving ? t('common.saving') : t('invoice.wr_save_and_sync') }}
        </button>
      </footer>
    </div>
  </div>
</template>

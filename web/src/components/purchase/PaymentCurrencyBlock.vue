<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Currency } from '@/api/codebooks'

const props = defineProps<{
  invoiceCurrencyId: number | null
  invoiceCurrency: string
  totalWithVat: number
  currencies: Currency[]
  /** Initial values (z faktury při editaci) */
  paymentCurrencyId: number | null
  paymentExchangeRate: number | null
  paidAmountPaymentCcy: number | null
  paidAmountInvoiceCcy: number | null
  exchangeDiffBase: number | null
  invoiceExchangeRate: number | null
}>()

const emit = defineEmits<{
  'update:paymentCurrencyId': [v: number | null]
  'update:paymentExchangeRate': [v: number | null]
  'update:paidAmountPaymentCcy': [v: number | null]
  'update:paidAmountInvoiceCcy': [v: number | null]
  'update:exchangeDiffBase': [v: number | null]
}>()

const { t } = useI18n()

// Sekce default-collapsed, jen pokud má hodnotu nebo user otevře
const hasValues = computed(() => !!(
  props.paymentCurrencyId || props.paymentExchangeRate || props.paidAmountPaymentCcy
))
const expanded = ref(hasValues.value)

const paymentCurrencyCode = computed(() => {
  if (!props.paymentCurrencyId) return ''
  return props.currencies.find(c => c.id === props.paymentCurrencyId)?.code ?? ''
})

// Auto-calc exchange_diff_base (CZK) když user zadá platbu:
//   paid_payment_ccy × kurz_payment_ccy_to_base − total_with_vat × invoice.exchange_rate
// Předpokládáme, že base = CZK; pokud má supplier jinou base (EUR), tohle bude muset upgrade.
function recalcDiff() {
  const paid = props.paidAmountPaymentCcy
  const rate = props.paymentExchangeRate
  const invRate = props.invoiceExchangeRate
  if (paid !== null && rate !== null && invRate !== null) {
    // Convert paid_payment_ccy to invoice ccy:
    const equivInvoiceCcy = paid * rate
    emit('update:paidAmountInvoiceCcy', round2(equivInvoiceCcy))
    // Diff = paid_in_base − billed_in_base = (paid_payment_ccy × kurz_to_base) − (total × invRate)
    // V tomto MVP předpokládáme payment_ccy kurz je rovnou k base (CZK).
    // Pokud payment_ccy = base (CZK), payment_exchange_rate × paid = base
    // Pokud payment_ccy ≠ base, user by měl zadat rate vůči base. Pro fázi 1 simplifikujeme.
    const paidBase = paid * (paymentCurrencyCode.value === 'CZK' ? 1 : rate)
    const billedBase = props.totalWithVat * invRate
    emit('update:exchangeDiffBase', round2(paidBase - billedBase))
  }
}

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

watch(() => [props.paidAmountPaymentCcy, props.paymentExchangeRate, props.invoiceExchangeRate], recalcDiff)

// Distinct ISO codes, vyloučit currency_id faktury (platba v jiné měně)
const otherCurrencies = computed(() => {
  const invoiceCode = props.currencies.find(c => c.id === props.invoiceCurrencyId)?.code
  const byCode = new Map<string, typeof props.currencies[number]>()
  for (const c of props.currencies) {
    if (c.code === invoiceCode) continue
    const existing = byCode.get(c.code)
    if (!existing || c.is_default) byCode.set(c.code, c)
  }
  return Array.from(byCode.values()).sort((a, b) => a.code.localeCompare(b.code))
})
</script>

<template>
  <div class="border border-neutral-200 rounded-lg">
    <button
      type="button"
      class="w-full text-left px-4 py-2 flex items-center justify-between hover:bg-neutral-50 cursor-pointer"
      @click="expanded = !expanded"
    >
      <span class="text-sm font-medium">{{ t('purchase_invoice.payment_currency.toggle') }}</span>
      <svg class="w-4 h-4 transition-transform" :class="expanded ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <div v-if="expanded" class="px-4 pb-4 pt-1 space-y-3 border-t border-neutral-100">
      <p class="text-xs text-neutral-500">{{ t('purchase_invoice.payment_currency.hint') }}</p>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.payment_currency.currency') }}</label>
          <select
            :value="paymentCurrencyId ?? ''"
            @change="emit('update:paymentCurrencyId', ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null)"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm"
          >
            <option value="">—</option>
            <option v-for="c in otherCurrencies" :key="c.id" :value="c.id">{{ c.code }}</option>
          </select>
        </div>

        <div>
          <label class="block text-sm text-neutral-700 mb-1">
            {{ t('purchase_invoice.payment_currency.rate') }}
            <span v-if="paymentCurrencyCode && invoiceCurrency" class="text-xs text-neutral-500">
              ({{ paymentCurrencyCode }}/{{ invoiceCurrency }})
            </span>
          </label>
          <input
            type="number"
            step="0.0001"
            min="0"
            :value="paymentExchangeRate ?? ''"
            @input="emit('update:paymentExchangeRate', ($event.target as HTMLInputElement).value ? Number(($event.target as HTMLInputElement).value) : null)"
            class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm font-mono"
          />
        </div>

        <div>
          <label class="block text-sm text-neutral-700 mb-1">
            {{ t('purchase_invoice.payment_currency.paid_payment_ccy') }}
            <span v-if="paymentCurrencyCode" class="text-xs text-neutral-500">({{ paymentCurrencyCode }})</span>
          </label>
          <input
            type="number"
            step="0.01"
            :value="paidAmountPaymentCcy ?? ''"
            @input="emit('update:paidAmountPaymentCcy', ($event.target as HTMLInputElement).value ? Number(($event.target as HTMLInputElement).value) : null)"
            class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm font-mono"
          />
        </div>

        <div>
          <label class="block text-sm text-neutral-700 mb-1">
            {{ t('purchase_invoice.payment_currency.paid_invoice_ccy') }}
            <span class="text-xs text-neutral-500">({{ invoiceCurrency }})</span>
          </label>
          <input
            type="number"
            step="0.01"
            :value="paidAmountInvoiceCcy ?? ''"
            @input="emit('update:paidAmountInvoiceCcy', ($event.target as HTMLInputElement).value ? Number(($event.target as HTMLInputElement).value) : null)"
            class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm font-mono bg-neutral-50"
            readonly
          />
        </div>

        <div class="sm:col-span-2">
          <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.payment_currency.exchange_diff') }}</label>
          <input
            type="number"
            step="0.01"
            :value="exchangeDiffBase ?? ''"
            @input="emit('update:exchangeDiffBase', ($event.target as HTMLInputElement).value ? Number(($event.target as HTMLInputElement).value) : null)"
            class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm font-mono"
            :class="exchangeDiffBase !== null && exchangeDiffBase < 0 ? 'text-danger-600' : 'text-success-600'"
          />
        </div>
      </div>
    </div>
  </div>
</template>

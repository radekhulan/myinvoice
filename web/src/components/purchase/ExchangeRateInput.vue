<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
  modelValue: number | null
  currency: string
  rateDate: string
  /** Rate value je editable; false = read-only display (např. archivovaná faktura) */
  editable?: boolean
}>(), {
  editable: true,
})

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
  /** Emit po načtení z ČNB — kompozit (rate + rate_date jak ho ČNB vrátila). */
  'cnb-loaded': [value: { rate: number; rate_date: string }]
  /** Když user změní rate ručně, parent dostane signal source = 'manual' */
  'source-change': [source: 'manual' | 'cnb']
}>()

const { t } = useI18n()
const loading = ref(false)
const errorMsg = ref('')

function onInput(e: Event) {
  const v = (e.target as HTMLInputElement).value
  const num = v === '' ? null : Number(v)
  emit('update:modelValue', num)
  emit('source-change', 'manual')
}

async function loadFromCnb() {
  if (!props.currency || props.currency === 'CZK' || !props.rateDate) return
  loading.value = true
  errorMsg.value = ''
  try {
    // Backend endpoint existuje pro vystavené faktury — reuse pro purchase.
    // /api/codebooks/cnb-rate?currency=USD&date=2026-05-20
    const res = await fetch(`/api/codebooks/cnb-rate?currency=${encodeURIComponent(props.currency)}&date=${encodeURIComponent(props.rateDate)}`, {
      headers: { 'X-Supplier-Id': localStorage.getItem('myinvoice.current_supplier_id') || '1' },
    })
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const data = await res.json()
    if (data?.rate) {
      emit('update:modelValue', Number(data.rate))
      emit('cnb-loaded', { rate: Number(data.rate), rate_date: String(data.rate_date || props.rateDate) })
      emit('source-change', 'cnb')
    } else {
      errorMsg.value = 'ČNB nevrátila kurz'
    }
  } catch (e: any) {
    errorMsg.value = e?.message || 'chyba'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="space-y-1">
    <label class="block text-sm text-neutral-700">{{ t('purchase_invoice.fields.exchange_rate') }}</label>
    <div class="flex items-center gap-2">
      <input
        type="number"
        step="0.0001"
        min="0"
        :value="modelValue ?? ''"
        @input="onInput"
        :disabled="!editable"
        class="flex-1 px-3 py-1.5 border border-neutral-300 rounded-md text-sm font-mono"
      />
      <span class="text-xs text-neutral-500 whitespace-nowrap">CZK/{{ currency }}</span>
      <button
        v-if="editable && currency && currency !== 'CZK'"
        type="button"
        @click="loadFromCnb"
        :disabled="loading"
        class="cursor-pointer px-3 py-1.5 text-xs border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50"
      >
        {{ loading ? '…' : t('purchase_invoice.fields.exchange_rate_load_cnb') }}
      </button>
    </div>
    <p v-if="errorMsg" class="text-xs text-danger-600">{{ errorMsg }}</p>
  </div>
</template>

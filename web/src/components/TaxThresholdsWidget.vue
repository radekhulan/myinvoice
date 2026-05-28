<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { dashboardApi, type TaxThresholds, type ThresholdStatus } from '@/api/dashboard'

const { t } = useI18n()
const data = ref<TaxThresholds | null>(null)
const error = ref('')
const loading = ref(true)

onMounted(async () => {
  try {
    data.value = await dashboardApi.taxThresholds()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || String(e)
  } finally {
    loading.value = false
  }
})

function fmtCzk(amount: number | null | undefined): string {
  if (amount === null || amount === undefined) return '—'
  return new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK', maximumFractionDigits: 0 }).format(amount)
}

function statusBar(s: ThresholdStatus): string {
  return {
    ok:      'bg-emerald-500',
    notice:  'bg-sky-500',
    warning: 'bg-amber-500',
    danger:  'bg-rose-500',
  }[s]
}
function statusBg(s: ThresholdStatus): string {
  return {
    ok:      'bg-emerald-50  border-emerald-200',
    notice:  'bg-sky-50      border-sky-200',
    warning: 'bg-amber-50    border-amber-200',
    danger:  'bg-rose-50     border-rose-200',
  }[s]
}
function statusText(s: ThresholdStatus): string {
  return {
    ok:      'text-emerald-700',
    notice:  'text-sky-700',
    warning: 'text-amber-700',
    danger:  'text-rose-700',
  }[s]
}
</script>

<template>
  <section
    v-if="loading"
    class="rounded-lg border border-neutral-200 bg-white p-4 text-sm text-neutral-500"
  >
    {{ t('tax_thresholds.loading') }}
  </section>

  <section
    v-else-if="error"
    class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"
  >
    {{ error }}
  </section>

  <section
    v-else-if="data && (data.vat_threshold.applicable || data.flat_tax_threshold.applicable)"
    class="space-y-3"
  >
    <!-- DPH limit (neplátce) -->
    <div
      v-if="data.vat_threshold.applicable && data.vat_threshold.rolling12m"
      class="rounded-lg border p-4"
      :class="statusBg(data.vat_threshold.rolling12m.status)"
    >
      <div class="flex items-baseline justify-between gap-2">
        <h3 class="text-sm font-semibold text-neutral-800">
          {{ t('tax_thresholds.vat_title') }}
        </h3>
        <span class="text-xs text-neutral-500">
          {{ data.vat_threshold.rolling12m.window_from }} — {{ data.vat_threshold.rolling12m.window_to }}
        </span>
      </div>
      <div class="mt-2 flex items-baseline justify-between font-mono text-sm">
        <span :class="statusText(data.vat_threshold.rolling12m.status)">
          {{ fmtCzk(data.vat_threshold.rolling12m.current_czk) }}
        </span>
        <span class="text-neutral-500">
          / {{ fmtCzk(data.vat_threshold.rolling12m.limit_czk) }}
          <span class="ml-2 font-semibold" :class="statusText(data.vat_threshold.rolling12m.status)">
            {{ data.vat_threshold.rolling12m.percent }} %
          </span>
        </span>
      </div>
      <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-white/70">
        <div
          class="h-full transition-all"
          :class="statusBar(data.vat_threshold.rolling12m.status)"
          :style="{ width: Math.min(100, data.vat_threshold.rolling12m.percent) + '%' }"
        ></div>
      </div>

      <div v-if="data.vat_threshold.calendar_year" class="mt-3 border-t border-white/60 pt-2 text-xs text-neutral-600">
        {{ t('tax_thresholds.vat_year_label', { year: data.vat_threshold.calendar_year.year }) }}:
        <span class="font-mono">{{ fmtCzk(data.vat_threshold.calendar_year.current_czk) }}</span>
        / <span class="font-mono">{{ fmtCzk(data.vat_threshold.calendar_year.limit_czk) }}</span>
        ({{ data.vat_threshold.calendar_year.percent }} %)
      </div>
      <p class="mt-2 text-xs text-neutral-500">{{ t('tax_thresholds.vat_hint') }}</p>
    </div>

    <!-- Paušální daň -->
    <div
      v-if="data.flat_tax_threshold.applicable && data.flat_tax_threshold.status"
      class="rounded-lg border p-4"
      :class="statusBg(data.flat_tax_threshold.status)"
    >
      <div class="flex items-baseline justify-between gap-2">
        <h3 class="text-sm font-semibold text-neutral-800">
          {{ t('tax_thresholds.flat_title', { band: t('tax_thresholds.band_' + data.flat_tax_threshold.band) }) }}
        </h3>
        <span class="text-xs text-neutral-500">{{ data.flat_tax_threshold.year }}</span>
      </div>
      <div class="mt-2 flex items-baseline justify-between font-mono text-sm">
        <span :class="statusText(data.flat_tax_threshold.status)">
          {{ fmtCzk(data.flat_tax_threshold.current_czk) }}
        </span>
        <span class="text-neutral-500">
          / {{ fmtCzk(data.flat_tax_threshold.limit_czk) }}
          <span class="ml-2 font-semibold" :class="statusText(data.flat_tax_threshold.status)">
            {{ data.flat_tax_threshold.percent }} %
          </span>
        </span>
      </div>
      <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-white/70">
        <div
          class="h-full transition-all"
          :class="statusBar(data.flat_tax_threshold.status)"
          :style="{ width: Math.min(100, data.flat_tax_threshold.percent ?? 0) + '%' }"
        ></div>
      </div>
      <p class="mt-2 text-xs text-neutral-500">
        {{ t('tax_thresholds.flat_hint', { advance: fmtCzk(data.flat_tax_threshold.monthly_advance_czk) }) }}
      </p>
    </div>
  </section>
</template>

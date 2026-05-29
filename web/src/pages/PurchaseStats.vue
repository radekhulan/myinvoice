<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { purchaseStatsApi, type PurchaseSummary } from '@/api/purchaseStats'
import { formatMoney } from '@/composables/useFormat'
import type { TopClient } from '@/api/dashboard'
import RevenueChart from '@/components/charts/RevenueChart.vue'
import CumulativeYtdChart from '@/components/charts/CumulativeYtdChart.vue'
import TopClientsPieChart from '@/components/charts/TopClientsPieChart.vue'
import PurchaseStatusChart from '@/components/charts/PurchaseStatusChart.vue'
import PaymentDaysHistogramChart from '@/components/charts/PaymentDaysHistogramChart.vue'
import VatBreakdownChart from '@/components/charts/VatBreakdownChart.vue'
import AgingChart from '@/components/charts/AgingChart.vue'
import InvoiceSizeChart from '@/components/charts/InvoiceSizeChart.vue'

const { t } = useI18n()

const summary = ref<PurchaseSummary | null>(null)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    summary.value = await purchaseStatsApi.summary()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('errors.generic')
  } finally {
    loading.value = false
  }
})

const isVatPayer = computed(() => summary.value?.is_vat_payer ?? false)

/** Náklady tento rok per měna — pole pro KPI tile. */
const costsThisYear = computed(() =>
  (summary.value?.kpi.per_currency ?? []).map(c => ({
    currency: c.currency,
    total: c.this_year,
    change_pct: c.change_pct,
    invoice_count: c.this_year_invoice_count,
    vendor_count: c.this_year_vendor_count,
  }))
)
const costsPrevYear = computed(() =>
  (summary.value?.kpi.per_currency ?? []).map(c => ({
    currency: c.currency,
    total: c.prev_year,
    invoice_count: c.prev_year_invoice_count,
    vendor_count: c.prev_year_vendor_count,
  }))
)

/** Top dodavatelé namapovaní do tvaru TopClient pro reuse pie komponenty (čte company_name + total_czk). */
function vendorsToPie(vendors: PurchaseSummary['top_vendors_ytd']): TopClient[] {
  return vendors.map(v => ({
    client_id: v.vendor_id,
    company_name: v.company_name,
    currencies: v.currencies,
    total_czk: v.total_czk,
    invoice_count: v.invoice_count,
  }))
}

/** Měsíční breakdown pro tabulku — všechny měny do jednoho indexu YYYY-MM. */
const monthlyTable = computed(() => {
  if (!summary.value) return [] as Array<{ ym: string; perCurrency: Array<{ currency: string; total: number }> }>
  const index = new Map<string, Map<string, number>>()
  for (const rev of summary.value.costs_by_month) {
    for (const m of rev.months) {
      if (!index.has(m.ym)) index.set(m.ym, new Map())
      index.get(m.ym)!.set(rev.currency, m.total)
    }
  }
  return Array.from(index.entries())
    .sort((a, b) => b[0].localeCompare(a[0]))
    .map(([ym, perMap]) => ({
      ym,
      perCurrency: Array.from(perMap.entries()).map(([currency, total]) => ({ currency, total })),
    }))
})

function ymLabel(ym: string): string {
  const [y, m] = ym.split('-')
  return `${m}/${y}`
}

/**
 * Vendor concentration — % nákladů z TOP3/TOP5 dodavatelů (rolling 12m).
 * Vysoká koncentrace = riziko závislosti na jednom dodavateli (přerušení dodávek).
 */
const concentration = computed(() => {
  const items = summary.value?.top_vendors_12m ?? []
  if (!items.length) return null
  const total = items.reduce((s, i) => s + i.total_czk, 0)
  if (total <= 0) return null
  const top3 = items.slice(0, 3).reduce((s, i) => s + i.total_czk, 0)
  const top5 = items.slice(0, 5).reduce((s, i) => s + i.total_czk, 0)
  return {
    currency: 'CZK',
    top3_pct: Math.round((top3 / total) * 100),
    top5_pct: Math.round((top5 / total) * 100),
    top3_total: top3,
    top5_total: top5,
  }
})

const concentrationLevel = computed(() => {
  const c = concentration.value
  if (!c) return 'ok'
  if (c.top3_pct >= 70 || c.top5_pct >= 90) return 'high'
  if (c.top3_pct >= 50 || c.top5_pct >= 70) return 'medium'
  return 'ok'
})

/** Aging report jen s ne-nulovými řádky. */
const agingRows = computed(() => {
  return (summary.value?.aging_report ?? []).filter(r =>
    r.current + r.b1_30 + r.b31_60 + r.b61_90 + r.b90_plus > 0
  )
})

/** Cash-outflow forecast jen s ne-nulovými měnami. */
const outflowForecast = computed(() =>
  (summary.value?.cashflow_forecast ?? []).filter(f => f.out_90 > 0)
)

const expenseRows = computed(() => summary.value?.expense_breakdown_12m ?? [])
const hasUncategorized = computed(() =>
  expenseRows.value.length > 0 && expenseRows.value.some(e => e.category_id === null)
)

const hasAnyData = computed(() =>
  !!(summary.value && (summary.value.rolling_12m.length > 0
    || summary.value.costs_by_month.length > 0
    || summary.value.top_vendors_ytd.length > 0
    || summary.value.top_vendors_prev_year.length > 0))
)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-semibold mb-1">{{ t('costs.title') }}</h1>
      <p class="text-sm text-neutral-500">{{ t('costs.subtitle') }}</p>
      <div v-if="summary" class="mt-2 inline-flex items-center gap-2 text-xs px-2.5 py-1 rounded-full"
        :class="isVatPayer ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-600'">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/>
        </svg>
        {{ isVatPayer ? t('costs.vat_payer_note') : t('costs.non_vat_payer_note') }}
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('dashboard.loading_data') }}</div>

    <div v-else-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
      {{ error }}
    </div>

    <div v-else-if="!hasAnyData" class="bg-surface border border-neutral-200 rounded-lg p-8 text-center">
      <p class="text-neutral-500">{{ t('costs.no_data') }}</p>
    </div>

    <div v-else-if="summary" class="space-y-6">
      <!-- KPI tiles per měna -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <!-- Plovoucí 12měsíční náklady per měna -->
        <div v-for="r in summary.rolling_12m" :key="`r12-${r.currency}`"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">
            {{ t('costs.rolling_12m', { currency: r.currency }) }}
          </div>
          <div class="text-2xl font-semibold text-neutral-900 font-mono">{{ formatMoney(r.total, r.currency) }}</div>
          <div v-if="r.prev_period_total > 0" class="text-xs mt-1"
            :class="r.total <= r.prev_period_total ? 'text-success-600' : 'text-danger-500'">
            {{ t('costs.rolling_12m_yoy', {
              sign: r.total >= r.prev_period_total ? '▲' : '▼',
              pct: Math.abs(Math.round(((r.total - r.prev_period_total) / r.prev_period_total) * 100 * 10) / 10)
            }) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('costs.rolling_12m_vs_prev', { total: formatMoney(r.prev_period_total, r.currency) }) }}
          </div>
          <div class="text-[11px] text-neutral-400 mt-2">{{ t('costs.rolling_12m_hint') }}</div>
        </div>

        <!-- Náklady tento rok per měna -->
        <div v-for="r in costsThisYear" :key="`ty-${r.currency}`"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">
            {{ t('costs.costs_this_year', { year: summary.year, currency: r.currency }) }}
          </div>
          <div class="text-2xl font-semibold text-neutral-900 font-mono">{{ formatMoney(r.total, r.currency) }}</div>
          <div v-if="r.change_pct !== null" class="text-xs mt-1"
            :class="r.change_pct <= 0 ? 'text-success-600' : 'text-danger-500'">
            {{ r.change_pct >= 0 ? '▲' : '▼' }} {{ Math.abs(r.change_pct) }} % {{ t('costs.vs_prev_ytd', { year: summary.prev_year }) }}
          </div>
          <div v-else class="text-xs text-neutral-400 mt-1">{{ t('costs.no_prev_year', { year: summary.prev_year }) }}</div>
          <div class="text-[11px] text-neutral-500 mt-2 flex flex-wrap gap-x-3 gap-y-0.5">
            <span>{{ t('costs.year_invoices_n', { n: r.invoice_count }) }}</span>
            <span>{{ t('costs.year_vendors_n', { n: r.vendor_count }) }}</span>
          </div>
        </div>

        <!-- Forecast nákladů aktuálního roku per měna -->
        <div v-for="f in summary.costs_forecast" :key="`fc-${f.currency}`"
          class="bg-surface border border-primary-200 rounded-lg p-5 shadow-sm bg-primary-50/30">
          <div class="text-xs uppercase tracking-wide text-primary-700 mb-1">
            {{ t('costs.forecast_year', { year: summary.year, currency: f.currency }) }}
          </div>
          <div class="text-2xl font-semibold text-primary-700 font-mono"
            :title="t('costs.forecast_tooltip', {
              ytd: formatMoney(f.ytd, f.currency),
              growth: Math.round((f.growth_ratio - 1) * 100 * 10) / 10,
              remainder: formatMoney(f.prev_year_remainder, f.currency)
            })">{{ formatMoney(f.forecast, f.currency) }}</div>
          <div v-if="f.prev_year_full > 0" class="text-xs mt-1"
            :class="f.forecast <= f.prev_year_full ? 'text-success-600' : 'text-danger-500'">
            {{ f.forecast >= f.prev_year_full ? '▲' : '▼' }}
            {{ Math.abs(Math.round(((f.forecast - f.prev_year_full) / f.prev_year_full) * 100 * 10) / 10) }} %
            {{ t('costs.vs_prev_year_full', { year: summary.prev_year }) }}
          </div>
          <div class="text-[11px] text-neutral-500 mt-2">
            {{ t('costs.forecast_growth_hint', { growth: ((f.growth_ratio - 1) * 100).toFixed(1) }) }}
          </div>
        </div>

        <!-- Náklady minulý rok per měna -->
        <div v-for="r in costsPrevYear" :key="`py-${r.currency}`"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">
            {{ t('costs.costs_prev_year', { year: summary.prev_year, currency: r.currency }) }}
          </div>
          <div class="text-2xl font-semibold text-neutral-700 font-mono">{{ formatMoney(r.total, r.currency) }}</div>
          <div class="text-[11px] text-neutral-500 mt-2 flex flex-wrap gap-x-3 gap-y-0.5">
            <span>{{ t('costs.year_invoices_n', { n: r.invoice_count }) }}</span>
            <span>{{ t('costs.year_vendors_n', { n: r.vendor_count }) }}</span>
          </div>
        </div>

        <!-- Počet přijatých faktur YTD -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('costs.purchases_count_ytd', { year: summary.year }) }}</div>
          <div class="text-2xl font-semibold text-neutral-900">{{ summary.kpi.purchase_count_ytd }}</div>
          <div class="text-xs text-neutral-400 mt-1">{{ t('costs.invoices_unit') }}</div>
        </div>

        <!-- Aktivní dodavatelé -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('costs.active_vendors') }}</div>
          <div class="text-2xl font-semibold text-neutral-900">{{ summary.active_vendors_count }}</div>
          <div class="text-xs text-neutral-400 mt-1">{{ t('costs.active_vendors_hint') }}</div>
        </div>

        <!-- Ø doba úhrady dodavatelům -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('costs.avg_payment') }}</div>
          <div class="text-2xl font-semibold text-neutral-900">
            {{ summary.kpi.avg_payment_days !== null ? summary.kpi.avg_payment_days + ' ' + t('common.days') : '—' }}
          </div>
          <div class="text-xs text-neutral-400 mt-1">{{ t('costs.avg_payment_hint') }}</div>
        </div>

        <!-- Náklady posledních 30 dní per měna -->
        <div v-for="r in summary.costs_last_30d" :key="`c30-${r.currency}`"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">
            {{ t('costs.costs_last_30d', { currency: r.currency }) }}
          </div>
          <div class="text-2xl font-semibold text-neutral-900 font-mono">{{ formatMoney(r.total, r.currency) }}</div>
          <div class="text-xs text-neutral-500 mt-1">{{ r.invoice_count }} {{ t('costs.invoices_unit') }}</div>
          <div class="text-[11px] text-neutral-400 mt-2">{{ t('costs.last_30d_hint') }}</div>
        </div>

        <!-- Nezaplacené závazky -->
        <RouterLink to="/purchase-invoices"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm hover:bg-neutral-50 transition cursor-pointer block">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('costs.unpaid_payables') }}</div>
          <div class="text-2xl font-semibold" :class="summary.kpi.overdue_count > 0 ? 'text-danger-500' : 'text-neutral-900'">
            {{ summary.kpi.unpaid_count }}
          </div>
          <div class="text-[11px] text-neutral-500 mt-2 space-x-2">
            <span v-for="u in summary.kpi.unpaid_per_currency" :key="`up-${u.currency}`" class="font-mono">
              {{ formatMoney(u.total, u.currency) }}
            </span>
          </div>
          <div v-if="summary.kpi.overdue_count > 0" class="text-[11px] text-danger-500 mt-1">
            {{ t('costs.overdue_n', { n: summary.kpi.overdue_count }) }}
          </div>
          <div v-else class="text-[11px] text-neutral-400 mt-1">{{ t('costs.unpaid_payables_hint') }}</div>
        </RouterLink>
      </div>

      <!-- Měsíční náklady — bar + prev-year linka -->
      <div v-if="summary.costs_by_month.length" class="space-y-4">
        <div v-for="rev in summary.costs_by_month" :key="`m-${rev.currency}`"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('costs.costs_last_12_months', { currency: rev.currency }) }}
          </h3>
          <RevenueChart :months="rev.months" :prev-year="rev.prev_year" :currency="rev.currency" />
        </div>
      </div>

      <!-- Kumulativní cash-outflow YTD vs loni — per měna -->
      <div v-if="summary.cashflow_out_ytd.length" class="space-y-4">
        <div v-for="cf in summary.cashflow_out_ytd" :key="`cf-${cf.currency}`"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('costs.cumulative_outflow', { currency: cf.currency }) }}
            </h3>
            <span class="text-xs text-neutral-400">{{ t('costs.cumulative_outflow_hint') }}</span>
          </div>
          <CumulativeYtdChart :months="cf.months" :prev-year="cf.prev_year" :currency="cf.currency" />
        </div>
      </div>

      <!-- Top dodavatelé pie YTD + loni -->
      <div v-if="(summary.top_vendors_ytd.length + summary.top_vendors_prev_year.length) > 0"
        class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div v-if="summary.top_vendors_ytd.length" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('costs.top_vendors_year', { year: summary.year }) }}
          </h3>
          <TopClientsPieChart :clients="vendorsToPie(summary.top_vendors_ytd)" />
        </div>
        <div v-if="summary.top_vendors_prev_year.length" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('costs.top_vendors_year', { year: summary.prev_year }) }}
          </h3>
          <TopClientsPieChart :clients="vendorsToPie(summary.top_vendors_prev_year)" />
        </div>
      </div>

      <!-- Status donut + rozpad DPH na vstupu -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div v-if="summary.kpi.status_counts_ytd && Object.keys(summary.kpi.status_counts_ytd).length"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('costs.status_purchases', { year: summary.year }) }}
          </h3>
          <PurchaseStatusChart :counts="summary.kpi.status_counts_ytd" />
        </div>
        <div v-if="isVatPayer && summary.vat_breakdown_12m.length"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('costs.vat_input_breakdown_title') }}</h3>
            <span class="text-xs font-mono text-neutral-500">{{ t('costs.last_12_months') }}</span>
          </div>
          <VatBreakdownChart :items="summary.vat_breakdown_12m" :currency="summary.vat_breakdown_12m[0].currency" />
        </div>
      </div>

      <!-- Číselné tabulky: náklady po rocích + po měsících -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Náklady po rocích -->
        <div v-if="summary.costs_by_year.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="font-semibold">{{ t('costs.costs_by_year_table') }}</h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium">{{ t('common.year') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('common.costs') }}</th>
                  <th class="text-right px-4 py-2 font-medium whitespace-nowrap">{{ t('costs.invoices_short') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="f in summary.costs_forecast" :key="`fc-row-${f.currency}`" class="bg-primary-50/40">
                  <td class="px-4 py-2 font-medium text-primary-700">
                    {{ summary.year }}
                    <span class="ml-1 text-[10px] font-normal text-primary-600 uppercase tracking-wide">{{ t('costs.forecast_label') }}</span>
                  </td>
                  <td class="px-4 py-2 text-right font-mono text-primary-700">{{ formatMoney(f.forecast, f.currency) }}</td>
                  <td class="px-4 py-2 text-right text-xs text-primary-600">—</td>
                </tr>
                <tr v-for="r in summary.costs_by_year" :key="`y-${r.year}-${r.currency}`">
                  <td class="px-4 py-2 font-medium">{{ r.year }}</td>
                  <td class="px-4 py-2 text-right font-mono">{{ formatMoney(r.total, r.currency) }}</td>
                  <td class="px-4 py-2 text-right text-xs text-neutral-500">{{ r.invoice_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Náklady po měsících -->
        <div v-if="monthlyTable.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="font-semibold">{{ t('costs.costs_by_month_table') }}</h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium">{{ t('common.month') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('common.costs') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in monthlyTable" :key="`mt-${row.ym}`">
                  <td class="px-4 py-2 font-mono text-neutral-700">{{ ymLabel(row.ym) }}</td>
                  <td class="px-4 py-2 text-right font-mono space-x-3">
                    <span v-for="c in row.perCurrency" :key="`${row.ym}-${c.currency}`">
                      {{ formatMoney(c.total, c.currency) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Top 12 dodavatelů (12m) + rozpad nákladů po kategoriích -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div v-if="summary.top_vendors_12m.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="font-semibold">{{ t('costs.top_vendors_12m_table') }}</h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium w-8">#</th>
                  <th class="text-left px-4 py-2 font-medium">{{ t('nav.vendors') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('common.costs') }}</th>
                  <th class="text-right px-4 py-2 font-medium whitespace-nowrap">{{ t('costs.invoices_short') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="(v, i) in summary.top_vendors_12m" :key="`tv12-${v.vendor_id}`"
                    class="hover:bg-neutral-50 cursor-pointer"
                    @click="$router.push(`/clients/${v.vendor_id}`)">
                  <td class="px-4 py-2 text-neutral-400 font-mono text-xs">{{ i + 1 }}</td>
                  <td class="px-4 py-2 truncate max-w-[260px]">
                    {{ v.company_name }}
                    <span v-if="v.currencies && v.currencies !== 'CZK'" class="ml-1 text-xs text-neutral-400">({{ v.currencies }})</span>
                  </td>
                  <td class="px-4 py-2 text-right font-mono">{{ formatMoney(v.total_czk, 'CZK') }}</td>
                  <td class="px-4 py-2 text-right text-xs text-neutral-500">{{ v.invoice_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Rozpad nákladů po kategoriích -->
        <div v-if="expenseRows.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="font-semibold">{{ t('costs.expense_breakdown_title') }}</h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="e in expenseRows" :key="`ec-${e.category_id ?? 0}-${e.code ?? ''}`" class="hover:bg-neutral-50">
                  <td class="px-5 py-2">
                    <div class="font-medium text-neutral-900">{{ e.label || t('costs.expense_uncategorized') }}</div>
                    <div class="text-xs text-neutral-500">{{ e.count }} {{ t('costs.invoices_unit') }}</div>
                  </td>
                  <td class="px-3 py-2 w-1/3">
                    <div class="w-full h-2 bg-neutral-100 rounded">
                      <div class="h-full bg-warning-500 rounded" :style="{ width: e.percent + '%' }"></div>
                    </div>
                  </td>
                  <td class="px-3 py-2 text-right font-mono text-neutral-900">{{ formatMoney(e.total_czk, 'CZK') }}</td>
                  <td class="px-5 py-2 text-right text-xs text-neutral-500 font-mono w-12">{{ e.percent.toFixed(1) }}%</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="hasUncategorized" class="px-5 py-2 text-xs text-warning-600 bg-warning-50 border-t border-warning-500/40">
            💡 {{ t('costs.expense_uncategorized_hint') }}
          </div>
        </div>
      </div>

      <!-- Vendor concentration risk -->
      <div v-if="concentration" class="bg-surface border rounded-lg p-5 shadow-sm"
        :class="concentrationLevel === 'high' ? 'border-danger-500/40 bg-danger-50/30'
              : concentrationLevel === 'medium' ? 'border-warning-500/50 bg-warning-50/30'
              : 'border-neutral-200'">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('costs.concentration_title') }}</h3>
          <span class="text-xs font-mono text-neutral-500">{{ concentration.currency }} · {{ t('costs.last_12_months') }}</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <div>
            <div class="text-xs text-neutral-500 mb-1">{{ t('costs.concentration_top3') }}</div>
            <div class="flex items-baseline gap-2">
              <span class="text-3xl font-semibold font-mono"
                :class="concentration.top3_pct >= 70 ? 'text-danger-500' : concentration.top3_pct >= 50 ? 'text-warning-600' : 'text-neutral-900'">{{ concentration.top3_pct }} %</span>
              <span class="text-xs text-neutral-500">{{ formatMoney(concentration.top3_total, concentration.currency) }}</span>
            </div>
            <div class="h-1.5 mt-2 bg-neutral-100 rounded-full overflow-hidden">
              <div class="h-full rounded-full"
                :class="concentration.top3_pct >= 70 ? 'bg-danger-500' : concentration.top3_pct >= 50 ? 'bg-warning-500' : 'bg-success-600'"
                :style="{ width: Math.min(100, concentration.top3_pct) + '%' }"></div>
            </div>
          </div>
          <div>
            <div class="text-xs text-neutral-500 mb-1">{{ t('costs.concentration_top5') }}</div>
            <div class="flex items-baseline gap-2">
              <span class="text-3xl font-semibold font-mono"
                :class="concentration.top5_pct >= 90 ? 'text-danger-500' : concentration.top5_pct >= 70 ? 'text-warning-600' : 'text-neutral-900'">{{ concentration.top5_pct }} %</span>
              <span class="text-xs text-neutral-500">{{ formatMoney(concentration.top5_total, concentration.currency) }}</span>
            </div>
            <div class="h-1.5 mt-2 bg-neutral-100 rounded-full overflow-hidden">
              <div class="h-full rounded-full"
                :class="concentration.top5_pct >= 90 ? 'bg-danger-500' : concentration.top5_pct >= 70 ? 'bg-warning-500' : 'bg-success-600'"
                :style="{ width: Math.min(100, concentration.top5_pct) + '%' }"></div>
            </div>
          </div>
        </div>
        <div class="text-xs mt-3"
          :class="concentrationLevel === 'high' ? 'text-danger-500 font-medium'
                : concentrationLevel === 'medium' ? 'text-warning-600' : 'text-neutral-500'">
          <span v-if="concentrationLevel === 'high'">{{ t('costs.concentration_high') }}</span>
          <span v-else-if="concentrationLevel === 'medium'">{{ t('costs.concentration_medium') }}</span>
          <span v-else>{{ t('costs.concentration_ok') }}</span>
        </div>
      </div>

      <!-- Histogram doby úhrady + plán plateb dodavatelům -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div v-if="summary.payment_days_histogram.total > 0" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('costs.payment_histogram_title') }}</h3>
            <span class="text-xs text-neutral-500">
              {{ t('costs.payment_histogram_avg', { days: summary.payment_days_histogram.avg_days, n: summary.payment_days_histogram.total }) }}
            </span>
          </div>
          <PaymentDaysHistogramChart :buckets="summary.payment_days_histogram.buckets" />
        </div>

        <!-- Plán plateb (cash-outflow 30/60/90) -->
        <div v-if="outflowForecast.length" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('costs.outflow_forecast_title') }}</h3>
            <span class="text-xs text-neutral-400">{{ t('costs.outflow_forecast_hint') }}</span>
          </div>
          <div class="space-y-4">
            <div v-for="f in outflowForecast" :key="`of-${f.currency}`">
              <div class="text-xs font-mono text-neutral-500 mb-2">{{ f.currency }}</div>
              <div class="grid grid-cols-3 gap-2 text-center">
                <div class="rounded-md bg-neutral-50 border border-neutral-200 p-2">
                  <div class="text-[11px] text-neutral-500">{{ t('costs.outflow_30') }}</div>
                  <div class="text-sm font-semibold font-mono text-neutral-900">{{ formatMoney(f.out_30, f.currency) }}</div>
                  <div class="text-[10px] text-neutral-400">{{ t('costs.outflow_n_invoices', { n: f.count_30 }) }}</div>
                </div>
                <div class="rounded-md bg-neutral-50 border border-neutral-200 p-2">
                  <div class="text-[11px] text-neutral-500">{{ t('costs.outflow_60') }}</div>
                  <div class="text-sm font-semibold font-mono text-neutral-900">{{ formatMoney(f.out_60, f.currency) }}</div>
                  <div class="text-[10px] text-neutral-400">{{ t('costs.outflow_n_invoices', { n: f.count_60 }) }}</div>
                </div>
                <div class="rounded-md bg-neutral-50 border border-neutral-200 p-2">
                  <div class="text-[11px] text-neutral-500">{{ t('costs.outflow_90') }}</div>
                  <div class="text-sm font-semibold font-mono text-neutral-900">{{ formatMoney(f.out_90, f.currency) }}</div>
                  <div class="text-[10px] text-neutral-400">{{ t('costs.outflow_n_invoices', { n: f.count_90 }) }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Aging report — stáří závazků -->
      <div v-if="agingRows.length" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('costs.aging_title') }}</h3>
          <span class="text-xs text-neutral-400">{{ t('costs.aging_hint') }}</span>
        </div>
        <AgingChart :rows="agingRows" :format="(v, c) => formatMoney(v, c)" />
        <div class="mt-4 overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="text-left px-3 py-1.5 font-medium">{{ t('common.currency') }}</th>
                <th class="text-right px-3 py-1.5 font-medium">{{ t('costs.aging_current') }}</th>
                <th class="text-right px-3 py-1.5 font-medium">{{ t('costs.aging_1_30') }}</th>
                <th class="text-right px-3 py-1.5 font-medium">{{ t('costs.aging_31_60') }}</th>
                <th class="text-right px-3 py-1.5 font-medium">{{ t('costs.aging_61_90') }}</th>
                <th class="text-right px-3 py-1.5 font-medium text-danger-500">{{ t('costs.aging_90_plus') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in agingRows" :key="`ag-${r.currency}`">
                <td class="px-3 py-1.5 font-mono">{{ r.currency }}</td>
                <td class="px-3 py-1.5 text-right font-mono">
                  <div>{{ formatMoney(r.current, r.currency) }}</div>
                  <div class="text-neutral-400">{{ r.current_n }}×</div>
                </td>
                <td class="px-3 py-1.5 text-right font-mono">
                  <div>{{ formatMoney(r.b1_30, r.currency) }}</div>
                  <div class="text-neutral-400">{{ r.b1_30_n }}×</div>
                </td>
                <td class="px-3 py-1.5 text-right font-mono">
                  <div>{{ formatMoney(r.b31_60, r.currency) }}</div>
                  <div class="text-neutral-400">{{ r.b31_60_n }}×</div>
                </td>
                <td class="px-3 py-1.5 text-right font-mono">
                  <div>{{ formatMoney(r.b61_90, r.currency) }}</div>
                  <div class="text-neutral-400">{{ r.b61_90_n }}×</div>
                </td>
                <td class="px-3 py-1.5 text-right font-mono"
                    :class="r.b90_plus > 0 ? 'text-danger-500 font-semibold' : ''">
                  <div>{{ formatMoney(r.b90_plus, r.currency) }}</div>
                  <div class="text-neutral-400">{{ r.b90_plus_n }}×</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Distribuce velikosti přijatých faktur -->
      <div v-if="summary.invoice_size_histogram.total > 0"
        class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('costs.invoice_size_title') }}</h3>
          <span class="text-xs text-neutral-400">{{ t('costs.invoice_size_hint', { n: summary.invoice_size_histogram.total }) }}</span>
        </div>
        <InvoiceSizeChart :buckets="summary.invoice_size_histogram.buckets" />
      </div>
    </div>
  </div>
</template>

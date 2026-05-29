<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { crmApi, type CrmOverview, type CrmMonthlyRow, type TopClient, type TopVendor,
  type AgingBucket, type DsoResult, type PunctualityResult, type ConcentrationResult,
  type VendorConcentrationResult,
  type ExpenseCategoryRow, type ChurnRiskClient,
  type ActionItemsResult, type CashFlowResult, type LateRiskClient,
  type ReminderEffectiveness, type PaymentTimeHistogram, type CrmYearlyRow } from '@/api/crm'
import { formatMoney } from '@/composables/useFormat'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const overview = ref<CrmOverview | null>(null)
const monthly = ref<CrmMonthlyRow[]>([])
const yearly = ref<CrmYearlyRow[]>([])
const topClients = ref<TopClient[]>([])
const topVendors = ref<TopVendor[]>([])
const agingRecv = ref<AgingBucket[]>([])
const agingPay  = ref<AgingBucket[]>([])
const dso = ref<DsoResult | null>(null)
const punctuality = ref<PunctualityResult | null>(null)
const concentration = ref<ConcentrationResult | null>(null)
const vendorConcentration = ref<VendorConcentrationResult | null>(null)
const dpo = ref<DsoResult | null>(null)
const expenses = ref<ExpenseCategoryRow[]>([])
const churn = ref<ChurnRiskClient[]>([])
const actionItems = ref<ActionItemsResult | null>(null)
const cashFlow = ref<CashFlowResult | null>(null)
const lateRisk = ref<LateRiskClient[]>([])
const reminderEff = ref<ReminderEffectiveness | null>(null)
const paymentHist = ref<PaymentTimeHistogram | null>(null)
const loading = ref(true)
const recomputing = ref(false)
const openMenuIdx = ref<number | null>(null)

function toggleMenu(idx: number) {
  openMenuIdx.value = openMenuIdx.value === idx ? null : idx
}

async function dismissItem(itemType: string, mode: 'day' | 'week' | 'forever' | 'historical') {
  try {
    await crmApi.dismissActionItem(itemType, mode)
    openMenuIdx.value = null
    actionItems.value = await crmApi.actionItems()
    toast.success(t('crm.action_items.dismissed'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function restoreAllDismissed() {
  try {
    const r = await crmApi.restoreAllActionItems()
    actionItems.value = await crmApi.actionItems()
    toast.success(t('crm.action_items.restored_n', { n: r.restored }))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

// Filters
const periodMonths = ref(12)
const currencyFilter = ref<string>('')

const availableCurrencies = computed(() => overview.value?.currencies || [])

// Auto-select default currency = first one (typicky CZK)
watch(availableCurrencies, (curs) => {
  if (curs.length > 0 && !currencyFilter.value) {
    currencyFilter.value = curs[0]
  }
})

async function loadAll() {
  loading.value = true
  try {
    const cur = currencyFilter.value || undefined
    const [ov, mo, yr, tc, tv, ar, ap, d, p, conc, vc, dp, exp, ch, ai, cf, lr, re, ph] = await Promise.all([
      crmApi.overview(),
      crmApi.monthly(periodMonths.value, cur),
      crmApi.yearly(cur),
      crmApi.topClients(periodMonths.value, 10, cur),
      crmApi.topVendors(periodMonths.value, 10, cur),
      crmApi.agingReceivables(),
      crmApi.agingPayables(),
      crmApi.dso(periodMonths.value),
      crmApi.punctuality(periodMonths.value),
      crmApi.concentration(periodMonths.value, cur),
      crmApi.vendorConcentration(periodMonths.value, cur),
      crmApi.dpo(periodMonths.value),
      crmApi.expenseBreakdown(periodMonths.value, cur),
      crmApi.churnRisk(60, 10),
      crmApi.actionItems(),
      crmApi.cashFlowForecast(4, cur || 'CZK'),
      crmApi.lateRisk(10),
      crmApi.reminderEffectiveness(periodMonths.value),
      crmApi.paymentTimeHistogram(periodMonths.value),
    ])
    overview.value = ov
    monthly.value = mo
    yearly.value = yr
    topClients.value = tc
    topVendors.value = tv
    agingRecv.value = ar
    agingPay.value  = ap
    dso.value = d
    punctuality.value = p
    concentration.value = conc
    vendorConcentration.value = vc
    dpo.value = dp
    expenses.value = exp
    churn.value = ch
    actionItems.value = ai
    cashFlow.value = cf
    lateRisk.value = lr
    reminderEff.value = re
    paymentHist.value = ph
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

async function recompute() {
  if (recomputing.value) return
  recomputing.value = true
  try {
    const r = await crmApi.recompute()
    toast.success(t('crm.recompute_done', { ms: r.elapsed_ms }))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    recomputing.value = false
  }
}

// Derived: filter overview na vybranou měnu
const currentMonthKpi = computed(() => {
  if (!overview.value) return null
  return overview.value.current_month.find(k => k.currency === currencyFilter.value) || overview.value.current_month[0] || null
})
const lastMonthKpi = computed(() => {
  if (!overview.value) return null
  return overview.value.last_month.find(k => k.currency === currencyFilter.value) || overview.value.last_month[0] || null
})
const ytdKpi = computed(() => {
  if (!overview.value) return null
  return overview.value.ytd.find(k => k.currency === currencyFilter.value) || overview.value.ytd[0] || null
})

// Trend % vs last month
function trendPct(current: number, last: number): number {
  if (last === 0) return current > 0 ? 100 : 0
  return Math.round(((current - last) / Math.abs(last)) * 100)
}

// Chart max — pro proportional bar widths
const chartMaxValue = computed(() => {
  let max = 0
  for (const m of monthly.value) {
    if (m.revenue > max) max = m.revenue
    if (m.costs > max) max = m.costs
  }
  return max
})

function barWidthPct(value: number): number {
  if (chartMaxValue.value === 0) return 0
  return Math.round((value / chartMaxValue.value) * 100)
}

// Aging buckets pro vybranou měnu
const agingForCurrency = computed(() =>
  agingRecv.value.filter(b => b.currency === currencyFilter.value)
)
const agingPayForCurrency = computed(() =>
  agingPay.value.filter(b => b.currency === currencyFilter.value)
)
const agingTotal = computed(() => agingForCurrency.value.reduce((s, b) => s + b.total, 0))
const agingPayTotal = computed(() => agingPayForCurrency.value.reduce((s, b) => s + b.total, 0))

function agingPct(bucket: AgingBucket, total: number): number {
  if (total === 0) return 0
  return Math.round((bucket.total / total) * 100)
}

function agingBucketColor(bucket: string): string {
  switch (bucket) {
    case 'not_due':         return 'bg-success-500'
    case 'overdue_30':      return 'bg-warning-400'
    case 'overdue_60':      return 'bg-warning-500'
    case 'overdue_90':      return 'bg-danger-400'
    case 'overdue_90_plus': return 'bg-danger-600'
    default:                return 'bg-neutral-400'
  }
}

function riskColor(level: string): string {
  return level === 'high' ? 'text-danger-500' : level === 'medium' ? 'text-warning-600' : 'text-success-600'
}

function formatMonthLabel(period: string): string {
  // "2026-05" → "kvě 26" (cz) nebo "May 26"
  const [y, m] = period.split('-')
  if (!y || !m) return period
  const date = new Date(Number(y), Number(m) - 1, 1)
  return date.toLocaleDateString('cs-CZ', { month: 'short', year: '2-digit' })
}

// Pracovní kapitálový cyklus = DSO − DPO. Kladné = financuješ provoz (inkasuješ pomaleji než platíš),
// záporné = dodavatelé tě financují (platíš později, než ti platí klienti).
const wcCycle = computed<number | null>(() => {
  if (!dso.value || !dpo.value) return null
  if (dso.value.sample_size === 0 && dpo.value.sample_size === 0) return null
  return Math.round((dso.value.avg_days - dpo.value.avg_days) * 10) / 10
})

/** Krátký štítek zvoleného analytického období pro hlavičky sekcí (např. "12 m"). */
const periodChip = computed(() => t('crm.period_chip', { n: periodMonths.value }))

watch([periodMonths, currencyFilter], () => {
  if (currencyFilter.value) loadAll()
})

onMounted(loadAll)
</script>

<template>
  <div>
    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('crm.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('crm.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <select v-if="availableCurrencies.length > 1" v-model="currencyFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="c in availableCurrencies" :key="c" :value="c">{{ c }}</option>
        </select>
        <button
          v-if="auth.user?.role === 'admin'"
          type="button" @click="recompute" :disabled="recomputing"
          :title="t('crm.recompute_hint')"
          class="cursor-pointer h-9 px-3 border border-neutral-300 hover:bg-neutral-50 text-sm rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/>
          </svg>
          {{ recomputing ? '…' : t('crm.recompute') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <div v-else-if="!overview || overview.currencies.length === 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center">
      <p class="text-neutral-600 mb-2">{{ t('crm.no_data') }}</p>
      <p class="text-sm text-neutral-500 mb-4">{{ t('crm.no_data_hint') }}</p>
      <button v-if="auth.user?.role === 'admin'" type="button" @click="recompute" :disabled="recomputing"
        class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
        {{ t('crm.recompute_now') }}
      </button>
    </div>

    <div v-else class="space-y-4">
      <!-- ═══ Action items widget (daily TODO) ═══ -->
      <div v-if="actionItems && actionItems.total > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between bg-gradient-to-r from-primary-50 to-white rounded-t-lg">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-primary-700">
            ⚡ {{ t('crm.action_items.title') }}
            <span class="ml-2 px-1.5 py-0.5 bg-primary-600 text-white rounded text-xs">{{ actionItems.total }}</span>
          </h3>
          <button v-if="actionItems.dismissed_count > 0 && auth.canWrite" type="button" @click="restoreAllDismissed"
            class="text-xs text-neutral-500 hover:text-primary-600 underline decoration-dotted">
            {{ t('crm.action_items.restore_n', { n: actionItems.dismissed_count }) }}
          </button>
        </header>
        <div class="divide-y divide-neutral-100">
          <div v-for="(item, idx) in actionItems.items" :key="idx"
            class="relative flex items-center justify-between px-5 py-3 hover:bg-neutral-50">
            <RouterLink :to="item.link" class="flex items-center gap-3 flex-1 min-w-0">
              <span :class="['inline-block w-2.5 h-2.5 rounded-full shrink-0',
                item.severity === 'high' ? 'bg-danger-500' :
                item.severity === 'medium' ? 'bg-warning-500' : 'bg-neutral-400']"></span>
              <div class="min-w-0">
                <div class="text-sm font-medium text-neutral-700">{{ item.title }}</div>
                <div class="text-xs text-neutral-500 mt-0.5">{{ item.hint }}</div>
              </div>
            </RouterLink>
            <div class="flex items-center gap-1 ml-3 shrink-0">
              <RouterLink :to="item.link" class="text-neutral-400 hover:text-neutral-600 p-1" :title="t('crm.action_items.go_to')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
              </RouterLink>
              <button v-if="auth.canWrite" type="button" @click.stop="toggleMenu(idx)"
                class="text-neutral-400 hover:text-neutral-700 p-1 rounded hover:bg-neutral-100"
                :title="t('crm.action_items.dismiss')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm0 7a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm0 7a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/></svg>
              </button>
              <div v-if="(openMenuIdx === idx) && auth.canWrite"
                class="absolute right-3 top-12 z-20 bg-surface border border-neutral-200 rounded-md shadow-lg py-1 w-[280px]"
                @click.stop>
                <div class="px-3 py-1.5 text-xs uppercase tracking-wide text-neutral-500 font-semibold border-b border-neutral-100">
                  {{ t('crm.action_items.dismiss_title') }}
                </div>
                <button type="button" @click="dismissItem(item.type, 'day')"
                  class="w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
                  {{ t('crm.action_items.dismiss_day') }}
                  <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_day_hint') }}</div>
                </button>
                <button type="button" @click="dismissItem(item.type, 'week')"
                  class="w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
                  {{ t('crm.action_items.dismiss_week') }}
                  <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_week_hint') }}</div>
                </button>
                <button type="button" @click="dismissItem(item.type, 'historical')"
                  class="w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
                  {{ t('crm.action_items.dismiss_historical') }}
                  <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_historical_hint') }}</div>
                </button>
                <button type="button" @click="dismissItem(item.type, 'forever')"
                  class="w-full text-left px-3 py-2 text-sm hover:bg-neutral-50 text-danger-600 border-t border-neutral-100">
                  {{ t('crm.action_items.dismiss_forever') }}
                  <div class="text-xs text-neutral-400">{{ t('crm.action_items.dismiss_forever_hint') }}</div>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ Standalone restore hint — pro případ že total=0 ale jsou skryté ═══ -->
      <div v-else-if="actionItems && actionItems.dismissed_count > 0"
        class="bg-neutral-50 border border-neutral-200 rounded-lg px-4 py-2 flex items-center justify-between text-sm">
        <span class="text-neutral-500">
          {{ t('crm.action_items.all_clear_n_hidden', { n: actionItems.dismissed_count }) }}
        </span>
        <button v-if="auth.canWrite" type="button" @click="restoreAllDismissed"
          class="text-xs text-primary-600 hover:text-primary-700 underline decoration-dotted">
          {{ t('crm.action_items.restore_n', { n: actionItems.dismissed_count }) }}
        </button>
      </div>

      <!-- ═══ Headline KPI — aktuální měsíc + YTD (nezávislé na zvoleném období) ═══ -->
      <div>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-600 mb-2">{{ t('crm.kpi_section') }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Revenue -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('crm.kpi.revenue') }}</span>
            <svg class="w-5 h-5 text-success-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2m2 4h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm7-5a2 2 0 1 1-4 0 2 2 0 0 1 4 0z"/>
            </svg>
          </div>
          <div class="text-2xl font-bold text-neutral-900 font-mono">
            {{ formatMoney(currentMonthKpi?.revenue || 0, currencyFilter) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.kpi.this_month') }}
            <span v-if="lastMonthKpi" class="ml-2"
              :class="trendPct(currentMonthKpi?.revenue || 0, lastMonthKpi.revenue) >= 0 ? 'text-success-600' : 'text-danger-500'">
              {{ trendPct(currentMonthKpi?.revenue || 0, lastMonthKpi.revenue) >= 0 ? '▲' : '▼' }}
              {{ Math.abs(trendPct(currentMonthKpi?.revenue || 0, lastMonthKpi.revenue)) }}%
            </span>
          </div>
          <div class="text-xs text-neutral-400 mt-3 pt-2 border-t border-neutral-100">
            <div>YTD: <span class="font-mono">{{ formatMoney(ytdKpi?.revenue || 0, currencyFilter) }}</span></div>
            <div class="mt-0.5">{{ currentMonthKpi?.invoice_count || 0 }} {{ t('crm.kpi.invoices') }}</div>
          </div>
        </div>

        <!-- Costs -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('crm.kpi.costs') }}</span>
            <svg class="w-5 h-5 text-danger-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"/>
            </svg>
          </div>
          <div class="text-2xl font-bold text-neutral-900 font-mono">
            {{ formatMoney(currentMonthKpi?.costs || 0, currencyFilter) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.kpi.this_month') }}
            <span v-if="lastMonthKpi" class="ml-2"
              :class="trendPct(currentMonthKpi?.costs || 0, lastMonthKpi.costs) >= 0 ? 'text-danger-500' : 'text-success-600'">
              {{ trendPct(currentMonthKpi?.costs || 0, lastMonthKpi.costs) >= 0 ? '▲' : '▼' }}
              {{ Math.abs(trendPct(currentMonthKpi?.costs || 0, lastMonthKpi.costs)) }}%
            </span>
          </div>
          <div class="text-xs text-neutral-400 mt-3 pt-2 border-t border-neutral-100">
            <div>YTD: <span class="font-mono">{{ formatMoney(ytdKpi?.costs || 0, currencyFilter) }}</span></div>
            <div class="mt-0.5">{{ currentMonthKpi?.purchase_count || 0 }} {{ t('crm.kpi.purchases') }}</div>
          </div>
        </div>

        <!-- Profit -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('crm.kpi.profit') }}</span>
            <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
            </svg>
          </div>
          <div class="text-2xl font-bold font-mono"
            :class="(currentMonthKpi?.profit || 0) >= 0 ? 'text-success-600' : 'text-danger-500'">
            {{ formatMoney(currentMonthKpi?.profit || 0, currencyFilter) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.kpi.this_month') }}
            <span v-if="currentMonthKpi && currentMonthKpi.revenue > 0" class="ml-2">
              · {{ Math.round((currentMonthKpi.profit / currentMonthKpi.revenue) * 100) }}% {{ t('crm.kpi.margin') }}
            </span>
          </div>
          <div class="text-xs text-neutral-400 mt-3 pt-2 border-t border-neutral-100">
            <div>YTD: <span class="font-mono">{{ formatMoney(ytdKpi?.profit || 0, currencyFilter) }}</span></div>
          </div>
        </div>
        </div>
      </div>

      <!-- ═══ Analytické období — řídí žebříčky, grafy a metriky NÍŽE ═══ -->
      <div class="flex items-center justify-between gap-3 flex-wrap border-t border-neutral-200 pt-4">
        <div>
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-600">{{ t('crm.analytics_section') }}</h2>
          <p class="text-xs text-neutral-400 mt-0.5">{{ t('crm.analytics_section_hint') }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm shrink-0">
          <span class="text-neutral-500">{{ t('crm.period_label') }}</span>
          <select v-model.number="periodMonths" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option :value="3">{{ t('crm.last_n_months', { n: 3 }) }}</option>
            <option :value="6">{{ t('crm.last_n_months', { n: 6 }) }}</option>
            <option :value="12">{{ t('crm.last_n_months', { n: 12 }) }}</option>
            <option :value="24">{{ t('crm.last_n_months', { n: 24 }) }}</option>
          </select>
        </label>
      </div>

      <!-- ═══ Monthly trend chart (HTML/CSS bars — no chart.js dependency) ═══ -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
            {{ t('crm.monthly_trend') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
          </h3>
          <div class="flex items-center gap-3 text-xs">
            <span class="flex items-center gap-1">
              <span class="inline-block w-3 h-3 rounded-sm bg-success-500"></span>
              {{ t('crm.kpi.revenue') }}
            </span>
            <span class="flex items-center gap-1">
              <span class="inline-block w-3 h-3 rounded-sm bg-danger-500"></span>
              {{ t('crm.kpi.costs') }}
            </span>
          </div>
        </header>
        <div v-if="monthly.length === 0" class="p-8 text-center text-neutral-500 text-sm">
          {{ t('crm.no_chart_data') }}
        </div>
        <div v-else class="p-4 space-y-2">
          <div v-for="m in monthly" :key="m.period + m.currency" class="grid grid-cols-[60px_1fr_120px] gap-2 items-center text-xs">
            <div class="text-neutral-600 font-medium">{{ formatMonthLabel(m.period) }}</div>
            <div class="space-y-1">
              <div class="flex items-center gap-2">
                <div class="bg-success-500 h-3 rounded-sm" :style="{ width: barWidthPct(m.revenue) + '%' }"></div>
                <span class="font-mono text-neutral-700">{{ formatMoney(m.revenue, m.currency) }}</span>
              </div>
              <div class="flex items-center gap-2">
                <div class="bg-danger-500 h-3 rounded-sm" :style="{ width: barWidthPct(m.costs) + '%' }"></div>
                <span class="font-mono text-neutral-700">{{ formatMoney(m.costs, m.currency) }}</span>
              </div>
            </div>
            <div class="text-right font-mono"
              :class="m.profit >= 0 ? 'text-success-600' : 'text-danger-500'">
              {{ m.profit >= 0 ? '+' : '' }}{{ formatMoney(m.profit, m.currency) }}
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ Top klienti + Top vendoři side by side ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Top clients -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.top_clients') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
            </h3>
          </header>
          <div v-if="topClients.length === 0" class="p-8 text-center text-neutral-500 text-sm">
            {{ t('crm.no_data') }}
          </div>
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="c in topClients" :key="c.client_id" class="hover:bg-neutral-50">
                <td class="px-5 py-2.5">
                  <RouterLink :to="`/clients/${c.client_id}`" class="font-medium text-primary-700 hover:underline">
                    {{ c.company_name }}
                  </RouterLink>
                  <span v-if="c.currencies && c.currencies !== 'CZK'" class="ml-1.5 text-xs text-neutral-400">({{ c.currencies }})</span>
                  <div class="text-xs text-neutral-500 mt-0.5">{{ c.invoice_count }} {{ t('crm.kpi.invoices') }}</div>
                </td>
                <td class="px-3 py-2.5 text-right font-mono text-neutral-900">
                  {{ formatMoney(c.revenue, 'CZK') }}
                </td>
                <td class="px-5 py-2.5 text-right text-xs text-neutral-500 font-mono">
                  {{ c.percent_share.toFixed(1) }}%
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Top vendors -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.top_vendors') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
            </h3>
          </header>
          <div v-if="topVendors.length === 0" class="p-8 text-center text-neutral-500 text-sm">
            {{ t('crm.no_data_vendors') }}
          </div>
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="v in topVendors" :key="v.vendor_id" class="hover:bg-neutral-50">
                <td class="px-5 py-2.5">
                  <RouterLink :to="`/clients/${v.vendor_id}`" class="font-medium text-primary-700 hover:underline">
                    {{ v.company_name }}
                  </RouterLink>
                  <span v-if="v.currencies && v.currencies !== 'CZK'" class="ml-1.5 text-xs text-neutral-400">({{ v.currencies }})</span>
                  <div class="text-xs text-neutral-500 mt-0.5">{{ v.purchase_count }} {{ t('crm.kpi.purchases') }}</div>
                </td>
                <td class="px-3 py-2.5 text-right font-mono text-neutral-900">
                  {{ formatMoney(v.costs, 'CZK') }}
                </td>
                <td class="px-5 py-2.5 text-right text-xs text-neutral-500 font-mono">
                  {{ v.percent_share.toFixed(1) }}%
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ═══ Aging buckets (pohledávky + závazky) ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Receivables -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.aging.receivables_title') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ t('crm.snapshot_now') }})</span>
            </h3>
            <span class="text-sm font-mono text-neutral-700">
              {{ formatMoney(agingTotal, currencyFilter) }}
            </span>
          </header>
          <div v-if="agingForCurrency.length === 0" class="p-6 text-center text-neutral-500 text-sm">
            {{ t('crm.aging.no_open') }}
          </div>
          <div v-else class="p-4 space-y-2">
            <div v-for="b in agingForCurrency" :key="b.bucket" class="grid grid-cols-[100px_1fr_120px] gap-2 items-center text-xs">
              <div class="text-neutral-700 font-medium">{{ t('crm.aging.bucket.' + b.bucket) }}</div>
              <div class="flex items-center gap-2">
                <div :class="['h-3 rounded-sm', agingBucketColor(b.bucket)]"
                  :style="{ width: agingPct(b, agingTotal) + '%' }"></div>
                <span class="text-neutral-500">{{ b.count }} faktur</span>
              </div>
              <div class="text-right font-mono text-neutral-700">
                {{ formatMoney(b.total, b.currency) }}
              </div>
            </div>
          </div>
        </div>

        <!-- Payables -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.aging.payables_title') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ t('crm.snapshot_now') }})</span>
            </h3>
            <span class="text-sm font-mono text-neutral-700">
              {{ formatMoney(agingPayTotal, currencyFilter) }}
            </span>
          </header>
          <div v-if="agingPayForCurrency.length === 0" class="p-6 text-center text-neutral-500 text-sm">
            {{ t('crm.aging.no_pay') }}
          </div>
          <div v-else class="p-4 space-y-2">
            <div v-for="b in agingPayForCurrency" :key="b.bucket" class="grid grid-cols-[100px_1fr_120px] gap-2 items-center text-xs">
              <div class="text-neutral-700 font-medium">{{ t('crm.aging.bucket.' + b.bucket) }}</div>
              <div class="flex items-center gap-2">
                <div :class="['h-3 rounded-sm', agingBucketColor(b.bucket)]"
                  :style="{ width: agingPct(b, agingPayTotal) + '%' }"></div>
                <span class="text-neutral-500">{{ b.count }} faktur</span>
              </div>
              <div class="text-right font-mono text-neutral-700">
                {{ formatMoney(b.total, b.currency) }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ Health metrics row: DSO + Punctuality + Concentration ═══ -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- DSO -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('crm.dso.title') }} <span class="normal-case font-normal text-neutral-400">· {{ periodChip }}</span>
          </div>
          <div class="text-2xl font-bold font-mono text-neutral-900">
            {{ dso?.avg_days ?? '—' }}<span class="text-base text-neutral-500 ml-1">{{ t('crm.dso.days') }}</span>
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('crm.dso.hint', { n: dso?.sample_size || 0 }) }}</div>
        </div>

        <!-- Punctuality -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('crm.punctuality.title') }} <span class="normal-case font-normal text-neutral-400">· {{ periodChip }}</span>
          </div>
          <div class="text-2xl font-bold font-mono"
            :class="(punctuality?.on_time_pct ?? 0) >= 80 ? 'text-success-600' : (punctuality?.on_time_pct ?? 0) >= 50 ? 'text-warning-600' : 'text-danger-500'">
            {{ punctuality?.on_time_pct ?? 0 }}%
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.punctuality.detail', { on_time: punctuality?.on_time || 0, late: punctuality?.late || 0 }) }}
          </div>
        </div>

        <!-- Concentration risk -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('crm.concentration.title') }} <span class="normal-case font-normal text-neutral-400">· {{ periodChip }}</span>
          </div>
          <div class="text-2xl font-bold font-mono" :class="riskColor(concentration?.risk_level || 'low')">
            {{ concentration?.top1_share ?? 0 }}%
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.concentration.top1', { pct: concentration?.top1_share ?? 0 }) }}
            <span class="ml-2">· {{ t('crm.concentration.pareto', { n: concentration?.pareto_80_count ?? 0 }) }}</span>
          </div>
          <div class="text-xs mt-2 pt-2 border-t border-neutral-100" :class="riskColor(concentration?.risk_level || 'low')">
            {{ t('crm.concentration.risk_' + (concentration?.risk_level || 'low')) }}
          </div>
        </div>
      </div>

      <!-- ═══ Health metrics row 2: DPO + Vendor concentration + Working capital cycle ═══ -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- DPO -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('crm.dpo.title') }} <span class="normal-case font-normal text-neutral-400">· {{ periodChip }}</span>
          </div>
          <div class="text-2xl font-bold font-mono text-neutral-900">
            {{ dpo?.avg_days ?? '—' }}<span class="text-base text-neutral-500 ml-1">{{ t('crm.dso.days') }}</span>
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('crm.dpo.hint', { n: dpo?.sample_size || 0 }) }}</div>
        </div>

        <!-- Vendor concentration risk -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('crm.vendor_concentration.title') }} <span class="normal-case font-normal text-neutral-400">· {{ periodChip }}</span>
          </div>
          <div class="text-2xl font-bold font-mono" :class="riskColor(vendorConcentration?.risk_level || 'low')">
            {{ vendorConcentration?.top1_share ?? 0 }}%
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.vendor_concentration.top1', { pct: vendorConcentration?.top1_share ?? 0 }) }}
            <span class="ml-2">· {{ t('crm.vendor_concentration.pareto', { n: vendorConcentration?.pareto_80_count ?? 0 }) }}</span>
          </div>
          <div class="text-xs mt-2 pt-2 border-t border-neutral-100" :class="riskColor(vendorConcentration?.risk_level || 'low')">
            {{ t('crm.vendor_concentration.risk_' + (vendorConcentration?.risk_level || 'low')) }}
          </div>
        </div>

        <!-- Working capital cycle (DSO − DPO) -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('crm.wc_cycle.title') }}
          </div>
          <div class="text-2xl font-bold font-mono"
            :class="wcCycle === null ? 'text-neutral-400' : wcCycle > 0 ? 'text-warning-600' : 'text-success-600'">
            <template v-if="wcCycle !== null">{{ wcCycle > 0 ? '+' : '' }}{{ wcCycle }}<span class="text-base text-neutral-500 ml-1">{{ t('crm.dso.days') }}</span></template>
            <template v-else>—</template>
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('crm.wc_cycle.formula') }}</div>
          <div v-if="wcCycle !== null" class="text-xs mt-2 pt-2 border-t border-neutral-100"
            :class="wcCycle > 0 ? 'text-warning-600' : 'text-success-600'">
            {{ wcCycle > 0 ? t('crm.wc_cycle.positive') : t('crm.wc_cycle.negative') }}
          </div>
        </div>
      </div>

      <!-- ═══ Expense breakdown + Churn risk side-by-side ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Expense breakdown -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.expense_breakdown.title') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
            </h3>
          </header>
          <div v-if="expenses.length === 0" class="p-6 text-center text-neutral-500 text-sm">
            {{ t('crm.expense_breakdown.empty') }}
          </div>
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="e in expenses" :key="(e.category_id ?? 0) + '-' + (e.code ?? '')" class="hover:bg-neutral-50">
                <td class="px-5 py-2">
                  <div class="font-medium text-neutral-900">
                    {{ e.label || t('crm.expense_breakdown.uncategorized') }}
                  </div>
                  <div class="text-xs text-neutral-500">{{ e.count }} {{ t('crm.kpi.purchases') }}</div>
                </td>
                <td class="px-3 py-2">
                  <div class="w-full h-2 bg-neutral-100 rounded">
                    <div class="h-full bg-warning-500 rounded" :style="{ width: e.percent + '%' }"></div>
                  </div>
                </td>
                <td class="px-3 py-2 text-right font-mono text-neutral-900">{{ formatMoney(e.total, currencyFilter) }}</td>
                <td class="px-5 py-2 text-right text-xs text-neutral-500 font-mono w-12">{{ e.percent.toFixed(1) }}%</td>
              </tr>
            </tbody>
          </table>
          <div v-if="expenses.length > 0 && expenses[0].category_id === null"
            class="px-5 py-2 text-xs text-warning-600 bg-warning-50 border-t border-warning-500/40">
            💡 {{ t('crm.expense_breakdown.uncategorized_hint') }}
          </div>
        </div>

        <!-- Churn risk -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.churn.title') }}
            </h3>
          </header>
          <div v-if="churn.length === 0" class="p-6 text-center text-neutral-500 text-sm">
            {{ t('crm.churn.empty') }}
          </div>
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="c in churn" :key="c.client_id + c.currency" class="hover:bg-neutral-50">
                <td class="px-5 py-2">
                  <RouterLink :to="`/clients/${c.client_id}`" class="font-medium text-primary-700 hover:underline">
                    {{ c.company_name }}
                  </RouterLink>
                  <div class="text-xs text-neutral-500">{{ t('crm.churn.last', { date: c.last_invoice_date }) }}</div>
                </td>
                <td class="px-3 py-2 text-right">
                  <span class="text-sm font-mono"
                    :class="c.days_since > 180 ? 'text-danger-500' : c.days_since > 90 ? 'text-warning-600' : 'text-neutral-700'">
                    {{ c.days_since }}d
                  </span>
                </td>
                <td class="px-5 py-2 text-right text-xs text-neutral-500 font-mono">
                  {{ formatMoney(c.total_revenue, c.currency) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ═══ Náklady po rocích + Náklady po měsících (obdoba Stats) ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Náklady po rocích -->
        <div v-if="yearly.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              📅 {{ t('crm.costs_by_year_table') }}
            </h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium">{{ t('common.year') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('common.costs') }}</th>
                  <th class="text-right px-4 py-2 font-medium whitespace-nowrap">{{ t('crm.purchase_invoices_short') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="r in yearly.filter(y => y.costs > 0 || y.purchase_count > 0)" :key="`cy-${r.year}-${r.currency}`">
                  <td class="px-4 py-2 font-medium">{{ r.year }}</td>
                  <td class="px-4 py-2 text-right font-mono text-danger-500">{{ formatMoney(r.costs, r.currency) }}</td>
                  <td class="px-4 py-2 text-right text-xs text-neutral-500">{{ r.purchase_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Náklady po měsících (posledních N podle periodMonths) -->
        <div v-if="monthly.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              📊 {{ t('crm.costs_by_month_table') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
            </h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium">{{ t('common.month') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('common.costs') }}</th>
                  <th class="text-right px-4 py-2 font-medium whitespace-nowrap">{{ t('crm.purchase_invoices_short') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in [...monthly].filter(m => m.costs > 0 || m.purchase_count > 0).reverse()" :key="`cm-${row.period}-${row.currency}`">
                  <td class="px-4 py-2 font-mono text-neutral-700">{{ row.period }}</td>
                  <td class="px-4 py-2 text-right font-mono text-danger-500">{{ formatMoney(row.costs, row.currency) }}</td>
                  <td class="px-4 py-2 text-right text-xs text-neutral-500">{{ row.purchase_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ═══ Cash flow forecast (4 týdny) ═══ -->
      <div v-if="cashFlow && cashFlow.weeks.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
            💰 {{ t('crm.cash_flow.title') }} ({{ cashFlow.currency }})
          </h3>
          <div class="text-xs text-neutral-500">{{ t('crm.cash_flow.next_n_weeks', { n: cashFlow.weeks.length }) }}</div>
        </header>
        <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[560px]">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2">{{ t('crm.cash_flow.week') }}</th>
              <th class="text-right px-3 py-2">{{ t('crm.cash_flow.in') }}</th>
              <th class="text-right px-3 py-2">{{ t('crm.cash_flow.out') }}</th>
              <th class="text-right px-3 py-2">{{ t('crm.cash_flow.net') }}</th>
              <th class="text-right px-5 py-2">{{ t('crm.cash_flow.running') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(w, i) in cashFlow.weeks" :key="i" class="hover:bg-neutral-50">
              <td class="px-5 py-2 text-xs">
                <span class="font-medium">{{ new Date(w.week_start).toLocaleDateString() }}</span>
                <span class="text-neutral-400"> – {{ new Date(w.week_end).toLocaleDateString() }}</span>
              </td>
              <td class="px-3 py-2 text-right font-mono text-success-600">{{ formatMoney(w.in, cashFlow.currency) }}</td>
              <td class="px-3 py-2 text-right font-mono text-danger-500">−{{ formatMoney(w.out, cashFlow.currency) }}</td>
              <td class="px-3 py-2 text-right font-mono" :class="w.net >= 0 ? 'text-success-600' : 'text-danger-500'">
                {{ w.net >= 0 ? '+' : '' }}{{ formatMoney(w.net, cashFlow.currency) }}
              </td>
              <td class="px-5 py-2 text-right font-mono font-medium" :class="w.running >= 0 ? 'text-neutral-700' : 'text-danger-500'">
                {{ formatMoney(w.running, cashFlow.currency) }}
              </td>
            </tr>
          </tbody>
          <tfoot class="bg-neutral-50">
            <tr>
              <td class="px-5 py-2 text-xs font-medium">{{ t('crm.cash_flow.total') }}</td>
              <td class="px-3 py-2 text-right font-mono text-success-600 font-medium">{{ formatMoney(cashFlow.total_in, cashFlow.currency) }}</td>
              <td class="px-3 py-2 text-right font-mono text-danger-500 font-medium">−{{ formatMoney(cashFlow.total_out, cashFlow.currency) }}</td>
              <td colspan="2" class="px-5 py-2 text-right font-mono font-bold" :class="cashFlow.total_net >= 0 ? 'text-success-600' : 'text-danger-500'">
                {{ cashFlow.total_net >= 0 ? '+' : '' }}{{ formatMoney(cashFlow.total_net, cashFlow.currency) }}
              </td>
            </tr>
          </tfoot>
        </table>
        </div>
      </div>

      <!-- ═══ Late payment risk + Payment time histogram side-by-side ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Late risk -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              ⚠️ {{ t('crm.late_risk.title') }}
            </h3>
          </header>
          <div v-if="lateRisk.length === 0" class="p-6 text-center text-sm text-neutral-400">
            {{ t('crm.late_risk.no_data') }}
          </div>
          <table v-else class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="text-left px-5 py-2">{{ t('crm.late_risk.client') }}</th>
                <th class="text-right px-3 py-2">{{ t('crm.late_risk.late_rate') }}</th>
                <th class="text-right px-3 py-2">{{ t('crm.late_risk.avg_days') }}</th>
                <th class="text-center px-5 py-2">{{ t('crm.late_risk.score') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="c in lateRisk" :key="c.client_id" class="hover:bg-neutral-50">
                <td class="px-5 py-2">
                  <RouterLink :to="`/clients/${c.client_id}`" class="text-sm font-medium hover:text-primary-700 hover:underline">
                    {{ c.company_name }}
                  </RouterLink>
                  <div class="text-xs text-neutral-500">{{ c.late_count }}/{{ c.total_paid }} {{ t('crm.late_risk.late_paid') }}</div>
                </td>
                <td class="px-3 py-2 text-right font-mono text-xs">{{ Math.round(c.late_rate * 100) }}%</td>
                <td class="px-3 py-2 text-right font-mono text-xs">{{ c.avg_days_late.toFixed(1) }} d</td>
                <td class="px-5 py-2 text-center">
                  <span :class="['inline-block px-2 py-0.5 rounded text-xs font-bold',
                    c.risk_level === 'high' ? 'bg-danger-50 text-danger-500' :
                    c.risk_level === 'medium' ? 'bg-warning-50 text-warning-600' : 'bg-success-50 text-success-600']">
                    {{ c.score }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Payment time histogram -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              ⏱️ {{ t('crm.payment_time.title') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
            </h3>
            <div v-if="paymentHist && paymentHist.median_days !== null" class="text-xs text-neutral-500">
              {{ t('crm.payment_time.median') }}: <span class="font-mono font-medium">{{ paymentHist.median_days }} {{ t('crm.payment_time.days') }}</span>
            </div>
          </header>
          <div v-if="!paymentHist || paymentHist.total_invoices === 0" class="p-6 text-center text-sm text-neutral-400">
            {{ t('crm.payment_time.no_data') }}
          </div>
          <div v-else class="p-4 space-y-2">
            <div v-for="b in paymentHist.buckets" :key="b.label" class="text-xs">
              <div class="flex justify-between mb-1">
                <span class="text-neutral-700 font-medium">{{ b.label }}</span>
                <span class="font-mono text-neutral-600">{{ b.count }} ({{ b.percent }}%)</span>
              </div>
              <div class="w-full bg-neutral-100 rounded h-2 overflow-hidden">
                <div class="h-full rounded transition-all" :style="{ width: b.percent + '%' }"
                  :class="b.min >= 31 ? 'bg-danger-400' : b.min >= 15 ? 'bg-warning-400' : 'bg-success-500'"></div>
              </div>
            </div>
            <div class="text-xs text-neutral-500 mt-3 pt-3 border-t border-neutral-100">
              {{ t('crm.payment_time.total') }}: {{ paymentHist.total_invoices }} •
              {{ t('crm.payment_time.p90') }}: {{ paymentHist.p90_days ?? '—' }} {{ t('crm.payment_time.days') }}
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ Reminder effectiveness funnel ═══ -->
      <div v-if="reminderEff && reminderEff.total_paid > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
            📧 {{ t('crm.reminder.title') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
          </h3>
        </header>
        <div class="p-4 grid grid-cols-2 md:grid-cols-5 gap-3 text-center">
          <div>
            <div class="text-2xl font-bold text-success-600">{{ reminderEff.no_reminder }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ t('crm.reminder.no_reminder') }}</div>
          </div>
          <div>
            <div class="text-2xl font-bold text-primary-600">{{ reminderEff.after_first }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ t('crm.reminder.after_first') }}</div>
          </div>
          <div>
            <div class="text-2xl font-bold text-warning-600">{{ reminderEff.after_second }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ t('crm.reminder.after_second') }}</div>
          </div>
          <div>
            <div class="text-2xl font-bold text-danger-500">{{ reminderEff.after_third_plus }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ t('crm.reminder.after_third_plus') }}</div>
          </div>
          <div>
            <div class="text-2xl font-bold text-neutral-400">{{ reminderEff.never_paid }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ t('crm.reminder.never_paid') }}</div>
          </div>
        </div>
        <div class="px-4 pb-3 text-xs text-neutral-500 text-center">
          {{ t('crm.reminder.avg_reminders') }}: <span class="font-mono font-medium">{{ reminderEff.avg_reminders_to_paid }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
import { dashboardApi, type DashboardSummary } from '@/api/dashboard'
import { formatMoney, formatDate } from '@/composables/useFormat'
import SparklineChart from '@/components/charts/SparklineChart.vue'
import TopClientsPieChart from '@/components/charts/TopClientsPieChart.vue'

const router = useRouter()
const auth = useAuthStore()
const isAdmin = computed(() => auth.user?.role === 'admin')

const summary = ref<DashboardSummary | null>(null)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    summary.value = await dashboardApi.summary()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('errors.generic')
  } finally {
    loading.value = false
  }
})

const kpiGridCols = computed(() => {
  if (!summary.value) return 'lg:grid-cols-6'
  const showApprovals = isAdmin.value
    && (summary.value.pending_approvals?.requested ?? 0) > 0
  const currencies = summary.value.kpi?.per_currency?.length ?? 0
  // Revenue tile spans 2 cols (lg:col-span-2), standardní boxy 1 col.
  // Sloty = currencies*2 + 4 standard (Vystaveno/Po splatnosti/Před splatností/Ø) [+ 1 schvalování]
  const slots = currencies * 2 + 4 + (showApprovals ? 1 : 0)
  // Tailwind musí vidět tyto třídy staticky — explicitní mapping.
  return ({
    6: 'lg:grid-cols-6',   // 1 měna: 1×wide + 4×slim, 1 řada
    7: 'lg:grid-cols-7',   // 1 měna + schvalování, 1 řada
    8: 'lg:grid-cols-4',   // 2 měny: 2 řady × 4 (revenue span 2)
    9: 'lg:grid-cols-3',   // 2 měny + schvalování
    10: 'lg:grid-cols-5',  // 3 měny: 2 řady × 5
  } as Record<number, string>)[slots] ?? 'lg:grid-cols-6'
})

const upcomingPerCurrency = computed(() => {
  if (!summary.value) return [] as Array<{ currency: string; total: number }>
  const map = new Map<string, number>()
  for (const i of summary.value.unpaid_upcoming) {
    map.set(i.currency, (map.get(i.currency) ?? 0) + Number(i.amount_to_pay || 0))
  }
  return Array.from(map, ([currency, total]) => ({ currency, total }))
})

const hasAnyData = computed(() => {
  if (!summary.value || !summary.value.kpi) return false
  return (summary.value.kpi.issued_count_ytd ?? 0) > 0
      || (summary.value.overdue?.length ?? 0) > 0
      || (summary.value.unpaid_upcoming?.length ?? 0) > 0
})

function openInvoice(id: number) {
  router.push(`/invoices/${id}`)
}

/**
 * Vrátí 12 posledních měsíců dat pro sparkline daného currency.
 * Bere `revenue_by_month[currency].months` (12 měsíců rolling).
 */
function sparklineFor(currency: string): { labels: string[]; values: number[] } {
  const rev = summary.value?.revenue_by_month.find(r => r.currency === currency)
  if (!rev) return { labels: [], values: [] }
  return {
    labels: rev.months.map(m => {
      const [y, mo] = m.ym.split('-')
      return `${mo}/${y}`
    }),
    values: rev.months.map(m => m.total),
  }
}

// Jen jedna (aktivní) měna → graf vlevo přes 2 řádky a boxy 2×2 vpravo.
const singleCurrency = computed(() => (summary.value?.kpi?.per_currency?.length ?? 0) === 1)

// Mini graf nákladů (přijaté faktury, 12 měsíců, CZK) — místo boxu CRM.
const costsSparkline = computed(() => {
  const arr = summary.value?.purchase_costs_by_month ?? []
  return {
    labels: arr.map(m => { const [y, mo] = m.ym.split('-'); return `${mo}/${y}` }),
    values: arr.map(m => m.total),
  }
})
const hasCostsData = computed(() => (summary.value?.purchase_costs_by_month ?? []).some(m => m.total !== 0))
</script>

<template>
  <div>
    <div class="flex items-start justify-between gap-3 mb-6 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold mb-1">{{ t('dashboard.title') }}</h1>
        <p class="text-sm text-neutral-500">
          {{ t('dashboard.welcome_back', { name: auth.user?.name }) }}
        </p>
      </div>
      <div class="flex gap-2">
        <RouterLink v-if="auth.canWrite" to="/invoices/new"
          class="inline-flex items-center h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md shadow-sm">
          {{ t('invoice.new') }}
        </RouterLink>
        <RouterLink v-if="auth.canWrite" to="/clients/new"
          class="inline-flex items-center h-9 px-4 border border-primary-500/40 bg-surface hover:bg-primary-50 text-primary-700 text-sm font-medium rounded-md">
          {{ t('client.new') }}
        </RouterLink>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('dashboard.loading_data') }}</div>

    <div v-else-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
      {{ error }}
    </div>

    <div v-else-if="!hasAnyData" class="bg-surface border border-neutral-200 rounded-lg p-8 text-center">
      <h2 class="text-lg font-semibold mb-2">{{ t('dashboard.welcome') }}</h2>
      <p class="text-neutral-500 mb-6">{{ t('common.no_data') }}</p>
      <div class="flex justify-center gap-3">
        <RouterLink v-if="auth.canWrite" to="/clients/new" class="px-4 h-10 inline-flex items-center bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
          {{ t('client.new') }}
        </RouterLink>
        <RouterLink v-if="auth.canWrite" to="/invoices/new" class="px-4 h-10 inline-flex items-center border border-neutral-300 text-neutral-700 hover:bg-neutral-50 text-sm font-medium rounded-md">
          {{ t('invoice.new') }}
        </RouterLink>
      </div>
    </div>

    <div v-else-if="summary && summary.kpi" class="space-y-6">
      <!-- ═══ Sekce 1: VYSTAVENÉ FAKTURY ═══ -->
      <section class="space-y-3">
        <h2>
          <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold uppercase tracking-wider bg-primary-50 text-primary-700">
            {{ t('dashboard.section_issued') }}
          </span>
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4" :class="singleCurrency ? 'lg:grid-cols-3' : 'lg:grid-cols-4'">
          <!-- Revenue card: 1 měna → vysoký vlevo (2 řádky); více měn → široký (2 sloupce) -->
          <div v-for="c in summary.kpi.per_currency" :key="c.currency"
            class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm"
            :class="singleCurrency ? 'lg:row-span-2 flex flex-col' : 'md:col-span-2'">
            <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.revenue', { year: summary.year, currency: c.currency }) }}</div>
            <div class="text-2xl font-semibold text-neutral-900 font-mono">{{ formatMoney(c.this_year, c.currency) }}</div>
            <div v-if="c.change_pct !== null" class="text-xs mt-1" :class="c.change_pct >= 0 ? 'text-success-600' : 'text-danger-500'"
              :title="t('dashboard.yoy_ytd_tooltip', { year: summary.prev_year, total: formatMoney(c.prev_year, c.currency), ytd: formatMoney(c.prev_year_ytd, c.currency) })">
              {{ c.change_pct >= 0 ? '▲' : '▼' }} {{ Math.abs(c.change_pct) }} % {{ t('dashboard.vs_prev_ytd', { year: summary.prev_year }) }}
            </div>
            <div v-else class="text-xs text-neutral-400 mt-1">{{ t('dashboard.no_prev_year', { year: summary.prev_year }) }}</div>
            <div v-if="sparklineFor(c.currency).values.some(v => v !== 0)"
              :class="singleCurrency ? 'mt-3 flex-1 flex items-end' : 'mt-3'">
              <SparklineChart
                class="w-full"
                :labels="sparklineFor(c.currency).labels"
                :values="sparklineFor(c.currency).values"
                :format="(v: number) => formatMoney(v, c.currency)"
                :height="singleCurrency ? 150 : 40"
              />
            </div>
          </div>

          <!-- 4 single-column boxes: issued count, overdue, upcoming, avg payment -->
          <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.issued_count', { year: summary.year }) }}</div>
            <div class="text-2xl font-semibold text-neutral-900">{{ summary.kpi.issued_count_ytd }}</div>
            <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.invoices_unit') }}</div>
          </div>

          <div class="bg-surface border rounded-lg p-5 shadow-sm" :class="summary.kpi.overdue_count > 0 ? 'border-danger-500/40' : 'border-neutral-200'">
            <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.overdue') }}</div>
            <div class="text-2xl font-semibold" :class="summary.kpi.overdue_count > 0 ? 'text-danger-500' : 'text-neutral-900'">
              {{ summary.kpi.overdue_count }}
            </div>
            <div class="text-xs mt-1 flex flex-wrap gap-x-3" :class="summary.kpi.overdue_count > 0 ? 'text-danger-500' : 'text-neutral-400'">
              <span v-for="o in summary.kpi.overdue_per_currency" :key="o.currency">{{ formatMoney(o.total, o.currency) }}</span>
              <span v-if="summary.kpi.overdue_count === 0">{{ t('dashboard.all_ok') }}</span>
            </div>
          </div>

          <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.upcoming') }}</div>
            <div class="text-2xl font-semibold text-neutral-900">{{ summary.unpaid_upcoming.length }}</div>
            <div class="text-xs mt-1 text-neutral-400 flex flex-wrap gap-x-3">
              <span v-for="u in upcomingPerCurrency" :key="u.currency">{{ formatMoney(u.total, u.currency) }}</span>
              <span v-if="!upcomingPerCurrency.length">{{ t('dashboard.upcoming_none') }}</span>
            </div>
          </div>

          <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.avg_payment') }}</div>
            <div class="text-2xl font-semibold text-neutral-900">
              {{ summary.kpi.avg_payment_days !== null ? summary.kpi.avg_payment_days + ' ' + t('dashboard.days') : '—' }}
            </div>
            <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.this_year_paid') }}</div>
          </div>
        </div>
      </section>

      <!-- ═══ Sekce 2: PŘIJATÉ FAKTURY (visible jen pokud existují YTD) ═══ -->
      <section v-if="(summary.kpi.purchase_count_ytd ?? 0) > 0" class="space-y-3">
        <h2>
          <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold uppercase tracking-wider bg-warning-50 text-warning-600">
            {{ t('dashboard.section_purchase') }}
          </span>
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <!-- Costs YTD -->
          <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.purchase_costs_ytd', { year: summary.year }) }}</div>
            <div class="text-2xl font-semibold text-neutral-900 font-mono">{{ formatMoney(summary.kpi.purchase_costs_ytd, 'CZK') }}</div>
            <div class="text-xs text-neutral-400 mt-1">{{ summary.kpi.purchase_count_ytd }} {{ t('dashboard.invoices_unit') }}</div>
          </div>

          <!-- Unpaid -->
          <div class="bg-surface border rounded-lg p-5 shadow-sm"
            :class="(summary.kpi.purchase_unpaid_count ?? 0) > 0 ? 'border-warning-500/40' : 'border-neutral-200'">
            <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.purchase_unpaid') }}</div>
            <div class="text-2xl font-semibold"
              :class="(summary.kpi.purchase_unpaid_count ?? 0) > 0 ? 'text-warning-600' : 'text-neutral-900'">
              {{ summary.kpi.purchase_unpaid_count }}
            </div>
            <div class="text-xs mt-1"
              :class="(summary.kpi.purchase_unpaid_count ?? 0) > 0 ? 'text-warning-600' : 'text-neutral-400'">
              {{ formatMoney(summary.kpi.purchase_unpaid_total, 'CZK') }}
            </div>
          </div>

          <!-- Overdue (vždy zobrazené pro grid alignment, červené pokud > 0) -->
          <div class="bg-surface border rounded-lg p-5 shadow-sm"
            :class="(summary.kpi.purchase_overdue_count ?? 0) > 0 ? 'border-danger-500/40' : 'border-neutral-200'">
            <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.purchase_overdue') }}</div>
            <div class="text-2xl font-semibold"
              :class="(summary.kpi.purchase_overdue_count ?? 0) > 0 ? 'text-danger-500' : 'text-neutral-900'">
              {{ summary.kpi.purchase_overdue_count }}
            </div>
            <div class="text-xs mt-1"
              :class="(summary.kpi.purchase_overdue_count ?? 0) > 0 ? 'text-danger-500' : 'text-neutral-400'">
              <RouterLink v-if="(summary.kpi.purchase_overdue_count ?? 0) > 0"
                to="/purchase-invoices?overdue=1" class="hover:underline">
                {{ t('dashboard.purchase_overdue_link') }}
              </RouterLink>
              <span v-else>{{ t('dashboard.all_ok') }}</span>
            </div>
          </div>

          <!-- 4. box: mini graf nákladů (12 měsíců, CZK) — odkaz do CRM analytics -->
          <RouterLink to="/crm"
            class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm hover:bg-neutral-50 hover:border-primary-300 transition flex flex-col">
            <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.costs_trend_12m') }}</div>
            <div v-if="hasCostsData" class="mt-1 flex-1 flex items-end">
              <SparklineChart class="w-full"
                :labels="costsSparkline.labels" :values="costsSparkline.values"
                :format="(v: number) => formatMoney(v, 'CZK')" color="#D9822B" :height="48" />
            </div>
            <div v-else class="text-2xl font-semibold text-primary-700">CRM →</div>
            <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.costs_trend_hint') }}</div>
          </RouterLink>
        </div>
      </section>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4" :class="kpiGridCols">
        <!-- Pending approvals tile (admin only, jen pokud existují requested) -->
        <RouterLink
          v-if="isAdmin && summary.pending_approvals && summary.pending_approvals.requested > 0"
          to="/admin/approvals"
          class="bg-surface border rounded-lg p-5 shadow-sm hover:bg-primary-50 transition cursor-pointer"
          :class="summary.pending_approvals.overdue > 0 ? 'border-warning-500/50' : 'border-primary-500/40'">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.pending_approvals') }}</div>
          <div class="text-2xl font-semibold"
            :class="summary.pending_approvals.overdue > 0 ? 'text-warning-600' : 'text-primary-700'">
            {{ summary.pending_approvals.requested }}
          </div>
          <div class="text-xs mt-1"
            :class="summary.pending_approvals.overdue > 0 ? 'text-warning-600' : 'text-neutral-400'">
            <span v-if="summary.pending_approvals.overdue > 0">
              {{ t('dashboard.pending_approvals_overdue', { n: summary.pending_approvals.overdue }) }}
            </span>
            <span v-else>{{ t('dashboard.pending_approvals_hint') }}</span>
          </div>
        </RouterLink>
      </div>

      <!-- ═══ Sekce 3: SPLATNOST POHLEDÁVEK (vystavené, příchozí platby) ═══ -->
      <section v-if="summary.due_buckets.length" class="space-y-3">
        <h2 class="flex items-center gap-2 flex-wrap">
          <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold uppercase tracking-wider bg-success-50 text-success-600">
            {{ t('dashboard.section_receivables_due') }}
          </span>
          <span class="text-xs text-neutral-400">{{ t('dashboard.receivables_hint') }}</span>
        </h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div v-for="b in summary.due_buckets" :key="`db-today-${b.currency}`"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm"
          :class="{ 'border-warning-500/40 bg-warning-50/20': b.today_count > 0 }">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.due_today') }}</div>
          <div class="text-2xl font-semibold" :class="b.today_count > 0 ? 'text-warning-600' : 'text-neutral-300'">
            {{ b.today_count }}
          </div>
          <div class="text-xs mt-1 font-mono" :class="b.today_count > 0 ? 'text-neutral-700' : 'text-neutral-400'">
            {{ formatMoney(b.today_total, b.currency) }}
          </div>
        </div>
        <div v-for="b in summary.due_buckets" :key="`db-week-${b.currency}`"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.due_this_week') }}</div>
          <div class="text-2xl font-semibold text-neutral-900">{{ b.week_count }}</div>
          <div class="text-xs mt-1 font-mono text-neutral-500">{{ formatMoney(b.week_total, b.currency) }}</div>
        </div>
        <div v-for="b in summary.due_buckets" :key="`db-month-${b.currency}`"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.due_this_month') }}</div>
          <div class="text-2xl font-semibold text-neutral-900">{{ b.month_count }}</div>
          <div class="text-xs mt-1 font-mono text-neutral-500">{{ formatMoney(b.month_total, b.currency) }}</div>
        </div>
      </div>
      </section>

      <!-- Cash-flow forecast 30 / 60 / 90 dní — kolik se očekává inkasovat -->
      <div v-if="summary.cashflow_forecast.length" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-baseline justify-between mb-4">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('dashboard.cashflow_forecast') }}</h3>
          <span class="text-xs text-neutral-400">{{ t('dashboard.cashflow_forecast_hint') }}</span>
        </div>
        <div class="grid grid-cols-3 gap-4">
          <div v-for="period in ['30','60','90']" :key="`cf-${period}`" class="text-center">
            <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.cashflow_in_days', { n: period }) }}</div>
            <div class="space-y-0.5">
              <div v-for="cf in summary.cashflow_forecast" :key="`cf-${period}-${cf.currency}`"
                class="font-mono text-lg font-semibold text-neutral-900">
                {{ formatMoney(period === '30' ? cf.in_30 : period === '60' ? cf.in_60 : cf.in_90, cf.currency) }}
              </div>
            </div>
            <div class="text-xs text-neutral-500 mt-1 space-y-0.5">
              <div v-for="cf in summary.cashflow_forecast" :key="`cf-cnt-${period}-${cf.currency}`">
                {{ period === '30' ? cf.count_30 : period === '60' ? cf.count_60 : cf.count_90 }} {{ t('dashboard.invoices_unit') }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Po splatnosti -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="font-semibold">{{ t('dashboard.overdue_table') }}</h3>
            <span v-if="summary.overdue.length" class="text-xs px-2 py-0.5 rounded bg-danger-50 text-danger-500">
              {{ summary.overdue.length }}
            </span>
          </header>
          <div v-if="!summary.overdue.length" class="p-6 text-center text-sm text-neutral-500">
            {{ t('dashboard.overdue_none') }}
          </div>
          <!-- Desktop: tabulka -->
          <div v-else class="hidden md:block overflow-x-auto"><table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('type.invoice') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('nav.clients') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('invoice.amount_to_pay') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('dashboard.overdue') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="i in summary.overdue" :key="i.id" @click="openInvoice(i.id)" class="cursor-pointer hover:bg-neutral-50">
                <td class="px-3 py-2 font-mono text-xs">{{ i.varsymbol }}</td>
                <td class="px-3 py-2 truncate max-w-[200px]">{{ i.client_company_name }}</td>
                <td class="px-3 py-2 text-right font-mono text-xs">{{ formatMoney(i.amount_to_pay, i.currency) }}</td>
                <td class="px-3 py-2 text-center">
                  <span class="text-xs px-1.5 py-0.5 rounded bg-danger-50 text-danger-500 font-medium">
                    +{{ i.days_overdue }}d
                  </span>
                </td>
              </tr>
            </tbody>
          </table></div>

          <!-- Mobile: kompaktní list -->
          <div v-if="summary.overdue.length" class="md:hidden divide-y divide-neutral-100">
            <div v-for="i in summary.overdue" :key="`m-${i.id}`" @click="openInvoice(i.id)"
              class="cursor-pointer hover:bg-neutral-50 px-3 py-2.5">
              <div class="flex items-baseline justify-between gap-2">
                <div class="font-medium text-neutral-900 truncate">{{ i.client_company_name }}</div>
                <div class="font-mono text-sm whitespace-nowrap">{{ formatMoney(i.amount_to_pay, i.currency) }}</div>
              </div>
              <div class="flex items-baseline justify-between gap-2 mt-0.5">
                <span class="font-mono text-xs text-neutral-500">{{ i.varsymbol }}</span>
                <span class="text-xs px-1.5 py-0.5 rounded bg-danger-50 text-danger-500 font-medium whitespace-nowrap">
                  +{{ i.days_overdue }}d
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Nezaplacené -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="font-semibold">{{ t('dashboard.unpaid_upcoming') }}</h3>
            <span v-if="summary.unpaid_upcoming.length" class="text-xs px-2 py-0.5 rounded bg-primary-100 text-primary-700">
              {{ summary.unpaid_upcoming.length }}
            </span>
          </header>
          <div v-if="!summary.unpaid_upcoming.length" class="p-6 text-center text-sm text-neutral-500">
            {{ t('dashboard.unpaid_none') }}
          </div>
          <!-- Desktop: tabulka -->
          <div v-else class="hidden md:block overflow-x-auto"><table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('type.invoice') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('nav.clients') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('invoice.amount_to_pay') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('invoice.due_date') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="i in summary.unpaid_upcoming" :key="i.id" @click="openInvoice(i.id)" class="cursor-pointer hover:bg-neutral-50">
                <td class="px-3 py-2 font-mono text-xs">{{ i.varsymbol }}</td>
                <td class="px-3 py-2 truncate max-w-[200px]">{{ i.client_company_name }}</td>
                <td class="px-3 py-2 text-right font-mono text-xs">{{ formatMoney(i.amount_to_pay, i.currency) }}</td>
                <td class="px-3 py-2 text-center text-xs">{{ formatDate(i.due_date) }}</td>
              </tr>
            </tbody>
          </table></div>

          <!-- Mobile: kompaktní list -->
          <div v-if="summary.unpaid_upcoming.length" class="md:hidden divide-y divide-neutral-100">
            <div v-for="i in summary.unpaid_upcoming" :key="`m-${i.id}`" @click="openInvoice(i.id)"
              class="cursor-pointer hover:bg-neutral-50 px-3 py-2.5">
              <div class="flex items-baseline justify-between gap-2">
                <div class="font-medium text-neutral-900 truncate">{{ i.client_company_name }}</div>
                <div class="font-mono text-sm whitespace-nowrap">{{ formatMoney(i.amount_to_pay, i.currency) }}</div>
              </div>
              <div class="flex items-baseline justify-between gap-2 mt-0.5 text-xs text-neutral-500">
                <span class="font-mono">{{ i.varsymbol }}</span>
                <span class="font-mono">{{ formatDate(i.due_date) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Top klienti — posledních 12 měsíců: tabulka vlevo, koláč vpravo -->
      <div v-if="summary.top_clients_12m.length" class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200">
          <h3 class="font-semibold">{{ t('dashboard.top_clients_12m') }}</h3>
        </header>
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-4 py-2 text-left font-medium w-8">#</th>
              <th class="px-4 py-2 text-left font-medium">{{ t('nav.clients') }}</th>
              <th class="px-4 py-2 text-center font-medium">Faktur</th>
              <th class="px-4 py-2 text-right font-medium">Obrat</th>
              <th class="px-4 py-2 text-left font-medium w-32">{{ t('common.share') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(c, i) in summary.top_clients_12m" :key="c.client_id" class="hover:bg-neutral-50 cursor-pointer"
                @click="router.push(`/clients/${c.client_id}`)">
              <td class="px-4 py-2.5 text-neutral-400 font-mono text-xs">{{ i + 1 }}</td>
              <td class="px-4 py-2.5 font-medium">
                {{ c.company_name }}
                <span v-if="c.currencies && c.currencies !== 'CZK'" class="ml-1.5 text-xs text-neutral-400 font-normal">({{ c.currencies }})</span>
              </td>
              <td class="px-4 py-2.5 text-center text-xs text-neutral-600">{{ c.invoice_count }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ formatMoney(c.total_czk, 'CZK') }}</td>
              <td class="px-4 py-2.5">
                <div class="h-2 bg-neutral-100 rounded-full overflow-hidden">
                  <div class="h-full bg-primary-500 rounded-full" :style="{ width: (c.total_czk / summary.top_clients_12m[0].total_czk * 100) + '%' }"></div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: kompaktní list s share bar -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="(c, i) in summary.top_clients_12m" :key="`m-${c.client_id}`"
            @click="router.push(`/clients/${c.client_id}`)"
            class="cursor-pointer hover:bg-neutral-50 px-3 py-2.5">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2 min-w-0">
                <span class="text-neutral-400 font-mono text-xs whitespace-nowrap">{{ i + 1 }}.</span>
                <span class="font-medium text-neutral-900 truncate">{{ c.company_name }}</span>
                <span v-if="c.currencies && c.currencies !== 'CZK'" class="text-xs text-neutral-400 whitespace-nowrap">({{ c.currencies }})</span>
              </div>
              <div class="font-mono text-sm whitespace-nowrap">{{ formatMoney(c.total_czk, 'CZK') }}</div>
            </div>
            <div class="flex items-center gap-2 mt-1.5">
              <div class="h-1.5 flex-1 bg-neutral-100 rounded-full overflow-hidden">
                <div class="h-full bg-primary-500 rounded-full" :style="{ width: (c.total_czk / summary.top_clients_12m[0].total_czk * 100) + '%' }"></div>
              </div>
              <span class="text-xs text-neutral-500 font-mono whitespace-nowrap">{{ c.invoice_count }}×</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Koláč Top klienti — totožná data, druhý úhel pohledu (vždy v CZK po přepočtu) -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-baseline justify-between mb-4">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('dashboard.top_clients_12m_share') }}</h3>
          <span class="text-xs font-mono text-neutral-500">CZK</span>
        </div>
        <TopClientsPieChart :clients="summary.top_clients_12m" />
      </div>
      </div>
    </div>
  </div>
</template>

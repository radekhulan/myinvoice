<script setup lang="ts">
import { reactive, ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { taxApi, type TaxAnalysis, type TaxProfile } from '@/api/tax'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatMonth } from '@/composables/useFormat'
import { compare as engineCompare, predict as enginePredict, regular as engineRegular, type EngineProfile } from '@/composables/useTaxEngine'

const { t } = useI18n()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const analysis = ref<TaxAnalysis | null>(null)
const year = ref<number>(new Date().getFullYear())
const lastMonthOpen = ref(false)

const profile = reactive<TaxProfile>({
  activity_rate: 60, use_actual_expenses: false, actual_expenses: 0,
  flat_tax_band: 'none', is_secondary: false, spouse_credit: false,
  children_count: 0, mortgage_interest: 0, pension_contrib: 0, life_insurance: 0, donations: 0,
})

async function load(y: number) {
  loading.value = true
  error.value = ''
  try {
    const a = await taxApi.analysis(y)
    analysis.value = a
    year.value = a.year
    Object.assign(profile, a.profile)
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    loading.value = false
  }
}
onMounted(() => load(year.value))

const engineProfile = computed<EngineProfile>(() => ({
  ...profile,
  is_vat_payer: analysis.value?.is_vat_payer ?? false,
}))
const isForecast = computed(() => analysis.value?.mode === 'forecast')
const income = computed(() => analysis.value?.income ?? 0)

const cmp = computed(() =>
  analysis.value && !isForecast.value
    ? engineCompare(engineProfile.value, income.value, analysis.value.constants)
    : null)

const pred = computed(() =>
  analysis.value && isForecast.value
    ? enginePredict(engineProfile.value, analysis.value.ytd_income ?? 0, analysis.value.months_elapsed ?? 1, analysis.value.constants)
    : null)

// YoY: čistý příjem standardního režimu loni (stejný profil na loňský příjem + loňské konstanty).
const prevNet = computed(() => {
  const p = analysis.value?.prev
  if (!p || !cmp.value) return null
  return { year: p.year, net: Math.round(engineRegular(engineProfile.value, p.income, p.constants).net) }
})
const yoyNetPct = computed(() => {
  if (!prevNet.value || !cmp.value || prevNet.value.net === 0) return null
  return (cmp.value.regular.net - prevNet.value.net) / Math.abs(prevNet.value.net) * 100
})

// Teploměr — škála a pozice (procenta)
const SCALE = 2_700_000
const pct = (v: number) => Math.min(100, Math.max(0, v / SCALE * 100))
const ticks = computed(() => [
  { v: 1_500_000, l: '1,5 M' },
  { v: 2_000_000, l: '2 M' },
  { v: 2_536_500, l: '2,54 M' },
])

function incKids(d: number) {
  profile.children_count = Math.max(0, Math.min(10, profile.children_count + d))
}
function monthLabel(m: number | null): string {
  if (!m) return ''
  return formatMonth(`${year.value}-${String(Math.min(12, m)).padStart(2, '0')}`)
}
async function save() {
  saving.value = true
  try {
    await taxApi.saveProfile({ year: year.value, ...profile })
    if (analysis.value) analysis.value.profile = { ...profile, saved: true }
    toast.success(t('tax.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <div class="mb-5">
      <h1 class="text-2xl font-semibold mb-1">{{ t('tax.title') }}</h1>
      <p class="text-sm text-neutral-500">{{ t('tax.subtitle') }}</p>
    </div>

    <!-- Přepínač roku / režimu -->
    <div v-if="analysis" class="inline-flex gap-1.5 bg-neutral-100 border border-neutral-200 rounded-xl p-1.5 mb-6">
      <button v-for="y in analysis.available_years" :key="y" @click="load(y)" type="button"
        class="flex flex-col items-center min-w-[7.5rem] px-5 py-2 rounded-lg transition cursor-pointer border"
        :class="y === year
          ? 'bg-primary-600 border-primary-600 text-white shadow-md'
          : 'bg-surface border-neutral-200 text-neutral-600 hover:border-primary-300 hover:text-neutral-900'">
        <span class="text-xl font-bold leading-none tracking-tight">{{ y }}</span>
        <span class="text-[11px] font-semibold uppercase tracking-wider mt-1"
          :class="y === year ? 'text-white/85' : 'text-neutral-400'">
          {{ y < new Date().getFullYear() ? t('tax.tag_retro') : t('tax.tag_forecast') }}
        </span>
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>
    <div v-else-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>

    <div v-else-if="analysis" class="grid grid-cols-1 lg:grid-cols-[300px_1fr] gap-6 items-start">
      <!-- ── PROFIL ── -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm lg:sticky lg:top-4">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('tax.profile') }}</h2>

        <div class="space-y-4">
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('tax.activity') }}</label>
            <select v-model.number="profile.activity_rate" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option :value="60">{{ t('tax.activity_60') }}</option>
              <option :value="80">{{ t('tax.activity_80') }}</option>
              <option :value="40">{{ t('tax.activity_40') }}</option>
              <option :value="30">{{ t('tax.activity_30') }}</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('tax.expense_mode') }}</label>
            <div class="flex rounded-md border border-neutral-300 overflow-hidden text-sm mb-2">
              <button type="button" @click="profile.use_actual_expenses = false"
                :class="!profile.use_actual_expenses ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
                class="flex-1 px-3 h-9 cursor-pointer">{{ t('tax.expense_pausal') }}</button>
              <button type="button" @click="profile.use_actual_expenses = true"
                :class="profile.use_actual_expenses ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
                class="flex-1 px-3 h-9 cursor-pointer border-l border-neutral-300">{{ t('tax.expense_actual') }}</button>
            </div>
            <input v-if="profile.use_actual_expenses" v-model.number="profile.actual_expenses" type="number" min="0"
              :placeholder="t('tax.expense_actual_ph')"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm font-mono" />
            <p class="text-xs text-neutral-400 mt-1">{{ t('tax.expense_mode_hint') }}</p>
          </div>

          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('tax.band') }}</label>
            <select v-model="profile.flat_tax_band" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="band1">{{ t('tax.band_band1') }}</option>
              <option value="band2">{{ t('tax.band_band2') }}</option>
              <option value="band3">{{ t('tax.band_band3') }}</option>
              <option value="none">{{ t('tax.band_none') }}</option>
            </select>
          </div>

          <!-- DPH info (mění se v Nastavení) -->
          <div class="flex items-center justify-between text-xs py-2 border-t border-neutral-100">
            <span class="text-neutral-600">{{ t('settings.is_vat_payer') }}</span>
            <span :class="analysis.is_vat_payer ? 'text-danger-600 font-medium' : 'text-success-600 font-medium'">
              {{ analysis.is_vat_payer ? t('common.yes') : t('common.no') }}
            </span>
          </div>

          <label class="flex items-center justify-between gap-2 py-2 border-t border-neutral-100 cursor-pointer">
            <span class="text-xs font-medium text-neutral-700">
              {{ t('tax.secondary') }}
              <small class="block font-normal text-neutral-400">{{ t('tax.secondary_hint') }}</small>
            </span>
            <input v-model="profile.is_secondary" type="checkbox" class="rounded border-neutral-300 text-primary-600 shrink-0">
          </label>

          <label class="flex items-center justify-between gap-2 py-2 border-t border-neutral-100 cursor-pointer">
            <span class="text-xs font-medium text-neutral-700">
              {{ t('tax.spouse') }}
              <small class="block font-normal text-neutral-400">{{ t('tax.spouse_hint') }}</small>
            </span>
            <input v-model="profile.spouse_credit" type="checkbox" class="rounded border-neutral-300 text-primary-600 shrink-0">
          </label>

          <div class="flex items-center justify-between gap-2">
            <label class="text-xs font-medium text-neutral-700">{{ t('tax.children') }}</label>
            <div class="flex items-center border border-neutral-300 rounded-md overflow-hidden">
              <button type="button" @click="incKids(-1)" class="w-8 h-8 bg-neutral-100 hover:bg-neutral-200 text-neutral-700">−</button>
              <span class="w-10 text-center text-sm font-semibold">{{ profile.children_count }}</span>
              <button type="button" @click="incKids(1)" class="w-8 h-8 bg-neutral-100 hover:bg-neutral-200 text-neutral-700">+</button>
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('tax.mortgage') }}</label>
            <input v-model.number="profile.mortgage_interest" type="number" min="0" step="1000"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm text-right">
            <p class="text-[11px] text-neutral-400 mt-1">{{ t('tax.mortgage_hint') }}</p>
          </div>

          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('tax.pension') }}</label>
            <input v-model.number="profile.pension_contrib" type="number" min="0" step="1000"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm text-right">
            <p class="text-[11px] text-neutral-400 mt-1">{{ t('tax.pension_hint') }}</p>
          </div>

          <button @click="save" :disabled="saving"
            class="w-full h-9 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
            {{ saving ? t('common.saving') : t('tax.save') }}
          </button>
        </div>
      </div>

      <!-- ── VÝSLEDKY ── -->
      <div>
        <!-- Čistý příjem za minulý měsíc (odhad, nezávisle na zvoleném roce) -->
        <div v-if="analysis.last_month" class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-6 overflow-hidden">
          <button type="button" @click="lastMonthOpen = !lastMonthOpen"
            class="w-full flex items-center justify-between gap-3 px-5 py-4 text-left cursor-pointer hover:bg-neutral-50">
            <div>
              <div class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                {{ t('tax.last_month_title', { month: formatMonth(analysis.last_month.ym) }) }}
              </div>
              <div class="text-2xl font-bold font-mono text-success-700 mt-1">{{ formatMoney(analysis.last_month.net_income, 'CZK') }}</div>
            </div>
            <span class="text-neutral-400 transition-transform shrink-0" :class="lastMonthOpen ? 'rotate-180' : ''">▾</span>
          </button>
          <div v-if="lastMonthOpen" class="px-5 pb-5 border-t border-neutral-100">
            <table class="w-full text-sm mt-3">
              <tbody>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">{{ t('tax.last_month_revenue') }}</td><td class="py-1.5 text-right font-medium font-mono">{{ formatMoney(analysis.last_month.revenue, 'CZK') }}</td></tr>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">− {{ t('tax.last_month_expenses') }}</td><td class="py-1.5 text-right font-medium font-mono">{{ formatMoney(analysis.last_month.expenses, 'CZK') }}</td></tr>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">= {{ t('tax.last_month_profit') }}</td><td class="py-1.5 text-right font-medium font-mono">{{ formatMoney(analysis.last_month.profit, 'CZK') }}</td></tr>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">{{ t('tax.last_month_tax') }}</td><td class="py-1.5 text-right font-medium font-mono">{{ formatMoney(analysis.last_month.income_tax, 'CZK') }}</td></tr>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">{{ t('tax.last_month_social') }}</td><td class="py-1.5 text-right font-medium font-mono">{{ formatMoney(analysis.last_month.social, 'CZK') }}</td></tr>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">{{ t('tax.last_month_health') }}</td><td class="py-1.5 text-right font-medium font-mono">{{ formatMoney(analysis.last_month.health, 'CZK') }}</td></tr>
                <tr><td class="pt-2 font-bold text-success-700">{{ t('tax.last_month_net') }}</td><td class="pt-2 text-right font-bold font-mono text-success-700">{{ formatMoney(analysis.last_month.net_income, 'CZK') }}</td></tr>
              </tbody>
            </table>
            <p class="mt-2 text-[11px] text-neutral-400">{{ t('tax.last_month_hint') }}</p>
          </div>
        </div>

        <!-- RETROSPEKTIVA (uzavřený rok) -->
        <template v-if="cmp">
          <div class="text-sm text-neutral-500 mb-3">
            {{ t('tax.income_label') }}: <span class="font-mono font-semibold text-neutral-900">{{ formatMoney(income, 'CZK') }}</span>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <!-- Paušál -->
            <div class="relative border rounded-xl p-5 bg-surface"
              :class="[cmp.winner === 'pausal' ? 'border-success-600 ring-2 ring-success-50' : 'border-neutral-200', !cmp.pausal.ok ? 'opacity-60' : '']">
              <span v-if="cmp.winner === 'pausal'" class="absolute -top-2.5 left-4 bg-success-600 text-white text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full">{{ t('tax.best') }}</span>
              <div class="text-xs font-bold uppercase tracking-wide text-neutral-500">{{ t('tax.scenario_pausal') }}</div>
              <div class="text-3xl font-bold mt-2 mb-1 font-mono">{{ cmp.pausal.ok ? formatMoney(cmp.pausal.total, 'CZK') : '—' }}</div>
              <div class="text-xs text-neutral-500">
                <template v-if="cmp.pausal.ok">
                  {{ t('tax.band_' + cmp.pausal.eff) }}
                  <span v-if="cmp.pausal.note" class="text-warning-600"> · {{ t('tax.surcharge') }} {{ formatMoney(cmp.pausal.surcharge, 'CZK') }}</span>
                </template>
                <template v-else>{{ cmp.pausal.reason === 'vat_payer' ? t('tax.vat_payer_note') : t('tax.over_2m_note') }}</template>
              </div>
            </div>
            <!-- Běžný režim -->
            <div class="relative border rounded-xl p-5 bg-surface"
              :class="cmp.winner === 'regular' ? 'border-success-600 ring-2 ring-success-50' : 'border-neutral-200'">
              <span v-if="cmp.winner === 'regular'" class="absolute -top-2.5 left-4 bg-success-600 text-white text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full">{{ t('tax.best') }}</span>
              <div class="text-xs font-bold uppercase tracking-wide text-neutral-500">{{ t('tax.scenario_regular', { rate: profile.activity_rate }) }}</div>
              <div class="text-3xl font-bold mt-2 mb-1 font-mono">{{ formatMoney(cmp.regular.total, 'CZK') }}</div>
              <div class="text-xs text-neutral-500">
                {{ t('tax.bd_tax') }}
                <span :class="cmp.regular.isBonus ? 'text-success-600 font-medium' : ''">{{ formatMoney(cmp.regular.incomeTax, 'CZK') }}</span>
                + {{ t('tax.insurance') }} {{ formatMoney(cmp.regular.soc + cmp.regular.hea, 'CZK') }}
              </div>
            </div>
          </div>

          <!-- Verdikt -->
          <div class="flex gap-3 items-start bg-primary-50 border border-primary-100 rounded-xl px-4 py-3 mb-4">
            <span class="text-xl leading-none">{{ cmp.delta !== null && cmp.delta > 0 ? '📌' : '🔁' }}</span>
            <div class="text-sm text-neutral-700">
              <template v-if="cmp.delta !== null">
                <div class="font-semibold text-primary-700">
                  {{ t('tax.verdict', { regime: cmp.winner === 'pausal' ? t('tax.scenario_pausal') : t('tax.scenario_regular', { rate: profile.activity_rate }), amount: formatMoney(Math.abs(cmp.delta), 'CZK') }) }}
                </div>
                {{ cmp.delta > 0 ? t('tax.verdict_pausal_hint') : t('tax.verdict_regular_hint') }}
              </template>
              <div v-else class="font-semibold text-primary-700">{{ t('tax.pausal_unavailable') }}</div>
            </div>
          </div>

          <!-- Rozpad -->
          <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('tax.breakdown') }}</h2>
            <table class="w-full text-sm">
              <tbody>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">{{ t('tax.bd_income') }}</td><td class="py-1.5 text-right font-medium font-mono">{{ formatMoney(income, 'CZK') }}</td></tr>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">− {{ cmp.regular.useActual ? t('tax.bd_expenses_actual') : t('tax.bd_expenses', { rate: profile.activity_rate }) }}</td><td class="py-1.5 text-right font-medium font-mono">{{ formatMoney(cmp.regular.exp, 'CZK') }}</td></tr>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">− {{ t('tax.bd_deductions') }}</td><td class="py-1.5 text-right font-medium font-mono">{{ formatMoney(cmp.regular.ded, 'CZK') }}</td></tr>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">= {{ t('tax.bd_base') }}</td><td class="py-1.5 text-right font-medium font-mono">{{ formatMoney(cmp.regular.base, 'CZK') }}</td></tr>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">{{ t('tax.bd_tax_after') }}</td><td class="py-1.5 text-right font-medium font-mono" :class="cmp.regular.isBonus ? 'text-success-600' : ''">{{ formatMoney(cmp.regular.incomeTax, 'CZK') }}</td></tr>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">+ {{ t('tax.bd_social') }}</td><td class="py-1.5 text-right font-medium font-mono">{{ formatMoney(cmp.regular.soc, 'CZK') }}</td></tr>
                <tr class="border-b border-neutral-100"><td class="py-1.5 text-neutral-600">+ {{ t('tax.bd_health') }}</td><td class="py-1.5 text-right font-medium font-mono">{{ formatMoney(cmp.regular.hea, 'CZK') }}</td></tr>
                <tr><td class="pt-2 font-bold text-neutral-900">{{ t('tax.bd_total') }}</td><td class="pt-2 text-right font-bold font-mono">{{ formatMoney(cmp.regular.total, 'CZK') }}</td></tr>
                <tr class="border-t-2 border-neutral-200"><td class="pt-2 font-bold text-success-700">= {{ t('tax.bd_net') }}</td><td class="pt-2 text-right font-bold font-mono text-success-700">{{ formatMoney(cmp.regular.net, 'CZK') }}</td></tr>
                <tr><td class="py-1 text-neutral-500 text-xs">{{ t('tax.bd_effective') }}</td><td class="py-1 text-right text-xs text-neutral-500 font-mono">{{ (cmp.regular.eff * 100).toFixed(1) }} %</td></tr>
                <tr v-if="prevNet && yoyNetPct !== null">
                  <td class="py-1 text-neutral-500 text-xs">{{ t('tax.yoy_net', { year: prevNet.year }) }}</td>
                  <td class="py-1 text-right text-xs font-mono">
                    <span class="text-neutral-500">{{ formatMoney(prevNet.net, 'CZK') }}</span>
                    <span class="ml-1" :class="yoyNetPct >= 0 ? 'text-success-600' : 'text-danger-500'">{{ yoyNetPct >= 0 ? '▲' : '▼' }} {{ Math.abs(yoyNetPct).toFixed(0) }} %</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <!-- PREDIKCE (běžící rok) -->
        <template v-else-if="pred">
          <div class="grid grid-cols-3 gap-4 mb-4">
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
              <div class="text-[11px] uppercase tracking-wide text-neutral-500 font-medium">{{ t('tax.kpi_ytd') }}</div>
              <div class="text-xl font-bold mt-1 font-mono">{{ formatMoney(pred.ytd, 'CZK') }}</div>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
              <div class="text-[11px] uppercase tracking-wide text-neutral-500 font-medium">{{ t('tax.kpi_rate') }}</div>
              <div class="text-xl font-bold mt-1 font-mono">{{ formatMoney(pred.run, 'CZK') }}</div>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
              <div class="text-[11px] uppercase tracking-wide text-neutral-500 font-medium">{{ t('tax.kpi_projection') }}</div>
              <div class="text-xl font-bold mt-1 font-mono" :class="pred.proj > 2_000_000 ? 'text-danger-600' : ''">{{ formatMoney(pred.proj, 'CZK') }}</div>
            </div>
          </div>

          <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('tax.thermometer') }} · {{ year }}</h2>

            <!-- teploměr -->
            <div class="relative h-7 rounded-md overflow-hidden border border-neutral-200 bg-gradient-to-r from-success-50 from-[75%] to-warning-50 to-[75%]">
              <div class="absolute inset-y-0 left-0 bg-primary-600/90" :style="{ width: pct(pred.ytd) + '%' }"></div>
              <div class="absolute inset-y-[-4px] w-0.5 bg-danger-600" :style="{ left: pct(pred.proj) + '%' }"></div>
            </div>
            <div class="relative h-8 mt-1">
              <div v-for="tk in ticks" :key="tk.v" class="absolute -translate-x-1/2 text-center" :style="{ left: pct(tk.v) + '%' }">
                <div class="w-px h-2 bg-neutral-300 mx-auto mb-0.5"></div>
                <div class="text-[10px] text-neutral-500 font-medium whitespace-nowrap">{{ tk.l }}</div>
              </div>
            </div>

            <!-- varování -->
            <div class="space-y-2 mt-4">
              <div v-for="x in pred.cross" :key="x.key"
                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm border"
                :class="x.will ? 'bg-danger-50 border-danger-500/30 text-danger-600' : 'bg-success-50 border-success-600/20 text-success-700'">
                <span>{{ x.will ? '⚠' : '✓' }}</span>
                <div>
                  <b>{{ t('tax.limit_' + x.key) }}</b> · {{ formatMoney(x.val, 'CZK') }} —
                  {{ x.will ? t('tax.will_cross_in', { month: monthLabel(x.month) }) : t('tax.wont_cross') }}
                </div>
              </div>

              <!-- Vedlejší činnost: rozhodná částka pro placení sociálního pojištění (měří se proti zisku) -->
              <div v-if="pred.secondary"
                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm border"
                :class="pred.secondary.will ? 'bg-danger-50 border-danger-500/30 text-danger-600' : 'bg-success-50 border-success-600/20 text-success-700'">
                <span>{{ pred.secondary.will ? '⚠' : '✓' }}</span>
                <div>
                  <b>{{ t('tax.limit_social_secondary') }}</b> · {{ formatMoney(pred.secondary.threshold, 'CZK') }} —
                  {{ pred.secondary.will
                    ? t('tax.social_secondary_cross', { month: monthLabel(pred.secondary.month), profit: formatMoney(pred.secondary.profit, 'CZK') })
                    : t('tax.social_secondary_ok', { profit: formatMoney(pred.secondary.profit, 'CZK') }) }}
                </div>
              </div>
            </div>

            <!-- tip -->
            <div v-if="pred.deferMonth" class="flex gap-3 mt-4 bg-warning-50 border border-warning-500/40 rounded-lg px-4 py-3 text-sm text-warning-600">
              <span>💡</span>
              <span>{{ t('tax.defer_tip', { month: monthLabel(pred.deferMonth) }) }}</span>
            </div>
          </div>
        </template>

        <p class="mt-5 text-[11px] text-neutral-400 border-t border-neutral-200 pt-3">{{ t('tax.disclaimer') }}</p>
      </div>
    </div>
  </div>
</template>

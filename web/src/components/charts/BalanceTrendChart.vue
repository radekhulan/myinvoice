<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import {
  Chart,
  LineController,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Filler,
} from 'chart.js'
import { useChartColors } from '@/composables/useTheme'
import { formatMoney, formatMonth } from '@/composables/useFormat'

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Filler)

/**
 * Liniový graf vývoje zůstatku v čase. Sdílený pro malé grafy jednotlivých účtů
 * (nativní měna) i pro celkový CZK graf s rozpadem na více účtů. Osa X = 'YYYY-MM'
 * popisky, hodnoty mohou obsahovat null (mezera před založením účtu / bez kurzu).
 */
export interface BalanceTrendDataset {
  label: string
  values: Array<number | null>
  color: string
  fill?: boolean
  emphasis?: boolean
}

const props = defineProps<{
  /** Popisky 'YYYY-MM'. */
  labels: string[]
  /** Hodnoty zůstatku ve stejné délce jako labels; null = bez bodu. */
  values?: Array<number | null>
  /** Více pojmenovaných řad; pokud jsou zadané, mají přednost před `values`. */
  datasets?: BalanceTrendDataset[]
  /** Měna pro formátování tooltipu/os (např. 'CZK', 'EUR'). */
  currency: string
  /** Barva křivky; default primární. */
  color?: string
  /** Vyplnit plochu pod křivkou. */
  fill?: boolean
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()
const resolvedDatasets = computed<BalanceTrendDataset[]>(() => props.datasets?.length
  ? props.datasets
  : [{ label: '', values: props.values ?? [], color: props.color ?? colors.value.primary, fill: props.fill }])

function formatTick(n: number): string {
  const abs = Math.abs(n)
  if (abs >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M'
  if (abs >= 1_000) return (n / 1_000).toFixed(0) + 'k'
  return String(Math.round(n))
}

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()

  const sourceDatasets = resolvedDatasets.value
  chart = new Chart(canvas.value, {
    type: 'line',
    data: {
      labels: props.labels,
      datasets: sourceDatasets.map(ds => ({
          label: ds.label,
          data: ds.values as number[],
          borderColor: ds.color,
          backgroundColor: ds.fill ? ds.color + '26' : 'transparent', // 26 = ~15% alpha
          borderWidth: ds.emphasis ? 3 : 2,
          tension: 0.3,
          pointRadius: props.labels.length > 24 ? 0 : 2,
          pointHoverRadius: 4,
          pointBackgroundColor: ds.color,
          fill: !!ds.fill,
          spanGaps: true,
        })),
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            title: (items) => (items.length ? formatMonth(String(items[0].label)) : ''),
            label: (ctx) => {
              const value = formatMoney(Number(ctx.parsed.y ?? 0), props.currency)
              return ctx.dataset.label ? `${ctx.dataset.label}: ${value}` : value
            },
          },
        },
      },
      scales: {
        y: {
          ticks: { color: colors.value.tick, font: { size: 11 }, callback: (v) => formatTick(Number(v)) },
          grid: { color: colors.value.grid },
        },
        x: {
          ticks: {
            color: colors.value.tick,
            font: { size: 11 },
            maxRotation: 0,
            autoSkip: true,
            callback(_v, index) {
              // Zkrácený popisek 'MM/RR' ať se osa nepřeplní.
              const raw = String(this.getLabelForValue(index as number))
              const [y, m] = raw.split('-')
              return m && y ? `${m}/${y.slice(2)}` : raw
            },
          },
          grid: { display: false },
        },
      },
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.labels, props.values, props.datasets, props.currency, props.color, props.fill], build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="flex h-full w-full flex-col">
    <div class="relative min-h-0 flex-1"><canvas ref="canvas"></canvas></div>
    <div
      v-if="resolvedDatasets.length > 1"
      class="mt-3 flex flex-wrap justify-center gap-x-5 gap-y-2 text-xs text-muted"
      role="list"
    >
      <div v-for="dataset in resolvedDatasets" :key="dataset.label" class="flex items-center gap-2" role="listitem">
        <span
          class="inline-block h-0.5 w-6 rounded-full"
          :style="{ backgroundColor: dataset.color, height: dataset.emphasis ? '3px' : '2px' }"
          aria-hidden="true"
        ></span>
        <span :class="{ 'font-semibold text-ink': dataset.emphasis }">{{ dataset.label }}</span>
      </div>
    </div>
  </div>
</template>

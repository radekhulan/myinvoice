<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  Chart,
  LineController,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'
import { useChartColors } from '@/composables/useTheme'

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend, Filler)

/**
 * Kumulativní obrat YTD vs. minulý rok do stejného dne.
 *
 * Vstupem je `months` (aktuálních 12 měsíců, končící aktuálním měsícem) a `prevYear`
 * (totéž okno −1 rok). Komponenta najde index aktuálního měsíce a kumuluje hodnoty
 * z měsíců, které patří do tohoto roku resp. roku předchozího.
 */
const props = defineProps<{
  months: Array<{ ym: string; total: number }>
  prevYear: Array<{ ym: string; total: number }>
  currency: string
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const { locale, t } = useI18n()
const colors = useChartColors()

const seriesData = computed(() => {
  const now = new Date()
  const thisYear = now.getFullYear()
  const prevYear = thisYear - 1
  const labels: string[] = []
  const thisCum: number[] = []
  const prevCum: number[] = []
  let tAcc = 0
  let pAcc = 0
  for (let m = 1; m <= 12; m++) {
    const ymThis = `${thisYear}-${String(m).padStart(2, '0')}`
    const ymPrev = `${prevYear}-${String(m).padStart(2, '0')}`
    const thisVal = props.months.find(x => x.ym === ymThis)?.total
              ?? props.prevYear.find(x => x.ym === ymThis)?.total ?? 0
    const prevVal = props.months.find(x => x.ym === ymPrev)?.total
              ?? props.prevYear.find(x => x.ym === ymPrev)?.total ?? 0
    tAcc += Math.max(0, thisVal)
    pAcc += Math.max(0, prevVal)
    labels.push(String(m).padStart(2, '0'))
    // Future months pro aktuální rok → null (nezobrazí se).
    thisCum.push(m > now.getMonth() + 1 ? NaN : tAcc)
    prevCum.push(pAcc)
  }
  return { labels, thisCum, prevCum, thisYear, prevYear }
})

function formatVal(n: number): string {
  return new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(n)
}

function formatTick(n: number): string {
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M'
  if (n >= 1_000) return (n / 1_000).toFixed(0) + 'k'
  return n.toString()
}

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()
  const { labels, thisCum, prevCum, thisYear, prevYear } = seriesData.value

  chart = new Chart(canvas.value, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: `${thisYear}`,
          data: thisCum,
          borderColor: colors.value.primary,
          backgroundColor: 'rgba(92, 69, 160, 0.15)',
          borderWidth: 2.5,
          tension: 0.3,
          pointRadius: 3,
          pointBackgroundColor: colors.value.primary,
          fill: true,
          spanGaps: false,
        },
        {
          label: `${prevYear}`,
          data: prevCum,
          borderColor: colors.value.primarySoft,
          backgroundColor: 'transparent',
          borderWidth: 2,
          borderDash: [5, 4],
          tension: 0.3,
          pointRadius: 2,
          pointBackgroundColor: colors.value.primarySoft,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 12, color: colors.value.tick } },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => `${ctx.dataset.label}: ${formatVal(Number(ctx.parsed.y || 0))} ${props.currency}`,
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { color: colors.value.tick, font: { size: 11 }, callback: (v) => formatTick(Number(v)) },
          grid: { color: colors.value.grid },
        },
        x: { ticks: { color: colors.value.tick, font: { size: 11 } }, grid: { display: false } },
      },
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.months, props.prevYear, props.currency, locale.value], build, { deep: true })
watch(colors, build)
// Použito jen pro odlišení od dashboard chartu — t() pro budoucí i18n hover labelů.
void t
</script>

<template>
  <div class="relative h-64"><canvas ref="canvas"></canvas></div>
</template>

<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import {
  Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip,
} from 'chart.js'
import { useChartColors } from '@/composables/useTheme'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip)

const props = defineProps<{
  labels: string[]
  values: number[]
  currency: string
  // Indexů, které mají být zobrazeny šedě (typicky "Ostatní" agregát)
  greyedIndexes?: number[]
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()

  const greyed = new Set(props.greyedIndexes ?? [])
  const barColors = props.values.map((_, i) => greyed.has(i) ? colors.value.primarySoft : colors.value.primary)

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels: props.labels,
      datasets: [{
        data: props.values,
        backgroundColor: barColors,
        borderRadius: 4,
      }],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => `${formatVal(ctx.parsed.x ?? 0)} ${props.currency}`,
          },
        },
      },
      scales: {
        x: {
          beginAtZero: true,
          ticks: { color: colors.value.tick, font: { size: 11 }, callback: (v) => formatTick(Number(v)) },
          grid: { color: colors.value.grid },
        },
        y: {
          ticks: { color: colors.value.tick, font: { size: 11 }, autoSkip: false },
          grid: { display: false },
        },
      },
    },
  })
}

function formatVal(n: number): string {
  return new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(n)
}
function formatTick(n: number): string {
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M'
  if (n >= 1_000) return (n / 1_000).toFixed(0) + 'k'
  return n.toString()
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.labels, props.values, props.currency], build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="relative" :style="{ height: Math.max(160, (labels?.length ?? 0) * 28) + 'px' }">
    <canvas ref="canvas"></canvas>
  </div>
</template>

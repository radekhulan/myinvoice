<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import {
  Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip,
} from 'chart.js'
import { useChartColors } from '@/composables/useTheme'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip)

/**
 * Mini sparkline (bar) — bez os, mřížek, legendy. Pro vložení do KPI tile pod částku.
 * Tooltip ukáže label + hodnotu.
 */
const props = defineProps<{
  labels: string[]
  values: number[]
  /** Volitelný formátovač hodnoty v tooltipu (např. peníze) */
  format?: (v: number) => string
  color?: string
  height?: number
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()
  const formatter = props.format ?? ((v: number) => String(v))

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels: props.labels,
      datasets: [{
        data: props.values,
        backgroundColor: props.color ?? colors.value.primary,
        borderRadius: 1.5,
        barPercentage: 0.85,
        categoryPercentage: 0.95,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          displayColors: false,
          callbacks: { label: (ctx) => ` ${ctx.label}: ${formatter(Number(ctx.parsed.y || 0))}` },
        },
      },
      scales: {
        x: { display: false },
        y: { display: false, beginAtZero: true },
      },
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.labels, props.values, props.color], build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="relative" :style="{ height: (height ?? 40) + 'px' }">
    <canvas ref="canvas"></canvas>
  </div>
</template>

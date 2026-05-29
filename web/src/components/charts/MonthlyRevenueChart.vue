<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import {
  Chart,
  BarController,
  BarElement,
  CategoryScale,
  LinearScale,
  Tooltip,
} from 'chart.js'
import { useChartColors } from '@/composables/useTheme'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip)

const props = defineProps<{
  labels: string[]   // např. "2024-03"
  values: number[]
  currency: string
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels: props.labels,
      datasets: [
        {
          data: props.values,
          backgroundColor: colors.value.primary,
          borderRadius: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => `${formatVal(ctx.parsed.y ?? 0)} ${props.currency}`,
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { color: colors.value.tick, font: { size: 11 }, callback: (v) => formatTick(Number(v)) },
          grid: { color: colors.value.grid },
        },
        x: {
          ticks: { color: colors.value.tick, font: { size: 10 }, maxRotation: 45, minRotation: 45 },
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
  <div class="relative h-56">
    <canvas ref="canvas"></canvas>
  </div>
</template>

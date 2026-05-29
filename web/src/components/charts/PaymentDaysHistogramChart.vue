<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import {
  Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip,
} from 'chart.js'
import { useChartColors } from '@/composables/useTheme'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip)

const props = defineProps<{
  buckets: Array<{ key: string; label: string; count: number }>
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()

const palette = ['#4CAF7A', '#A99CD8', '#E8A547', '#D45B5B']

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()

  const labels = props.buckets.map(b => b.label)
  const data = props.buckets.map(b => b.count)
  const barColors = props.buckets.map((_, i) => palette[i] ?? '#5C45A0')

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: { labels, datasets: [{ data, backgroundColor: barColors, borderRadius: 4 }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: { label: (ctx) => ` ${ctx.parsed.y} faktur` },
        },
      },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0, color: colors.value.tick, font: { size: 11 } }, grid: { color: colors.value.grid } },
        x: { ticks: { color: colors.value.tick, font: { size: 11 } }, grid: { display: false } },
      },
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => props.buckets, build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="relative h-56"><canvas ref="canvas"></canvas></div>
</template>

<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import {
  Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend,
} from 'chart.js'
import { useChartColors, useTheme } from '@/composables/useTheme'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend)

/**
 * Stacked horizontal bar (jeden řádek per měna). Zelená = aktuální, gradient přes oranžovou
 * do červené pro starší pohledávky.
 */
const props = defineProps<{
  rows: Array<{
    currency: string
    current: number; b1_30: number; b31_60: number; b61_90: number; b90_plus: number
  }>
  format?: (v: number, currency: string) => string
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()
const { isDark } = useTheme()

// Semantic gradient stáří pohledávek. V dark je nejtmavší maroon (90+) nahrazen
// světlejší červenou, aby na tmavém pozadí nesplýval.
const palette = computed(() => isDark.value
  ? ['#4CAF7A', '#A99CD8', '#E8A547', '#D45B5B', '#E07A7A']
  : ['#4CAF7A', '#A99CD8', '#E8A547', '#D45B5B', '#7A2E2E'])
const bucketKeys = ['current', 'b1_30', 'b31_60', 'b61_90', 'b90_plus'] as const
const bucketLabels = ['Aktuální', '1–30 dní', '31–60 dní', '61–90 dní', '90+ dní']

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()

  const labels = props.rows.map(r => r.currency)
  const datasets = bucketKeys.map((k, idx) => ({
    label: bucketLabels[idx],
    data: props.rows.map(r => r[k]),
    backgroundColor: palette.value[idx],
    borderRadius: 2,
    stack: 'agg',
  }))

  const fmt = props.format ?? ((v: number) => String(v))

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: { labels, datasets },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 }, color: colors.value.tick } },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => {
              const cur = props.rows[ctx.dataIndex]?.currency ?? ''
              return ` ${ctx.dataset.label}: ${fmt(Number(ctx.parsed.x || 0), cur)}`
            },
          },
        },
      },
      scales: {
        x: { stacked: true, beginAtZero: true, ticks: { color: colors.value.tick, font: { size: 11 } }, grid: { color: colors.value.grid } },
        y: { stacked: true, ticks: { color: colors.value.tick, font: { size: 11 } }, grid: { display: false } },
      },
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => props.rows, build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="relative" :style="{ height: (Math.max(80, props.rows.length * 60)) + 'px' }">
    <canvas ref="canvas"></canvas>
  </div>
</template>

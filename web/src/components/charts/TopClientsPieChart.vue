<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Chart, DoughnutController, ArcElement, Tooltip, Legend } from 'chart.js'
import type { TopClient } from '@/api/dashboard'
import { formatMoney } from '@/composables/useFormat'
import { useChartColors } from '@/composables/useTheme'

Chart.register(DoughnutController, ArcElement, Tooltip, Legend)

// Po sjednocení na total_czk (CZK přepočet přes i.exchange_rate) už neřešíme currency filter —
// graf vždy ukazuje agregovaný obrat klienta v CZK.
const props = defineProps<{ clients: TopClient[] }>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const { t, locale } = useI18n()
const colors = useChartColors()

const sliceData = computed(() => {
  if (props.clients.length === 0) return { labels: [] as string[], values: [] as number[] }
  const sorted = [...props.clients].sort((a, b) => b.total_czk - a.total_czk)
  const top = sorted.slice(0, 8)
  const rest = sorted.slice(8)
  const labels = top.map(c => c.company_name)
  const values = top.map(c => c.total_czk)
  if (rest.length > 0) {
    labels.push(t('common.other'))
    values.push(rest.reduce((s, c) => s + c.total_czk, 0))
  }
  return { labels, values }
})

function build() {
  if (!canvas.value) return
  if (chart) { chart.destroy(); chart = null }
  const { labels, values } = sliceData.value
  if (labels.length === 0) return
  const total = values.reduce((s, v) => s + v, 0)
  chart = new Chart(canvas.value, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: labels.map((_, i) => colors.value.palette[i % colors.value.palette.length]),
        borderWidth: 1,
        borderColor: colors.value.border,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'right',
          labels: { boxWidth: 12, font: { size: 11 }, color: colors.value.tick },
        },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => {
              const v = ctx.parsed as number
              const pct = total > 0 ? ((v / total) * 100).toFixed(1) : '0'
              return ` ${ctx.label}: ${formatMoney(v, 'CZK')} (${pct} %)`
            },
          },
        },
      },
      cutout: '55%',
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => props.clients, build, { deep: true })
watch(() => locale.value, build)
watch(colors, build)
</script>

<template>
  <div v-if="sliceData.labels.length === 0" class="text-sm text-neutral-400 text-center py-12">
    {{ t('common.no_data') }}
  </div>
  <div v-else class="relative h-64">
    <canvas ref="canvas"></canvas>
  </div>
</template>

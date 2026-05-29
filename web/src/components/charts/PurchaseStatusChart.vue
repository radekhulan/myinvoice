<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Chart, DoughnutController, ArcElement, Tooltip, Legend } from 'chart.js'
import { useChartColors } from '@/composables/useTheme'

Chart.register(DoughnutController, ArcElement, Tooltip, Legend)

/**
 * Doughnut rozpadu stavů přijatých faktur (draft / received / booked / paid / cancelled).
 * Obdoba StatusDoughnutChart, ale s lifecycle a labely přijatých faktur.
 */
const props = defineProps<{ counts: Record<string, number> }>()
const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const { t, locale } = useI18n()
const colors = useChartColors()

const palette: Record<string, string> = {
  paid:      '#4CAF7A',
  booked:    '#5C45A0',
  received:  '#E8A547',
  draft:     '#A7A0BA',
  cancelled: '#D45B5B',
}

function statusLabel(k: string): string {
  return t(`purchase_invoice.status.${k}`)
}

const slice = computed(() => {
  const order = ['paid', 'booked', 'received', 'draft', 'cancelled']
  const labelArr: string[] = []
  const valueArr: number[] = []
  const colorArr: string[] = []
  for (const k of order) {
    const v = props.counts?.[k] ?? 0
    if (v > 0) {
      labelArr.push(statusLabel(k))
      valueArr.push(v)
      colorArr.push(palette[k] || '#A99CD8')
    }
  }
  return { labelArr, valueArr, colorArr }
})

function build() {
  if (!canvas.value) return
  if (chart) { chart.destroy(); chart = null }
  const { labelArr, valueArr, colorArr } = slice.value
  if (labelArr.length === 0) return
  const total = valueArr.reduce((s, v) => s + v, 0)
  chart = new Chart(canvas.value, {
    type: 'doughnut',
    data: {
      labels: labelArr,
      datasets: [{ data: valueArr, backgroundColor: colorArr, borderWidth: 1, borderColor: colors.value.border }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 }, color: colors.value.tick } },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => {
              const v = ctx.parsed as number
              const pct = total > 0 ? ((v / total) * 100).toFixed(1) : '0'
              return ` ${ctx.label}: ${v} (${pct} %)`
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
watch(() => props.counts, build, { deep: true })
watch(() => locale.value, build)
watch(colors, build)
</script>

<template>
  <div v-if="slice.labelArr.length === 0" class="text-sm text-neutral-400 text-center py-12">
    {{ t('common.no_data') }}
  </div>
  <div v-else class="relative h-64">
    <canvas ref="canvas"></canvas>
  </div>
</template>

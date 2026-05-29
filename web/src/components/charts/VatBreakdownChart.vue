<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { Chart, DoughnutController, ArcElement, Tooltip, Legend } from 'chart.js'
import { useChartColors } from '@/composables/useTheme'

Chart.register(DoughnutController, ArcElement, Tooltip, Legend)

/**
 * Rozpad obratu (base bez DPH) podle sazby DPH. Vstup je již agregován per měna —
 * komponenta zobrazí jednu měnu (předanou v `currency` prop).
 */
const props = defineProps<{
  items: Array<{ label: string; base: number; currency: string }>
  currency: string
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()

// Barevné mapování podle obvyklých CZ sazeb (21 % red, 12 % blue, 0 % grey, RC purple).
const palette: Record<string, string> = {
  '21 %': '#D45B5B',
  '12 %': '#5C45A0',
  '15 %': '#4CAF7A',
  '10 %': '#E8A547',
  '0 %':  '#A7A0BA',
  'RC (reverse charge)': '#A99CD8',
}

const filtered = computed(() => props.items.filter(i => i.currency === props.currency && i.base !== 0))

function formatVal(n: number): string {
  return new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(n)
}

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()
  const items = filtered.value
  if (items.length === 0) return

  const total = items.reduce((s, i) => s + i.base, 0)
  const labels = items.map(i => i.label)
  const data = items.map(i => i.base)
  const segmentColors = items.map(i => palette[i.label] ?? '#5C45A0')

  chart = new Chart(canvas.value, {
    type: 'doughnut',
    data: { labels, datasets: [{ data, backgroundColor: segmentColors, borderWidth: 1, borderColor: colors.value.border }] },
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
              return ` ${ctx.label}: ${formatVal(v)} ${props.currency} (${pct} %)`
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
watch(() => [props.items, props.currency], build, { deep: true })
watch(colors, build)
</script>

<template>
  <div v-if="!filtered.length" class="text-sm text-neutral-400 text-center py-12">—</div>
  <div v-else class="relative h-64"><canvas ref="canvas"></canvas></div>
</template>

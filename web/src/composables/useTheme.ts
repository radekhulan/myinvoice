import { computed, watchEffect } from 'vue'
import { useStorage, usePreferredDark } from '@vueuse/core'

/**
 * Barevný režim aplikace: System / Light / Dark.
 *
 * Why: `auto` respektuje OS (prefers-color-scheme), `light`/`dark` ho přebijí.
 * Volba se ukládá do localStorage (klíč musí sedět s anti-FOUC scriptem v index.html).
 * Reaktivně přepíná třídu `.dark` na <html>, na kterou je navázán dark scope v main.css.
 *
 * Stav je modul-level singleton, takže všechny komponenty sdílejí jednu instanci
 * a watchEffect běží jen jednou.
 */
export type ThemePreference = 'auto' | 'light' | 'dark'

export const THEME_STORAGE_KEY = 'myinvoice-color-scheme'

const preference = useStorage<ThemePreference>(THEME_STORAGE_KEY, 'auto')
const prefersDark = usePreferredDark()

/** Co reálně svítí (auto → podle systému). */
const isDark = computed(
  () => preference.value === 'dark' || (preference.value === 'auto' && prefersDark.value),
)

watchEffect(() => {
  document.documentElement.classList.toggle('dark', isDark.value)
})

export function useTheme() {
  return { preference, isDark }
}

/**
 * Barvy pro chart.js — ten nečte CSS proměnné, takže je tu zrcadlíme ručně podle režimu.
 * POZOR: hodnoty musí odpovídat tokenům v styles/main.css (.dark scope) — při změně palety
 * srovnej i tady. Sdílený singleton; v komponentě: const colors = useChartColors() + watch(colors, build).
 */
// Kategorická paleta pro grafy (rozlišení kategorií, ne sémantika). V dark posunutá do
// světlejších indigo tónů, aby nejtmavší segmenty nesplývaly s tmavým pozadím.
const CHART_PALETTE_LIGHT = ['#3B2D83', '#5C45A0', '#6753AE', '#8675C5', '#A99CD8', '#C9C0E9', '#E5E0F4', '#F4A261', '#E8A547', '#4CAF7A']
const CHART_PALETTE_DARK = ['#A99CD8', '#7C68C4', '#C9C0E9', '#8B79C8', '#E5E0F4', '#6753AE', '#D8CEF0', '#F4A261', '#E8A547', '#5FBF8E']

const chartColors = computed(() =>
  isDark.value
    ? { border: '#1E1B2B', tick: '#A8A1BE', grid: '#2C2840', tooltipBg: '#322C4A', primary: '#7C68C4', primarySoft: '#A99CD8', palette: CHART_PALETTE_DARK }
    : { border: '#FFFFFF', tick: '#5A5470', grid: '#E7E3EE', tooltipBg: '#15131D', primary: '#5C45A0', primarySoft: '#A99CD8', palette: CHART_PALETTE_LIGHT },
)

export function useChartColors() {
  return chartColors
}

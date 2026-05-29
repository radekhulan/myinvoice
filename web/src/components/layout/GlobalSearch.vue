<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { searchApi, type SearchResults } from '@/api/search'

interface MenuItem { to: string; label: string; icon: string; external?: boolean }
const props = defineProps<{ menuItems: MenuItem[] }>()
const emit = defineEmits<{ navigated: [] }>()

const { t } = useI18n()
const router = useRouter()

const q = ref('')
const open = ref(false)
const loading = ref(false)
const activeIndex = ref(0)
const results = ref<SearchResults>({ q: '', clients: [], invoices: [], purchase_invoices: [] })
const inputEl = ref<HTMLInputElement | null>(null)

let debounceTimer: ReturnType<typeof setTimeout> | undefined
let seq = 0

interface Option { kind: 'menu' | 'client' | 'invoice' | 'purchase'; label: string; sub: string; run: () => void }

const GROUP_LABEL: Record<Option['kind'], string> = {
  menu:     'search.group_menu',
  client:   'search.group_clients',
  invoice:  'search.group_invoices',
  purchase: 'search.group_purchase',
}

const menuMatches = computed<MenuItem[]>(() => {
  const needle = q.value.trim().toLowerCase()
  if (!needle) return []
  return props.menuItems.filter(m => m.label.toLowerCase().includes(needle)).slice(0, 5)
})

const options = computed<Option[]>(() => {
  const out: Option[] = []
  for (const m of menuMatches.value) {
    out.push({ kind: 'menu', label: m.label, sub: '',
      run: () => { m.external ? window.open(m.to, '_blank', 'noopener') : router.push(m.to) } })
  }
  for (const c of results.value.clients) {
    out.push({ kind: 'client', label: c.company_name, sub: c.main_email || '',
      run: () => router.push(`/clients/${c.id}`) })
  }
  for (const i of results.value.invoices) {
    out.push({ kind: 'invoice', label: i.varsymbol || `#${i.id}`, sub: i.company_name,
      run: () => router.push(`/invoices/${i.id}`) })
  }
  for (const p of results.value.purchase_invoices) {
    out.push({ kind: 'purchase', label: p.varsymbol || p.vendor_invoice_number || `#${p.id}`, sub: p.company_name,
      run: () => router.push(`/purchase-invoices/${p.id}`) })
  }
  return out
})

watch(q, (val) => {
  open.value = val.trim().length > 0
  activeIndex.value = 0
  clearTimeout(debounceTimer)
  const needle = val.trim()
  if (needle.length < 2) {
    results.value = { q: needle, clients: [], invoices: [], purchase_invoices: [] }
    loading.value = false
    return
  }
  loading.value = true
  debounceTimer = setTimeout(async () => {
    const mySeq = ++seq
    try {
      const r = await searchApi.query(needle)
      if (mySeq === seq) results.value = r
    } catch {
      if (mySeq === seq) results.value = { q: needle, clients: [], invoices: [], purchase_invoices: [] }
    } finally {
      if (mySeq === seq) loading.value = false
    }
  }, 250)
})

function select(i: number) {
  const opt = options.value[i]
  if (!opt) return
  opt.run()
  reset()
  emit('navigated')
}

function reset() {
  q.value = ''
  open.value = false
  activeIndex.value = 0
  results.value = { q: '', clients: [], invoices: [], purchase_invoices: [] }
  inputEl.value?.blur()
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    if (options.value.length) activeIndex.value = Math.min(activeIndex.value + 1, options.value.length - 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    activeIndex.value = Math.max(0, activeIndex.value - 1)
  } else if (e.key === 'Enter') {
    if (options.value.length) { e.preventDefault(); select(activeIndex.value) }
  } else if (e.key === 'Escape') {
    reset()
  }
}

/** Zobraz hlavičku skupiny, když se kind liší od předchozí položky. */
function groupHeaderFor(i: number): string | null {
  const opt = options.value[i]
  if (!opt) return null
  if (i === 0 || options.value[i - 1].kind !== opt.kind) return t(GROUP_LABEL[opt.kind])
  return null
}

const showDropdown = computed(() => open.value && q.value.trim().length > 0)
const hasResults = computed(() => options.value.length > 0)

onBeforeUnmount(() => clearTimeout(debounceTimer))
</script>

<template>
  <div class="relative px-0.5 pb-2">
    <div class="relative">
      <svg class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-neutral-400 pointer-events-none"
           fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z" />
      </svg>
      <input
        ref="inputEl"
        v-model="q"
        type="text"
        :placeholder="t('search.placeholder')"
        class="w-full pl-8 pr-3 py-1.5 text-sm rounded-md border border-neutral-200 bg-neutral-50 focus:bg-surface focus:border-primary-400 focus:outline-none focus:ring-1 focus:ring-primary-200"
        autocomplete="off"
        spellcheck="false"
        @focus="open = q.trim().length > 0"
        @blur="open = false"
        @keydown="onKeydown"
      />
    </div>

    <!-- Dropdown výsledků -->
    <div
      v-if="showDropdown"
      class="absolute left-0 right-0 mt-1 z-40 bg-surface border border-neutral-200 rounded-md shadow-lg max-h-[70vh] overflow-y-auto"
    >
      <div v-if="loading && !hasResults" class="px-3 py-2 text-xs text-neutral-500">{{ t('common.loading') }}</div>
      <div v-else-if="!hasResults" class="px-3 py-2 text-xs text-neutral-500">{{ t('search.no_results') }}</div>

      <template v-for="(opt, i) in options" :key="opt.kind + ':' + i">
        <div v-if="groupHeaderFor(i)"
             class="px-3 pt-2 pb-1 text-[10px] font-bold uppercase tracking-wider text-neutral-400">
          {{ groupHeaderFor(i) }}
        </div>
        <button
          type="button"
          class="w-full text-left px-3 py-1.5 flex flex-col gap-0.5 cursor-pointer"
          :class="i === activeIndex ? 'bg-primary-50' : 'hover:bg-neutral-50'"
          @mouseenter="activeIndex = i"
          @mousedown.prevent="select(i)"
        >
          <span class="text-sm text-neutral-800 truncate">{{ opt.label }}</span>
          <span v-if="opt.sub" class="text-xs text-neutral-500 truncate">{{ opt.sub }}</span>
        </button>
      </template>
    </div>
  </div>
</template>

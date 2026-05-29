<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { documentsApi, type EntityType, type LinkSearchResult } from '@/api/documents'

const props = withDefaults(defineProps<{
  types?: EntityType[]
  autofocus?: boolean
}>(), { autofocus: false })

const emit = defineEmits<{ (e: 'select', r: LinkSearchResult): void }>()

const { t } = useI18n()

const query = ref('')
const results = ref<LinkSearchResult[]>([])
const loading = ref(false)
const open = ref(false)
const activeIndex = ref(-1)

let debounce: ReturnType<typeof setTimeout> | null = null
let reqSeq = 0

const TYPE_STYLE: Record<EntityType, string> = {
  invoice:          'bg-primary-50 text-primary-700',
  purchase_invoice: 'bg-warning-50 text-warning-600',
  client:           'bg-success-50 text-success-600',
  project:          'bg-neutral-100 text-neutral-600',
}

function typeLabel(tp: EntityType): string {
  return t(`documents.entity.${tp}`)
}

watch(query, (q) => {
  if (debounce) clearTimeout(debounce)
  activeIndex.value = -1
  if (q.trim().length < 2) {
    results.value = []
    open.value = q.trim().length > 0
    return
  }
  debounce = setTimeout(async () => {
    const seq = ++reqSeq
    loading.value = true
    try {
      const r = await documentsApi.linkSearch(q.trim(), props.types)
      if (seq === reqSeq) { results.value = r; open.value = true }
    } catch {
      if (seq === reqSeq) results.value = []
    } finally {
      if (seq === reqSeq) loading.value = false
    }
  }, 220)
})

function choose(r: LinkSearchResult) {
  emit('select', r)
  query.value = ''
  results.value = []
  open.value = false
  activeIndex.value = -1
}

function onKeydown(e: KeyboardEvent) {
  if (!open.value && (e.key === 'ArrowDown')) { open.value = true; return }
  if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex.value = Math.min(activeIndex.value + 1, results.value.length - 1) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex.value = Math.max(activeIndex.value - 1, 0) }
  else if (e.key === 'Enter') {
    if (activeIndex.value >= 0 && results.value[activeIndex.value]) {
      e.preventDefault(); choose(results.value[activeIndex.value])
    }
  } else if (e.key === 'Escape') { open.value = false }
}

function onBlur() {
  // Zpoždění, ať proběhne mousedown na položce.
  setTimeout(() => { open.value = false }, 150)
}

onBeforeUnmount(() => { if (debounce) clearTimeout(debounce) })
</script>

<template>
  <div class="relative">
    <div class="relative">
      <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14z" />
      </svg>
      <input
        v-model="query"
        type="text"
        :autofocus="autofocus"
        class="w-full pl-9 pr-9 py-2 text-sm border border-neutral-300 rounded-lg focus:ring-2 focus:ring-primary-200 focus:border-primary-400 outline-none"
        :placeholder="t('documents.link_search_placeholder')"
        @keydown="onKeydown"
        @focus="open = results.length > 0 || query.length >= 2"
        @blur="onBlur"
      />
      <svg v-if="loading" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-primary-500 animate-spin" viewBox="0 0 24 24" fill="none">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4z" />
      </svg>
    </div>

    <div
      v-if="open"
      class="absolute z-30 mt-1 w-full bg-surface border border-neutral-200 rounded-lg shadow-lg max-h-80 overflow-auto"
    >
      <div v-if="query.trim().length < 2" class="px-3 py-3 text-sm text-neutral-400">
        {{ t('documents.search_hint') }}
      </div>
      <div v-else-if="results.length === 0 && !loading" class="px-3 py-3 text-sm text-neutral-400">
        {{ t('documents.search_no_results') }}
      </div>
      <ul v-else class="py-1">
        <li
          v-for="(r, i) in results"
          :key="r.entity_type + '-' + r.entity_id"
          :class="['flex items-center gap-3 px-3 py-2 cursor-pointer', i === activeIndex ? 'bg-primary-50' : 'hover:bg-neutral-50']"
          @mousedown.prevent="choose(r)"
          @mouseenter="activeIndex = i"
        >
          <span :class="['shrink-0 px-1.5 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide', TYPE_STYLE[r.entity_type]]">
            {{ typeLabel(r.entity_type) }}
          </span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-medium text-neutral-800 truncate">{{ r.label }}</span>
            <span v-if="r.sublabel" class="block text-xs text-neutral-500 truncate">{{ r.sublabel }}</span>
          </span>
          <span v-if="r.meta" class="shrink-0 text-xs text-neutral-400 tabular-nums">{{ r.meta }}</span>
        </li>
      </ul>
    </div>
  </div>
</template>

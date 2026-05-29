<script setup lang="ts" generic="T extends string | number">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'

type Option = { value: T; label: string; secondary?: string }

const props = withDefaults(defineProps<{
  modelValue: T | null
  options: Option[]
  placeholder?: string
  emptyLabel?: string
  noResultsLabel?: string
  clearable?: boolean
  disabled?: boolean
}>(), {
  placeholder: '',
  emptyLabel: '',
  noResultsLabel: 'Žádné výsledky',
  clearable: true,
  disabled: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: T | null]
}>()

const root = ref<HTMLDivElement | null>(null)
const input = ref<HTMLInputElement | null>(null)
const listbox = ref<HTMLDivElement | null>(null)
const open = ref(false)
const query = ref('')
const highlightIdx = ref(0)

const selected = computed(() =>
  props.modelValue !== null && props.modelValue !== undefined
    ? props.options.find(o => o.value === props.modelValue) ?? null
    : null
)

// when query je prázdný (nebo přesně shoduje s vybraným labelem), ukazujeme všechny;
// jinak filtrujeme case-insensitively (substring match v label i secondary)
const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q || (selected.value && q === selected.value.label.toLowerCase())) {
    return props.options
  }
  return props.options.filter(o =>
    o.label.toLowerCase().includes(q) ||
    (o.secondary?.toLowerCase().includes(q) ?? false)
  )
})

watch(selected, (s) => {
  query.value = s?.label ?? ''
}, { immediate: true })

watch(open, (o) => {
  if (o) {
    highlightIdx.value = Math.max(0, filtered.value.findIndex(opt => opt.value === props.modelValue))
    nextTick(() => {
      input.value?.select()
    })
  }
})

function selectOption(o: Option) {
  emit('update:modelValue', o.value)
  query.value = o.label
  open.value = false
}

function clear() {
  emit('update:modelValue', null)
  query.value = ''
  open.value = false
  input.value?.focus()
}

function onFocus() {
  if (!props.disabled) open.value = true
}

function onInput() {
  open.value = true
  highlightIdx.value = 0
}

function onKey(e: KeyboardEvent) {
  if (props.disabled) return
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    open.value = true
    highlightIdx.value = Math.min(highlightIdx.value + 1, filtered.value.length - 1)
    scrollHighlightIntoView()
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    highlightIdx.value = Math.max(highlightIdx.value - 1, 0)
    scrollHighlightIntoView()
  } else if (e.key === 'Enter') {
    if (open.value && filtered.value[highlightIdx.value]) {
      e.preventDefault()
      selectOption(filtered.value[highlightIdx.value])
    }
  } else if (e.key === 'Escape') {
    open.value = false
    // resetuj query na vybraný label, kdyby uživatel měnil text bez výběru
    query.value = selected.value?.label ?? ''
    input.value?.blur()
  }
}

function scrollHighlightIntoView() {
  nextTick(() => {
    const el = listbox.value?.querySelector<HTMLElement>(`[data-idx="${highlightIdx.value}"]`)
    el?.scrollIntoView({ block: 'nearest' })
  })
}

function onClickOutside(e: MouseEvent) {
  if (!root.value) return
  if (!root.value.contains(e.target as Node)) {
    open.value = false
    // při zavření bez výběru: pokud query neshodí s vybraným, vrať na vybraný label
    query.value = selected.value?.label ?? ''
  }
}

onMounted(() => {
  document.addEventListener('mousedown', onClickOutside)
})
onUnmounted(() => {
  document.removeEventListener('mousedown', onClickOutside)
})
</script>

<template>
  <div ref="root" class="relative">
    <div class="relative">
      <input
        ref="input"
        v-model="query"
        type="text"
        role="combobox"
        :aria-expanded="open"
        :aria-controls="open ? 'searchable-select-listbox' : undefined"
        :placeholder="placeholder"
        :disabled="disabled"
        autocomplete="off"
        :class="[
          'w-full h-10 pl-3 pr-16 border border-neutral-300 rounded-md text-sm bg-surface',
          'focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none',
          'disabled:bg-neutral-50 disabled:text-neutral-400',
        ]"
        @focus="onFocus"
        @input="onInput"
        @keydown="onKey"
      />
      <button
        v-if="clearable && modelValue !== null && modelValue !== undefined && !disabled"
        type="button"
        @click="clear"
        class="cursor-pointer absolute right-7 top-1/2 -translate-y-1/2 w-6 h-6 inline-flex items-center justify-center text-neutral-400 hover:text-neutral-700 text-lg leading-none"
        :aria-label="'Zrušit výběr'"
      >×</button>
      <span class="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 pointer-events-none text-xs">▼</span>
    </div>
    <div
      v-if="open"
      ref="listbox"
      id="searchable-select-listbox"
      role="listbox"
      class="absolute z-50 left-0 right-0 mt-1 bg-surface border border-neutral-200 rounded-md shadow-lg max-h-72 overflow-y-auto"
    >
      <div v-if="filtered.length === 0" class="px-3 py-2 text-sm text-neutral-400">
        {{ noResultsLabel }}
      </div>
      <button
        v-for="(o, i) in filtered"
        :key="String(o.value)"
        :data-idx="i"
        role="option"
        :aria-selected="o.value === modelValue"
        type="button"
        @click="selectOption(o)"
        @mouseenter="highlightIdx = i"
        :class="[
          'cursor-pointer w-full text-left px-3 py-2 text-sm',
          i === highlightIdx ? 'bg-primary-50' : 'hover:bg-neutral-50',
          o.value === modelValue ? 'font-medium text-primary-700' : 'text-neutral-900',
        ]"
      >
        <div class="truncate">{{ o.label }}</div>
        <div v-if="o.secondary" class="text-xs text-neutral-500 truncate">{{ o.secondary }}</div>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { documentsApi, type TagInfo } from '@/api/documents'

const props = defineProps<{ modelValue: string[]; autofocus?: boolean }>()
const emit = defineEmits<{ (e: 'update:modelValue', v: string[]): void }>()

const { t } = useI18n()

const input = ref('')
const inputEl = ref<HTMLInputElement | null>(null)
const allTags = ref<TagInfo[]>([])
const open = ref(false)
const activeIndex = ref(-1)

onMounted(async () => {
  try { allTags.value = await documentsApi.tags() } catch { /* ignore */ }
  if (props.autofocus) inputEl.value?.focus()
})

const suggestions = computed(() => {
  const q = input.value.trim().toLowerCase()
  const selected = new Set(props.modelValue.map(t => t.toLowerCase()))
  return allTags.value
    .filter(tg => !selected.has(tg.name.toLowerCase()))
    .filter(tg => q === '' || tg.name.toLowerCase().includes(q))
    .slice(0, 8)
})

function add(name: string) {
  const v = name.trim()
  if (!v) return
  if (!props.modelValue.some(t => t.toLowerCase() === v.toLowerCase())) {
    emit('update:modelValue', [...props.modelValue, v])
  }
  input.value = ''
  activeIndex.value = -1
}
function remove(tag: string) {
  emit('update:modelValue', props.modelValue.filter(t => t !== tag))
}
function onEnter() {
  if (activeIndex.value >= 0 && suggestions.value[activeIndex.value]) {
    add(suggestions.value[activeIndex.value].name)
  } else {
    add(input.value)
  }
}
function onBlur() {
  setTimeout(() => { open.value = false }, 150)
}
function onKeydown(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') { e.preventDefault(); open.value = true; activeIndex.value = Math.min(activeIndex.value + 1, suggestions.value.length - 1) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex.value = Math.max(activeIndex.value - 1, -1) }
  else if (e.key === 'Backspace' && input.value === '' && props.modelValue.length) { remove(props.modelValue[props.modelValue.length - 1]) }
}
</script>

<template>
  <div class="relative">
    <div class="flex flex-wrap items-center gap-1 px-2 py-1.5 border border-neutral-300 rounded-md focus-within:ring-2 focus-within:ring-primary-500/20 focus-within:border-primary-500 min-h-9">
      <span v-for="tg in modelValue" :key="tg" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-primary-50 text-primary-700 text-xs">
        {{ tg }}
        <button type="button" class="hover:text-danger-500" @click="remove(tg)">×</button>
      </span>
      <input
        ref="inputEl"
        v-model="input"
        type="text"
        class="flex-1 min-w-24 text-sm outline-none bg-transparent"
        :placeholder="modelValue.length ? '' : t('documents.tags_placeholder')"
        @focus="open = true"
        @blur="onBlur"
        @keydown.enter.prevent="onEnter"
        @keydown="onKeydown"
      />
    </div>
    <ul v-if="open && suggestions.length" class="absolute z-30 mt-1 w-full bg-surface border border-neutral-200 rounded-lg shadow-lg max-h-56 overflow-auto py-1">
      <li
        v-for="(s, i) in suggestions"
        :key="s.id"
        :class="['flex items-center justify-between px-3 py-1.5 text-sm cursor-pointer', i === activeIndex ? 'bg-primary-50' : 'hover:bg-neutral-50']"
        @mousedown.prevent="add(s.name)"
        @mouseenter="activeIndex = i"
      >
        <span class="text-neutral-700">{{ s.name }}</span>
        <span class="text-xs text-neutral-400">{{ s.usage_count }}</span>
      </li>
    </ul>
  </div>
</template>

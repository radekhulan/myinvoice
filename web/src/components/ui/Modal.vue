<script setup lang="ts">
import { onMounted, onBeforeUnmount } from 'vue'

/**
 * Generic modal — backdrop, ESC close, click-outside close, sticky header.
 * Tělo se scrolluje, hlavička zůstává. Šířka přes `widthClass` (Tailwind utility).
 */
const props = withDefaults(defineProps<{
  title: string
  widthClass?: string
}>(), {
  widthClass: 'max-w-3xl',
})

const emit = defineEmits<{
  (e: 'close'): void
}>()

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}

onMounted(() => {
  document.addEventListener('keydown', onKey)
  // Zamkni body scroll dokud je modal otevřený.
  document.body.style.overflow = 'hidden'
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  document.body.style.overflow = ''
})

void props
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
      @click.self="emit('close')">
      <div class="bg-surface rounded-lg shadow-xl w-full flex flex-col max-h-[90vh]" :class="widthClass">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between shrink-0">
          <h2 class="text-lg font-semibold">{{ title }}</h2>
          <button type="button" @click="emit('close')" aria-label="Close"
            class="cursor-pointer w-8 h-8 inline-flex items-center justify-center rounded-md text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>
        <div class="overflow-y-auto flex-1 p-5">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>

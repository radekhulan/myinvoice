<script setup lang="ts">
import { useI18n } from 'vue-i18n'
defineProps<{ title?: string }>()
const { t } = useI18n()
</script>

<template>
  <div class="min-h-screen flex flex-col bg-neutral-50">
    <header class="border-b border-neutral-200 bg-surface">
      <div class="max-w-6xl mx-auto px-6 py-4 flex items-center gap-3">
        <img src="/styles/logo.svg" alt="MyInvoice" class="w-9 h-9" />
        <div>
          <h1 class="text-lg font-semibold leading-tight">
            My<span class="text-primary-600">Invoice</span><span class="text-neutral-500 font-normal">.cz</span>
          </h1>
          <p class="text-xs text-neutral-500 leading-tight">{{ title ?? t('app.subtitle') }}</p>
        </div>
      </div>
    </header>
    <main class="flex-1 flex items-center justify-center px-4 py-8">
      <slot />
    </main>
  </div>
</template>

<script setup lang="ts">
import { RouterView } from 'vue-router'
import Toaster from '@/components/Toaster.vue'
import { useTheme } from '@/composables/useTheme'

// Inicializace barevného režimu (System/Light/Dark) — aplikuje .dark na <html>.
useTheme()
</script>

<template>
  <RouterView />
  <Toaster />
</template>

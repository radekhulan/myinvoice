<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useTheme, type ThemePreference } from '@/composables/useTheme'

const { t } = useI18n()
const { preference } = useTheme()

/** Heroicons outline (stroke 2, viewBox 24): monitor = System, slunce = Light, měsíc = Dark. */
const OPTIONS: { value: ThemePreference; key: string; icon: string }[] = [
  {
    value: 'auto',
    key: 'theme.system',
    icon: 'M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25',
  },
  {
    value: 'light',
    key: 'theme.light',
    icon: 'M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0z',
  },
  {
    value: 'dark',
    key: 'theme.dark',
    icon: 'M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998z',
  },
]
</script>

<template>
  <div
    class="hidden sm:inline-flex items-center border border-neutral-200 rounded-md overflow-hidden"
    role="group"
    :aria-label="t('theme.label')"
  >
    <button
      v-for="(opt, i) in OPTIONS"
      :key="opt.value"
      type="button"
      @click="preference = opt.value"
      :title="t(opt.key)"
      :aria-label="t(opt.key)"
      :aria-pressed="preference === opt.value"
      class="cursor-pointer h-8 px-2 inline-flex items-center"
      :class="[
        i > 0 ? 'border-l border-neutral-200' : '',
        preference === opt.value
          ? 'bg-primary-50 text-primary-700'
          : 'text-neutral-500 hover:bg-neutral-50 hover:text-neutral-700',
      ]"
    >
      <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" :d="opt.icon" />
      </svg>
    </button>
  </div>
</template>

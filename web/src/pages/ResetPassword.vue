<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/layout/AppShell.vue'
import { authApi } from '@/api/auth'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const token = ref('')
const password = ref('')
const passwordConfirm = ref('')
const submitting = ref(false)
const success = ref(false)
const error = ref('')

onMounted(() => {
  token.value = (route.query.token as string) || ''
  if (!token.value) error.value = t('errors.generic')
})

const passwordOk = computed(() => password.value.length >= 12)
const passwordMatch = computed(() => password.value === passwordConfirm.value)
const valid = computed(() => passwordOk.value && passwordMatch.value && token.value)

async function submit() {
  submitting.value = true
  error.value = ''
  try {
    await authApi.reset(token.value, password.value)
    success.value = true
    setTimeout(() => router.push('/login'), 2000)
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('errors.generic')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AppShell :title="t('auth.reset_title')">
    <div class="w-full max-w-sm">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-6">
        <h2 class="text-xl font-semibold mb-1">{{ t('auth.reset_title') }}</h2>

        <div v-if="success" class="rounded-md bg-primary-50 border border-primary-200 p-4 text-sm text-primary-800 mt-4">
          {{ t('common.success') }}…
        </div>

        <form v-else @submit.prevent="submit" class="space-y-4 mt-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password') }}</label>
            <input v-model="password" type="password" required autocomplete="new-password" class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            <p class="text-xs text-neutral-500 mt-1" :class="{ 'text-danger-500': password && !passwordOk }">{{ t('auth.min_chars', { n: 12 }) }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password_confirm') }}</label>
            <input v-model="passwordConfirm" type="password" required autocomplete="new-password" class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            <p v-if="passwordConfirm && !passwordMatch" class="text-xs text-danger-500 mt-1">{{ t('auth.passwords_dont_match') }}</p>
          </div>

          <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
            {{ error }}
          </div>

          <button type="submit" :disabled="!valid || submitting" class="w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ submitting ? t('common.saving') : t('common.save') }}
          </button>
        </form>
      </div>
    </div>
  </AppShell>
</template>

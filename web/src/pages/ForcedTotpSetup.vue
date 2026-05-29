<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/layout/AppShell.vue'
import { authApi, type TotpSetup } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const setup = ref<TotpSetup | null>(null)
const code = ref('')
const busy = ref(false)
const error = ref('')

async function startSetup() {
  busy.value = true
  error.value = ''
  try {
    setup.value = await authApi.totpSetup()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    busy.value = false
  }
}

async function activate() {
  if (!/^\d{6}$/.test(code.value)) {
    error.value = t('auth.totp_invalid')
    return
  }
  busy.value = true
  error.value = ''
  try {
    await authApi.totpEnable(code.value)
    await auth.refresh()
    router.replace('/')
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('auth.totp_invalid')
  } finally {
    busy.value = false
  }
}

async function logout() {
  await auth.logout()
  router.replace('/login')
}

onMounted(() => {
  startSetup()
})
</script>

<template>
  <AppShell :title="t('auth.totp_force_title')">
    <div class="w-full max-w-md">
      <div class="bg-surface border border-warning-300 rounded-lg shadow-sm p-6 space-y-5">
        <div class="flex items-start gap-3">
          <svg class="w-6 h-6 text-warning-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
          </svg>
          <div>
            <h1 class="text-lg font-semibold text-warning-600">{{ t('auth.totp_force_title') }}</h1>
            <p class="text-sm text-warning-600 mt-1">{{ t('auth.totp_force_intro') }}</p>
          </div>
        </div>

        <div v-if="setup" class="space-y-4 pt-2 border-t border-neutral-200">
          <p class="text-sm text-neutral-700">{{ t('auth.totp_setup_step1') }}</p>
          <div class="flex justify-center bg-neutral-50 rounded-md p-4">
            <img :src="setup.qr_data_uri" :alt="setup.uri" class="border border-neutral-200 rounded" />
          </div>

          <details class="text-xs text-neutral-500">
            <summary class="cursor-pointer hover:text-neutral-700">{{ t('auth.totp_setup_step1_alt') }}</summary>
            <code class="block mt-2 p-2 bg-neutral-100 rounded font-mono text-xs break-all select-all">{{ setup.secret }}</code>
          </details>

          <div class="pt-2">
            <p class="text-sm text-neutral-700 mb-2">{{ t('auth.totp_setup_step2') }}</p>
            <input
              v-model="code"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="6"
              pattern="\d{6}"
              placeholder="000000"
              autofocus
              class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-lg tracking-widest text-center"
              @keydown.enter="activate"
            />
          </div>

          <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
            {{ error }}
          </div>

          <button
            @click="activate"
            :disabled="busy || code.length !== 6"
            class="w-full h-10 bg-primary-600 hover:bg-primary-700 active:bg-primary-800 disabled:bg-neutral-300 disabled:cursor-not-allowed text-white font-medium rounded-md transition"
          >
            {{ busy ? '…' : t('auth.totp_force_enable_btn') }}
          </button>
        </div>

        <div v-else-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </div>

        <div v-else class="text-center text-sm text-neutral-500 py-4">
          {{ t('common.loading') }}…
        </div>

        <div class="pt-4 border-t border-neutral-200 flex justify-between items-center">
          <p class="text-xs text-neutral-500">{{ t('auth.totp_force_logout_hint') }}</p>
          <button
            type="button"
            @click="logout"
            class="text-sm text-neutral-600 hover:text-neutral-800 underline"
          >
            {{ t('auth.logout') }}
          </button>
        </div>
      </div>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { authApi, type TotpSetup } from '@/api/auth'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()

const status = ref<{ enabled: boolean } | null>(null)
const setup = ref<TotpSetup | null>(null)
const code = ref('')
const busy = ref(false)
const error = ref('')

async function loadStatus() {
  status.value = await authApi.totpStatus()
}

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
    toast.success(t('auth.totp_enabled_done'))
    setup.value = null
    code.value = ''
    await loadStatus()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('auth.totp_invalid')
  } finally {
    busy.value = false
  }
}

onMounted(loadStatus)
</script>

<template>
  <div class="max-w-xl">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ t('auth.totp_2fa') }}</h1>
      <RouterLink to="/profile/api-tokens" class="text-sm text-primary-600 hover:underline">
        {{ t('api_tokens.title') }} →
      </RouterLink>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg p-6 shadow-sm space-y-4">
      <div v-if="status">
        <div v-if="status.enabled" class="flex items-center gap-2 text-success-600">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          <span class="font-medium">{{ t('auth.totp_status_enabled') }}</span>
        </div>
        <div v-else class="flex items-center gap-2 text-neutral-500">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
          <span>{{ t('auth.totp_status_disabled') }}</span>
        </div>
      </div>

      <p v-if="status?.enabled" class="text-xs text-neutral-500">
        {{ t('auth.totp_disable_hint') }}
      </p>

      <button
        v-if="status && !status.enabled && !setup"
        @click="startSetup"
        :disabled="busy"
        class="cursor-pointer h-10 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md"
      >
        {{ busy ? '…' : t('auth.totp_setup_btn') }}
      </button>

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
            maxlength="6"
            pattern="\d{6}"
            placeholder="000000"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-lg tracking-widest text-center"
            @keydown.enter="activate"
          />
        </div>

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </div>

        <div class="flex justify-end gap-2">
          <button @click="setup = null; code = ''; error = ''"
            class="cursor-pointer h-10 px-4 border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button
            @click="activate"
            :disabled="busy || code.length !== 6"
            class="cursor-pointer h-10 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md"
          >
            {{ busy ? '…' : t('auth.totp_enable_btn') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

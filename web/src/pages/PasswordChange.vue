<script setup lang="ts">
/**
 * Profil uživatele — záložky:
 *   - Heslo  (změna hesla, self-service)
 *   - 2FA    (TOTP setup, status, aktivace)
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { authApi, type TotpSetup } from '@/api/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()

type Tab = 'password' | 'totp'
const tab = ref<Tab>((route.query.tab === 'totp' ? 'totp' : 'password') as Tab)
function setTab(t: Tab) {
  tab.value = t
  router.replace({ query: { ...route.query, tab: t === 'password' ? undefined : t } })
}

// ── Heslo ─────────────────────────────────────────────────────────────
const MIN_LEN = 12
const MAX_LEN = 128
const current = ref('')
const next = ref('')
const confirm = ref('')
const showCurrent = ref(false)
const showNext = ref(false)
const pwBusy = ref(false)
const pwError = ref('')
const nextLenOk = computed(() => next.value.length >= MIN_LEN && next.value.length <= MAX_LEN)
const matchOk = computed(() => next.value !== '' && next.value === confirm.value)
const canSubmitPw = computed(() =>
  current.value !== '' && nextLenOk.value && matchOk.value && !pwBusy.value
)

async function submitPw() {
  if (!canSubmitPw.value) return
  pwError.value = ''
  pwBusy.value = true
  try {
    await authApi.changePassword(current.value, next.value)
    toast.success(t('auth.password_changed'))
    current.value = ''
    next.value = ''
    confirm.value = ''
  } catch (e: any) {
    pwError.value = apiErrorMessage(e, t('auth.password_change_failed'))
  } finally {
    pwBusy.value = false
  }
}

// ── TOTP / 2FA ────────────────────────────────────────────────────────
const totpStatus = ref<{ enabled: boolean } | null>(null)
const totpSetup = ref<TotpSetup | null>(null)
const totpCode = ref('')
const totpBusy = ref(false)
const totpError = ref('')

async function loadTotpStatus() {
  try {
    totpStatus.value = await authApi.totpStatus()
  } catch (e: any) {
    totpError.value = apiErrorMessage(e, t('common.error'))
  }
}

async function startTotpSetup() {
  totpBusy.value = true
  totpError.value = ''
  try {
    totpSetup.value = await authApi.totpSetup()
  } catch (e: any) {
    totpError.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    totpBusy.value = false
  }
}

async function activateTotp() {
  if (!/^\d{6}$/.test(totpCode.value)) {
    totpError.value = t('auth.totp_invalid')
    return
  }
  totpBusy.value = true
  totpError.value = ''
  try {
    await authApi.totpEnable(totpCode.value)
    toast.success(t('auth.totp_enabled_done'))
    totpSetup.value = null
    totpCode.value = ''
    await loadTotpStatus()
  } catch (e: any) {
    totpError.value = e?.response?.data?.error?.message || t('auth.totp_invalid')
  } finally {
    totpBusy.value = false
  }
}

function cancelTotpSetup() {
  totpSetup.value = null
  totpCode.value = ''
  totpError.value = ''
}

onMounted(() => {
  loadTotpStatus()
})
</script>

<template>
  <div class="max-w-2xl mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('auth.profile_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('auth.profile_subtitle') }}</p>
    </div>

    <!-- Tabs -->
    <div class="border-b border-neutral-200 mb-4 flex gap-1">
      <button type="button" @click="setTab('password')"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition"
        :class="tab === 'password'
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t('auth.change_password_title') }}
      </button>
      <button type="button" @click="setTab('totp')"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition inline-flex items-center gap-2"
        :class="tab === 'totp'
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t('auth.totp_2fa') }}
        <span v-if="totpStatus?.enabled"
              class="text-[10px] uppercase bg-success-50 text-success-600 border border-success-500/40 px-1.5 py-0.5 rounded">
          {{ t('auth.totp_badge_on') }}
        </span>
      </button>
    </div>

    <!-- ── Heslo ── -->
    <form v-if="tab === 'password'" @submit.prevent="submitPw"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.current_password') }} *</label>
        <div class="flex gap-2">
          <input v-model="current" :type="showCurrent ? 'text' : 'password'"
                 autocomplete="current-password" required
                 class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          <button type="button" @click="showCurrent = !showCurrent"
                  class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
            {{ showCurrent ? '🙈' : '👁' }}
          </button>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password') }} *</label>
        <div class="flex gap-2">
          <input v-model="next" :type="showNext ? 'text' : 'password'"
                 autocomplete="new-password" :minlength="MIN_LEN" :maxlength="MAX_LEN" required
                 class="flex-1 h-10 px-3 border rounded-md text-sm"
                 :class="next === '' ? 'border-neutral-300' : nextLenOk ? 'border-success-500/40' : 'border-warning-500/40'" />
          <button type="button" @click="showNext = !showNext"
                  class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
            {{ showNext ? '🙈' : '👁' }}
          </button>
        </div>
        <p class="text-xs mt-1"
           :class="next === '' ? 'text-neutral-500' : nextLenOk ? 'text-success-600' : 'text-warning-600'">
          {{ t('auth.password_min_hint', { n: MIN_LEN }) }}
          <span v-if="next !== ''">— {{ next.length }}/{{ MAX_LEN }}</span>
        </p>
      </div>

      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password_confirm') }} *</label>
        <input v-model="confirm" :type="showNext ? 'text' : 'password'"
               autocomplete="new-password" required
               class="w-full h-10 px-3 border rounded-md text-sm"
               :class="confirm === '' ? 'border-neutral-300' : matchOk ? 'border-success-500/40' : 'border-danger-500/40'" />
        <p v-if="confirm !== '' && !matchOk" class="text-xs text-danger-500 mt-1">
          {{ t('auth.password_mismatch') }}
        </p>
      </div>

      <div v-if="pwError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ pwError }}
      </div>

      <div class="flex items-center justify-between pt-2 border-t border-neutral-100">
        <button type="button" @click="router.back()"
                class="cursor-pointer h-10 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">
          {{ t('common.back') }}
        </button>
        <button type="submit" :disabled="!canSubmitPw"
                class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ pwBusy ? t('common.saving') : t('auth.change_password_submit') }}
        </button>
      </div>

      <p class="text-xs text-neutral-500 pt-2">
        ℹ {{ t('auth.password_change_note') }}
      </p>
    </form>

    <!-- ── 2FA / TOTP ── -->
    <div v-else class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
      <div v-if="totpStatus">
        <div v-if="totpStatus.enabled" class="flex items-center gap-2 text-success-600">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          <span class="font-medium">{{ t('auth.totp_status_enabled') }}</span>
        </div>
        <div v-else class="flex items-center gap-2 text-neutral-500">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
          <span>{{ t('auth.totp_status_disabled') }}</span>
        </div>
      </div>

      <p v-if="totpStatus?.enabled" class="text-xs text-neutral-500">
        {{ t('auth.totp_disable_hint') }}
      </p>

      <button v-if="totpStatus && !totpStatus.enabled && !totpSetup"
              @click="startTotpSetup" :disabled="totpBusy"
              class="cursor-pointer h-10 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
        {{ totpBusy ? '…' : t('auth.totp_setup_btn') }}
      </button>

      <div v-if="totpSetup" class="space-y-4 pt-2 border-t border-neutral-200">
        <p class="text-sm text-neutral-700">{{ t('auth.totp_setup_step1') }}</p>
        <div class="flex justify-center bg-neutral-50 rounded-md p-4">
          <img :src="totpSetup.qr_data_uri" :alt="totpSetup.uri" class="border border-neutral-200 rounded" />
        </div>

        <details class="text-xs text-neutral-500">
          <summary class="cursor-pointer hover:text-neutral-700">{{ t('auth.totp_setup_step1_alt') }}</summary>
          <code class="block mt-2 p-2 bg-neutral-100 rounded font-mono text-xs break-all select-all">{{ totpSetup.secret }}</code>
        </details>

        <div class="pt-2">
          <p class="text-sm text-neutral-700 mb-2">{{ t('auth.totp_setup_step2') }}</p>
          <input v-model="totpCode" type="text" inputmode="numeric" maxlength="6"
                 pattern="\d{6}" placeholder="000000" @keydown.enter="activateTotp"
                 class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-lg tracking-widest text-center" />
        </div>

        <div v-if="totpError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ totpError }}
        </div>

        <div class="flex justify-end gap-2">
          <button @click="cancelTotpSetup"
                  class="cursor-pointer h-10 px-4 border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button @click="activateTotp" :disabled="totpBusy || totpCode.length !== 6"
                  class="cursor-pointer h-10 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ totpBusy ? '…' : t('auth.totp_enable_btn') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
import AppShell from '@/components/layout/AppShell.vue'
import { useAuthStore } from '@/stores/auth'
import { useTurnstile } from '@/composables/useTurnstile'

const router = useRouter()
const auth = useAuthStore()

// Štítek pro „zapamatovat zařízení". Odpovídá cfg.auth.email_otp.trusted_device_days
// (default 30) — čistě informativní, vlastní platnost řídí backend.
const REMEMBER_DAYS = 30

const email = ref('')
const password = ref('')
const totp = ref('')
const totpRequired = ref(false)
const error = ref<string>('')
const captchaRequired = ref(false)
const captchaSiteKey = ref('')
const captchaScriptUrl = ref('')

// E-mailové OTP (2. faktor pro uživatele bez TOTP — jen když je zapnuté v configu)
const emailOtp = ref('')
const emailOtpRequired = ref(false)
const rememberDevice = ref(false)
const otpEmailMasked = ref('')
const otpResendCooldown = ref(0)
const otpInfo = ref<string>('')
let cooldownTimer: ReturnType<typeof setInterval> | null = null

function startCooldown(seconds: number) {
  otpResendCooldown.value = Math.max(0, Math.floor(seconds))
  if (cooldownTimer) clearInterval(cooldownTimer)
  if (otpResendCooldown.value <= 0) return
  cooldownTimer = setInterval(() => {
    otpResendCooldown.value -= 1
    if (otpResendCooldown.value <= 0 && cooldownTimer) {
      clearInterval(cooldownTimer)
      cooldownTimer = null
    }
  }, 1000)
}

onUnmounted(() => {
  if (cooldownTimer) clearInterval(cooldownTimer)
})

const turnstile = useTurnstile()
const turnstileEl = ref<HTMLElement | null>(null)

onMounted(async () => {
  await auth.fetchSetupStatus()
  if (auth.needsSetup) {
    router.replace('/setup')
    return
  }
  // Stale session detection: pokud uživatel přijde na /login s platnou cookie,
  // hodíme ho rovnou kam patří (`/` nebo `/setup-totp`). Bez toho by submit
  // formuláře probíhal v rozjetém stavu a UX by byl matoucí.
  const stillAuthed = await auth.refresh()
  if (stillAuthed) {
    router.replace(auth.mustSetupTotp ? '/setup-totp' : '/')
    return
  }
  if (auth.setupStatus?.captcha.provider === 'turnstile') {
    captchaSiteKey.value = auth.setupStatus.captcha.site_key
    captchaScriptUrl.value = auth.setupStatus.captcha.script_url
    captchaRequired.value = true
    // Render hned po mountu — captcha vždy aktivní, Cloudflare sám rozhodne.
    await nextTick()
    if (turnstileEl.value) {
      // Přiřaď DOM element do composable a render widget
      turnstile.containerRef.value = turnstileEl.value
      await turnstile.render(captchaSiteKey.value, captchaScriptUrl.value, 'login')
    }
  }
})

async function submit() {
  // Guard: pokud captcha vyžadovaná a token chybí, nepouštět request.
  // (button má `:disabled` ale Enter v inputu submitne form i s disabled buttonem
  //  → bez tohoto guardu by 1. pokus šel s prázdným tokenem → 400 captcha_failed.)
  if (captchaRequired.value && !turnstile.token.value) {
    error.value = t('auth.captcha_loading')
    return
  }
  error.value = ''
  otpInfo.value = ''
  try {
    await auth.login(email.value.trim(), password.value, turnstile.token.value || undefined, totp.value || undefined, {
      emailOtp: emailOtp.value || undefined,
      rememberDevice: rememberDevice.value,
    })
    router.push('/')
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    const msg  = e?.response?.data?.error?.message
    const data = e?.response?.data?.error
    if (code === 'totp_required') {
      totpRequired.value = true
      error.value = ''
      // Token byl spotřebovaný 1. pokusem (heslo OK, čekáme na TOTP).
      // Reset → fresh token pro další pokus s TOTP kódem (jinak by 2. submit
      // šel s already-consumed tokenem → captcha_failed → user musí submit 2x).
      turnstile.reset()
    } else if (code === 'email_otp_required') {
      // Heslo OK, user nemá TOTP → backend poslal kód na e-mail.
      emailOtpRequired.value = true
      error.value = ''
      otpEmailMasked.value = data?.email_masked || ''
      startCooldown(data?.cooldown_remaining ?? 0)
      turnstile.reset()
    } else if (code === 'invalid_email_otp') {
      emailOtp.value = ''
      error.value = msg || t('auth.email_otp_invalid')
      turnstile.reset()
    } else if (code === 'invalid_totp') {
      totp.value = ''
      error.value = msg || t('auth.totp_invalid')
      turnstile.reset()  // taky reset — token z předchozího pokusu už invalid
    } else if (code === 'captcha_required') {
      captchaRequired.value = true
      error.value = t('auth.captcha_required')
    } else if (code === 'captcha_failed') {
      turnstile.reset()
      error.value = t('auth.captcha_failed')
    } else if (code === 'too_many_attempts') {
      error.value = msg || t('auth.too_many_attempts')
    } else {
      error.value = msg || t('auth.login_failed')
      turnstile.reset()
    }
  }
}

// Poslat e-mailový kód znovu. Re-submitne heslo s resend_otp=1; backend pošle
// nový kód (s cooldownem) a vrátí znovu email_otp_required.
async function resendCode() {
  if (otpResendCooldown.value > 0) return
  error.value = ''
  otpInfo.value = ''
  try {
    await auth.login(email.value.trim(), password.value, turnstile.token.value || undefined, undefined, {
      resendOtp: true,
    })
    // Úspěch by tu být neměl (kód je vyžadovaný), ale pro jistotu:
    router.push('/')
  } catch (e: any) {
    const data = e?.response?.data?.error
    if (data?.code === 'email_otp_required') {
      startCooldown(data?.cooldown_remaining ?? 0)
      if (data?.otp_sent) otpInfo.value = t('auth.email_otp_sent')
      turnstile.reset()
    } else {
      error.value = data?.message || t('auth.login_failed')
      turnstile.reset()
    }
  }
}
</script>

<template>
  <AppShell :title="t('auth.login_title')">
    <div class="w-full max-w-sm">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-6">
        <h2 class="text-xl font-semibold mb-1">{{ t('auth.login_title') }}</h2>
        <p class="text-sm text-neutral-500 mb-6">{{ t('auth.login_subtitle') }}</p>

        <form @submit.prevent="submit" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.email') }}</label>
            <input
              v-model="email"
              type="email"
              autocomplete="email"
              required
              autofocus
              class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.password') }}</label>
            <input
              v-model="password"
              type="password"
              autocomplete="current-password"
              required
              class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
            />
          </div>

          <div v-if="totpRequired">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.totp_code') }}</label>
            <input
              v-model="totp"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="6"
              pattern="\d{6}"
              placeholder="000000"
              autofocus
              class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-lg tracking-widest text-center focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
            />
            <p class="text-xs text-neutral-500 mt-1">{{ t('auth.totp_hint') }}</p>
          </div>

          <div v-if="emailOtpRequired">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.email_otp_code') }}</label>
            <input
              v-model="emailOtp"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="6"
              pattern="\d{6}"
              placeholder="000000"
              autofocus
              class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-lg tracking-widest text-center focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
            />
            <p class="text-xs text-neutral-500 mt-1">{{ t('auth.email_otp_hint', { email: otpEmailMasked }) }}</p>

            <label class="flex items-center gap-2 mt-3 text-sm text-neutral-700 cursor-pointer select-none">
              <input v-model="rememberDevice" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500/20" />
              {{ t('auth.remember_device', { days: REMEMBER_DAYS }) }}
            </label>

            <div class="mt-2">
              <button
                type="button"
                :disabled="otpResendCooldown > 0"
                @click="resendCode"
                class="text-sm text-primary-600 hover:underline disabled:text-neutral-400 disabled:no-underline disabled:cursor-not-allowed"
              >
                {{ otpResendCooldown > 0 ? t('auth.resend_in', { s: otpResendCooldown }) : t('auth.resend_code') }}
              </button>
            </div>

            <p v-if="otpInfo" class="text-xs text-success-600 mt-1">{{ otpInfo }}</p>
          </div>

          <!-- Turnstile container — vždy v DOM. Lokální template ref + watch
               v setup, který přiřadí do composable a vyrenderuje widget. -->
          <div v-show="captchaRequired" class="flex justify-center py-2">
            <div ref="turnstileEl"></div>
          </div>

          <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
            {{ error }}
          </div>

          <button
            type="submit"
            :disabled="auth.loading || (captchaRequired && !turnstile.token.value)"
            class="w-full h-10 bg-primary-600 hover:bg-primary-700 active:bg-primary-800 disabled:bg-neutral-300 disabled:cursor-not-allowed text-white font-medium rounded-md transition"
          >
            {{ auth.loading ? '…' : t('auth.login') }}
          </button>

          <div class="text-center pt-2">
            <router-link to="/forgot" class="text-sm text-primary-600 hover:underline">
              {{ t('auth.forgot') }}
            </router-link>
          </div>
        </form>
      </div>
    </div>
  </AppShell>
</template>

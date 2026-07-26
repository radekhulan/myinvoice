<script setup lang="ts">
import { ref, computed, markRaw, onMounted, onUnmounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
import AppShell from '@/components/layout/AppShell.vue'
import { useAuthStore } from '@/stores/auth'
import { useTurnstile } from '@/composables/useTurnstile'
import { authApi } from '@/api/auth'
import { cancelActiveWebAuthnCeremony, getCredential, isWebAuthnAvailable } from '@/security/webauthn'

const router = useRouter()
const auth = useAuthStore()

// Štítek pro „zapamatovat zařízení". Odpovídá cfg.auth.email_otp.trusted_device_days
// (default 30) — čistě informativní, vlastní platnost řídí backend.
const REMEMBER_DAYS = 30

const email = ref('')
const password = ref('')
const totp = ref('')
const totpRequired = ref(false)
const passkeyFlow = ref<{ flowToken: string; publicKey: Record<string, any>; methods: string[] } | null>(null)
// Nabídnuté faktory přežívají jednorázovou ceremony. Passkey flow se po selhání
// (i po pouhém zrušení systémového dialogu) zahazuje, ale možnost přepnout na
// TOTP musí zůstat vidět — jinak by uživatel musel naslepo znovu odeslat heslo.
const mfaMethods = ref<string[]>([])
const canUseTotpFallback = computed(() => !totpRequired.value && mfaMethods.value.includes('totp'))
const passkeyBusy = ref(false)
const passwordlessBusy = ref(false)
const passkeySupported = isWebAuthnAvailable()
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
    router.replace((auth.mustSetupMfa || auth.mustSetupTotp) ? '/setup-mfa' : '/')
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
  if (passwordlessBusy.value) return
  // Guard: pokud captcha vyžadovaná a token chybí, nepouštět request.
  // (button má `:disabled` ale Enter v inputu submitne form i s disabled buttonem
  //  → bez tohoto guardu by 1. pokus šel s prázdným tokenem → 400 captcha_failed.)
  if (captchaRequired.value && !turnstile.token.value) {
    error.value = t('auth.captcha_loading')
    return
  }
  error.value = ''
  otpInfo.value = ''
  mfaMethods.value = []
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
      passkeyFlow.value = null
      totpRequired.value = true
      error.value = ''
      // Token byl spotřebovaný 1. pokusem (heslo OK, čekáme na TOTP).
      // Reset → fresh token pro další pokus s TOTP kódem (jinak by 2. submit
      // šel s already-consumed tokenem → captcha_failed → user musí submit 2x).
      turnstile.reset()
    } else if (code === 'mfa_required') {
      mfaMethods.value = Array.isArray(data.methods) ? data.methods : ['passkey']
      passkeyFlow.value = {
        flowToken: data.flow_token,
        // markRaw: options nesmí být reaktivní Proxy — neklonují se mezi světy
        // a správci hesel na tom padají (viz plainJson v security/webauthn).
        publicKey: markRaw(data.public_key),
        methods: mfaMethods.value,
      }
      totpRequired.value = false
      error.value = ''
      // Turnstile se tu ZÁMĚRNĚ neresetuje: passkey verify captcha token nepoužívá
      // a re-render widgetu přebírá fokus dokumentu. Prohlížeč pak nad nezaostřenou
      // stránkou nevykreslí WebAuthn dialog a credentials.get() visí. Nový token
      // si vyžádá až cesta, která ho reálně potřebuje (TOTP fallback / další submit).
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

async function loginWithPasskey() {
  if (!passkeySupported || !auth.setupStatus?.passwordless_login_enabled) return
  if (captchaRequired.value && !turnstile.token.value) {
    error.value = t('auth.captcha_loading')
    return
  }

  passwordlessBusy.value = true
  error.value = ''
  try {
    const flow = await authApi.passkeyLoginOptions(turnstile.token.value || undefined)
    const credential = await getCredential(flow.public_key)
    await authApi.passkeyLoginVerify(flow.flow_token, credential)
    await auth.refresh()
    router.push('/')
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'captcha_failed') {
      error.value = t('auth.captcha_failed')
    } else if (code === 'too_many_attempts') {
      error.value = e?.response?.data?.error?.message || t('auth.too_many_attempts')
    } else {
      error.value = t('auth.passwordless_passkey_failed')
    }
  } finally {
    turnstile.reset()
    passwordlessBusy.value = false
  }
}

async function verifyPasskey() {
  if (!passkeyFlow.value || !passkeySupported) return
  const flow = passkeyFlow.value
  passkeyBusy.value = true
  error.value = ''
  try {
    const credential = await getCredential(flow.publicKey)
    await authApi.passkeyLoginVerify(flow.flowToken, credential)
    await auth.refresh()
    router.push('/')
  } catch (e: any) {
    // Verify endpoint spotřebuje flow už při prvním pokusu. Po browser cancelu
    // jej také zahodíme, protože klient bezpečně nepozná, zda request odešel.
    // Další submit hesla proto vždy získá novou ceremony.
    passkeyFlow.value = null
    turnstile.reset()
    error.value = e?.message === 'webauthn_timeout_extension'
      ? t('auth.passkey_timeout_extension')
      : e?.message === 'webauthn_timeout'
        ? t('auth.passkey_timeout')
        : e?.response?.data?.error?.message || t('auth.passkey_failed')
  } finally {
    passkeyBusy.value = false
  }
}

function useTotpFallback() {
  // TOTP dokončuje původní heslový login request. Aktivní passkey flow nesmí
  // držet submit disabled; nevyužitá ceremony pouze krátce expiruje na serveru.
  cancelActiveWebAuthnCeremony()
  passkeyFlow.value = null
  totpRequired.value = true
  error.value = ''
  // Teprve tady je nový captcha token potřeba — další submit půjde s heslem.
  turnstile.reset()
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
          <div v-if="auth.setupStatus?.passwordless_login_enabled" class="space-y-3">
            <button
              v-if="passkeySupported"
              type="button"
              :disabled="passwordlessBusy || auth.loading || (captchaRequired && !turnstile.token.value)"
              autofocus
              class="w-full h-10 bg-primary-600 hover:bg-primary-700 active:bg-primary-800 disabled:bg-neutral-300 disabled:cursor-not-allowed text-white font-medium rounded-md transition"
              @click="loginWithPasskey"
            >
              {{ passwordlessBusy ? t('auth.passkey_verifying') : t('auth.passwordless_passkey_login') }}
            </button>
            <p v-if="passkeySupported" class="text-xs text-neutral-500 text-center">
              {{ t('auth.passwordless_passkey_hint') }}
            </p>
            <p v-else class="text-sm text-warning-700">
              {{ t('auth.passwordless_passkey_unsupported') }}
            </p>
            <div class="flex items-center gap-3" aria-hidden="true">
              <span class="h-px flex-1 bg-neutral-200"></span>
              <span class="text-xs text-neutral-500">{{ t('auth.or_password_login') }}</span>
              <span class="h-px flex-1 bg-neutral-200"></span>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.email') }}</label>
            <input
              v-model="email"
              type="email"
              autocomplete="email"
              required
              :autofocus="!auth.setupStatus?.passwordless_login_enabled"
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

          <div v-if="passkeyFlow || canUseTotpFallback"
               class="rounded-md border border-primary-500/40 bg-primary-50 p-3 space-y-2">
            <template v-if="passkeyFlow">
              <button v-if="passkeySupported" type="button" @click="verifyPasskey" :disabled="passkeyBusy"
                      class="w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
                {{ passkeyBusy ? t('auth.passkey_verifying') : t('auth.passkey_login') }}
              </button>
              <p v-else class="text-sm text-warning-700">
                {{ t('auth.passkey_unsupported_recovery') }}
              </p>
            </template>
            <button v-if="canUseTotpFallback" type="button" @click="useTotpFallback"
                    class="w-full text-sm text-primary-700 hover:underline">
              {{ t('auth.use_totp_instead') }}
            </button>
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
            :disabled="auth.loading || passwordlessBusy || !!passkeyFlow || (captchaRequired && !turnstile.token.value)"
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

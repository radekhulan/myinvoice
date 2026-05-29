<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/layout/AppShell.vue'
import { authApi } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'
import { useTurnstile } from '@/composables/useTurnstile'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const auth = useAuthStore()
const email = ref('')
const submitted = ref(false)
const submitting = ref(false)
const error = ref('')

const captchaSiteKey = ref('')
const captchaScriptUrl = ref('')
const captchaEnabled = ref(false)
const turnstile = useTurnstile()
const turnstileEl = ref<HTMLElement | null>(null)

onMounted(async () => {
  await auth.fetchSetupStatus()
  if (auth.setupStatus?.captcha.provider === 'turnstile') {
    captchaEnabled.value = true
    captchaSiteKey.value = auth.setupStatus.captcha.site_key
    captchaScriptUrl.value = auth.setupStatus.captcha.script_url
    // Render widget hned po mountu — Cloudflare sám rozhodne o auto-pass / challenge.
    await new Promise((r) => setTimeout(r, 50))
    if (turnstileEl.value) {
      turnstile.containerRef.value = turnstileEl.value
      await turnstile.render(captchaSiteKey.value, captchaScriptUrl.value, 'forgot')
    }
  }
})

async function submit() {
  // Guard: pokud captcha vyžadovaná a token chybí, nepouštět request.
  // Enter v inputu submitne form i s disabled buttonem → bez tohoto guardu by
  // 1. pokus šel s prázdným tokenem → 400 captcha_failed.
  if (captchaEnabled.value && !turnstile.token.value) {
    error.value = t('auth.captcha_loading')
    return
  }
  error.value = ''
  submitting.value = true
  try {
    await authApi.forgot(email.value.trim(), turnstile.token.value || undefined)
    submitted.value = true
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('auth.reset_send'))
    turnstile.reset()
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
        <p class="text-sm text-neutral-500 mb-6">{{ t('auth.reset_send') }}</p>

        <div v-if="submitted" class="rounded-md bg-primary-50 border border-primary-200 p-4 text-sm text-primary-800">
          {{ t('auth.reset_sent') }}
        </div>

        <form v-else @submit.prevent="submit" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.email') }}</label>
            <input v-model="email" type="email" required autofocus autocomplete="email" class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>

          <!-- Turnstile widget — vždy v DOM. Lokální template ref + assign
               do composable v setup, pak render. -->
          <div v-show="captchaEnabled" class="flex justify-center py-2">
            <div ref="turnstileEl"></div>
          </div>

          <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 p-3 text-sm text-danger-600">
            {{ error }}
          </div>

          <button
            type="submit"
            :disabled="submitting || (captchaEnabled && !turnstile.token.value)"
            class="w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 disabled:cursor-not-allowed text-white font-medium rounded-md transition"
          >
            {{ submitting ? '…' : t('auth.reset_send') }}
          </button>
        </form>

        <div class="text-center pt-4 mt-4 border-t border-neutral-200">
          <router-link to="/login" class="text-sm text-primary-600 hover:underline">{{ t('auth.back_to_login') }}</router-link>
        </div>
      </div>
    </div>
  </AppShell>
</template>

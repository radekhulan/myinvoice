<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { isWebAuthnAvailable } from '@/security/webauthn'

const { t } = useI18n()
const auth = useAuthStore()
const security = useSessionSecurityStore()
const route = useRoute()
const overlay = ref<HTMLElement | null>(null)
const unlockButton = ref<HTMLButtonElement | null>(null)
let lastActivitySent = 0
let trailingActivityTimer: number | null = null
let previousOverflow = ''
let previousFocus: HTMLElement | null = null
const outsideElements = new Map<HTMLElement, { inert: boolean; ariaHidden: string | null }>()
const passkeySupported = isWebAuthnAvailable()

const privateRoute = computed(() => route.matched.some(record => record.meta.requiresAuth)
  && route.name !== 'setup-mfa')
const visible = computed(() => security.privacyCurtain && privateRoute.value)
const hasPasskey = computed(() => security.state?.unlock_methods.includes('passkey') === true)
const automaticLockEnabled = computed(() => (security.state?.lock_after_minutes ?? 0) > 0)
const canUnlock = computed(() => hasPasskey.value
  && passkeySupported
  && security.error !== 'session_status_failed')
// Bez timeoutu a bez odemykací metody se session nemá jak zamknout, takže
// kontrola stavu při každém přepnutí okna nemá co zjistit. Zámek z jiného tabu
// chodí přes BroadcastChannel, serverový 423 přes interceptor.
const lockPossible = computed(() => security.state === null
  || (security.state.lock_after_minutes ?? 0) > 0
  || security.state.unlock_methods.length > 0)

function lockedEvent() {
  security.markLocked()
}
function sendActivity() {
  lastActivitySent = Date.now()
  trailingActivityTimer = null
  void security.recordActivity()
}
function realInput(event: Event) {
  if (!event.isTrusted
    || !privateRoute.value
    || security.privacyCurtain
    || !automaticLockEnabled.value
  ) return
  const now = Date.now()
  const remaining = 30_000 - (now - lastActivitySent)
  if (remaining <= 0) {
    sendActivity()
  } else if (trailingActivityTimer === null) {
    trailingActivityTimer = window.setTimeout(() => {
      if (document.visibilityState === 'visible' && privateRoute.value && !security.privacyCurtain) {
        sendActivity()
      } else {
        trailingActivityTimer = null
      }
    }, remaining)
  }
}
function visibilityChanged() {
  if (document.visibilityState === 'visible' && privateRoute.value && lockPossible.value) {
    void security.refresh()
  }
}
function recheck() {
  if (document.visibilityState === 'visible' && auth.isAuthenticated && lockPossible.value) {
    void security.refresh()
  }
}
async function logout() {
  security.error = ''
  try {
    await auth.logout()
    security.clear()
    window.location.href = '/login'
  } catch {
    security.clear()
    security.markLocked()
    security.error = 'logout_failed'
  }
}
function trapFocus(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    return
  }
  if (event.key !== 'Tab' || !overlay.value) return
  const focusable = [...overlay.value.querySelectorAll<HTMLElement>('button:not(:disabled)')]
  if (focusable.length === 0) {
    event.preventDefault()
    overlay.value.focus()
    return
  }
  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  if (!first || !last) return
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

function coverOutsideElements() {
  const app = document.getElementById('app')
  for (const child of document.body.children) {
    if (!(child instanceof HTMLElement) || child === app || outsideElements.has(child)) continue
    outsideElements.set(child, {
      inert: child.inert,
      ariaHidden: child.getAttribute('aria-hidden'),
    })
    child.inert = true
    child.setAttribute('aria-hidden', 'true')
  }
}

function restoreOutsideElements() {
  for (const [element, original] of outsideElements) {
    element.inert = original.inert
    if (original.ariaHidden === null) {
      element.removeAttribute('aria-hidden')
    } else {
      element.setAttribute('aria-hidden', original.ariaHidden)
    }
  }
  outsideElements.clear()
}

watch(visible, async (covered) => {
  if (covered) {
    previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
    previousOverflow = document.documentElement.style.overflow
    document.documentElement.style.overflow = 'hidden'
    coverOutsideElements()
    await nextTick()
    ;(unlockButton.value?.disabled ? overlay.value : unlockButton.value)?.focus()
  } else {
    document.documentElement.style.overflow = previousOverflow
    restoreOutsideElements()
    previousFocus?.focus()
    previousFocus = null
  }
}, { immediate: true })

watch(privateRoute, (isPrivate, wasPrivate) => {
  if (isPrivate && !wasPrivate) {
    void security.refresh()
  }
})

onMounted(() => {
  window.addEventListener('myinvoice:session-locked', lockedEvent)
  window.addEventListener('pointerdown', realInput, { capture: true, passive: true })
  window.addEventListener('keydown', realInput, true)
  window.addEventListener('beforeinput', realInput, true)
  window.addEventListener('input', realInput, true)
  window.addEventListener('click', realInput, true)
  window.addEventListener('wheel', realInput, { capture: true, passive: true })
  if (typeof window.PointerEvent === 'undefined') {
    window.addEventListener('touchstart', realInput, { capture: true, passive: true })
  }
  window.addEventListener('focus', recheck)
  window.addEventListener('pageshow', recheck)
  document.addEventListener('visibilitychange', visibilityChanged)
  recheck()
})
onUnmounted(() => {
  window.removeEventListener('myinvoice:session-locked', lockedEvent)
  window.removeEventListener('pointerdown', realInput, true)
  window.removeEventListener('keydown', realInput, true)
  window.removeEventListener('beforeinput', realInput, true)
  window.removeEventListener('input', realInput, true)
  window.removeEventListener('click', realInput, true)
  window.removeEventListener('wheel', realInput, true)
  window.removeEventListener('touchstart', realInput, true)
  window.removeEventListener('focus', recheck)
  window.removeEventListener('pageshow', recheck)
  document.removeEventListener('visibilitychange', visibilityChanged)
  if (trailingActivityTimer !== null) window.clearTimeout(trailingActivityTimer)
  document.documentElement.style.overflow = previousOverflow
  restoreOutsideElements()
})
</script>

<template>
  <div v-if="visible" ref="overlay" tabindex="-1" @keydown="trapFocus"
       class="fixed inset-0 z-[10000] bg-neutral-950 flex items-center justify-center p-4"
       role="dialog" aria-modal="true" :aria-label="t('session_lock.title')">
    <div class="w-full max-w-sm rounded-xl bg-surface border border-neutral-700 p-6 text-center shadow-2xl">
      <div class="mx-auto mb-4 w-12 h-12 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center text-2xl">🔒</div>
      <h2 class="text-xl font-semibold">{{ t('session_lock.title') }}</h2>
      <p class="mt-2 text-sm text-neutral-500">{{ t('session_lock.message') }}</p>
      <p v-if="security.error" class="mt-3 text-sm text-danger-500">
        {{ security.error === 'logout_failed'
          ? t('session_lock.logout_failed')
          : security.error === 'session_status_failed'
            ? t('session_lock.status_failed')
            : t('session_lock.failed') }}
      </p>
      <button v-if="canUnlock" ref="unlockButton" type="button" :disabled="security.busy"
              @click="security.unlock"
              class="mt-5 w-full h-11 rounded-md bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-400 text-white font-medium">
        {{ security.busy ? t('session_lock.unlocking') : t('session_lock.unlock') }}
      </button>
      <p v-if="!security.state && security.error !== 'logout_failed'"
         class="mt-3 text-xs text-neutral-500">{{ t('session_lock.status_failed') }}</p>
      <p v-else-if="!hasPasskey"
         class="mt-3 text-xs text-neutral-500">{{ t('session_lock.no_passkey') }}</p>
      <p v-else-if="!passkeySupported"
         class="mt-3 text-xs text-neutral-500">{{ t('session_lock.unsupported') }}</p>
      <button v-if="security.error === 'session_status_failed'" type="button"
              @click="security.refresh({ force: true })"
              class="mt-4 w-full h-10 rounded-md border border-neutral-600 text-neutral-200 hover:bg-neutral-800 font-medium">
        {{ t('session_lock.retry_status') }}
      </button>
      <button type="button" @click="logout"
              class="mt-4 text-sm text-neutral-500 hover:text-neutral-700 underline">
        {{ t('auth.logout') }}
      </button>
    </div>
  </div>
</template>

import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const polling = await readFile(new URL('composables/useSessionAwarePolling.ts', root), 'utf8')
const webauthn = await readFile(new URL('security/webauthn.ts', root), 'utf8')
const sessionSecurity = await readFile(new URL('stores/sessionSecurity.ts', root), 'utf8')
const app = await readFile(new URL('App.vue', root), 'utf8')
const lockOverlay = await readFile(new URL('components/SessionLockOverlay.vue', root), 'utf8')

test('session-aware polling stops for hidden or covered private UI and aborts in-flight work', () => {
  assert.match(polling, /document\.visibilityState === 'visible'/)
  assert.match(polling, /!security\.privacyCurtain/)
  assert.match(polling, /new AbortController\(\)/)
  assert.match(polling, /controller\?\.abort\(\)/)
})

test('locking discards a stale WebAuthn ceremony without AbortSignal', () => {
  // Obal správce hesel AbortSignal nezvládá (změřeno: volání se zrušeným
  // signálem proti specifikaci nedoběhne), takže se ceremonii signál nepředává
  // a zastaralý výsledek se jen zahodí podle generace.
  assert.doesNotMatch(webauthn, /signal[,:]/)
  assert.match(webauthn, /let ceremonyGeneration = 0/)
  assert.match(webauthn, /function isCurrentCeremony/)
  assert.match(webauthn, /if \(!isCurrentCeremony\(generation\)\) throw new Error\('webauthn_cancelled'\)/)
  assert.match(sessionSecurity, /cancelActiveWebAuthnCeremony\(\)/)
})

test('a stuck WebAuthn ceremony cannot hang the UI silently', () => {
  // Bez vlastního stropu promise nedoběhne, když se systémový dialog nevykreslí.
  assert.match(webauthn, /CEREMONY_FALLBACK_TIMEOUT_MS/)
  assert.match(webauthn, /Promise\.race\(\[ceremony, timeout\]\)/)
  // Přepsané navigator.credentials.* (správci hesel) se hlásí zvlášť.
  assert.match(webauthn, /isCredentialsApiPatched\(\)[\s\S]{0,40}'webauthn_timeout_extension'/)
  assert.match(webauthn, /includes\('\[native code\]'\)/)
})

test('cold-start lock keeps the private route unmounted until full profile hydration', () => {
  assert.match(app, /RouterView v-if="showRoutedContent"/)
  assert.match(app, /privateSessionReady[\s\S]*auth\.profileHydrated[\s\S]*session_state === 'active'/)
  assert.match(app, /const privateTreeMounted = ref\(false\)/)
  assert.match(app, /if \(!authenticated\) \{\s*privateTreeMounted\.value = false/)
  assert.match(app, /else if \(ready\) \{\s*privateTreeMounted\.value = true/)
  assert.match(app, /showRoutedContent[\s\S]*auth\.isAuthenticated && privateTreeMounted\.value/)
  assert.match(app, /v-else-if="showColdStartGate"/)
  assert.match(sessionSecurity, /await auth\.refresh\(\)/)
  assert.match(sessionSecurity, /!auth\.profileHydrated/)
})

test('confirmed lock keeps a hydrated private route mounted below the overlay', () => {
  // \r?\n — na Windows checkoutu s core.autocrlf=true jsou konce řádků CRLF.
  const showRoutedContent = app.match(
    /const showRoutedContent = computed\(\(\) => ([\s\S]*?)\)\r?\nconst showColdStartGate/,
  )?.[1] || ''

  assert.match(showRoutedContent, /privateTreeMounted\.value/)
  assert.doesNotMatch(showRoutedContent, /privateSessionReady\.value/)
})

test('routine visibility checks do not cover an active session', () => {
  const visibilityHandler = lockOverlay.match(/function visibilityChanged\(\) \{([\s\S]*?)\n\}/)?.[1] || ''
  const refresh = sessionSecurity.match(/function refresh\(options: RefreshOptions = \{\}\): Promise<boolean> \{([\s\S]*?)\n\}/)?.[1] || ''

  assert.match(visibilityHandler, /visibilityState === 'visible'[\s\S]*security\.refresh\(\)/)
  assert.doesNotMatch(visibilityHandler, /visibilityState === 'hidden'/)
  assert.doesNotMatch(visibilityHandler, /privacyCurtain/)
  assert.doesNotMatch(refresh, /privacyCurtain\.value = true/)
})

test('lifecycle rechecks are skipped when the session cannot lock at all', () => {
  assert.match(lockOverlay, /const lockPossible = computed\(\(\) => security\.state === null/)
  assert.match(lockOverlay, /lock_after_minutes \?\? 0\) > 0/)
  assert.match(lockOverlay, /unlock_methods\.length > 0/)

  const visibilityHandler = lockOverlay.match(/function visibilityChanged\(\) \{([\s\S]*?)\r?\n\}/)?.[1] || ''
  const recheckHandler = lockOverlay.match(/function recheck\(\) \{([\s\S]*?)\r?\n\}/)?.[1] || ''
  assert.match(visibilityHandler, /lockPossible\.value/)
  assert.match(recheckHandler, /lockPossible\.value/)
})

test('session status refresh is single-flight and coalesces browser lifecycle events', () => {
  assert.match(sessionSecurity, /let refreshPromise: Promise<boolean> \| null = null/)
  assert.match(sessionSecurity, /if \(refreshPromise\) return refreshPromise/)
  assert.match(sessionSecurity, /REFRESH_COOLDOWN_MS/)
  assert.match(sessionSecurity, /!options\.force[\s\S]*lastRefreshCompletedAt/)
  assert.match(sessionSecurity, /refresh\(\{ force: true \}\)/)
})

test('only a confirmed lock or an elapsed local deadline keeps the privacy curtain active', () => {
  assert.match(sessionSecurity, /deadlineElapsed = true\s*privacyCurtain\.value = true/)
  assert.match(sessionSecurity, /function coverElapsedDeadline\(\)[\s\S]*Date\.now\(\) < deadlineDueAt[\s\S]*privacyCurtain\.value = true/)
  assert.match(sessionSecurity, /function refresh[\s\S]*coverElapsedDeadline\(\)/)
  assert.match(sessionSecurity, /state\.value\?\.session_state === 'locked'[\s\S]*privacyCurtain\.value = true/)
  assert.match(sessionSecurity, /else if \(!deadlineElapsed\) \{\s*privacyCurtain\.value = false/)
  assert.doesNotMatch(lockOverlay, /security\.privacyCurtain\s*=/)
})

test('disabled automatic lock does not emit activity heartbeats', () => {
  assert.match(sessionSecurity, /state\.value\?\.lock_after_minutes === 0/)
  assert.match(lockOverlay, /automaticLockEnabled/)
  assert.match(lockOverlay, /!automaticLockEnabled\.value/)
})

test('known private pollers use the shared lifecycle helper instead of raw intervals', async () => {
  const pages = [
    'pages/admin/Integrations.vue',
    'pages/admin/CronJobs.vue',
    'pages/admin/Update.vue',
    'pages/documents/DocumentsBrowser.vue',
    'pages/reports/MonthlyExportReport.vue',
  ]

  for (const page of pages) {
    const source = await readFile(new URL(page, root), 'utf8')
    assert.match(source, /useSessionAwarePolling/)
    assert.doesNotMatch(source, /setInterval\s*\(/)
  }
})

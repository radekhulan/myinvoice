import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)

test('passkey entry points use the shared WebAuthn capability check', async () => {
  const pages = [
    'pages/Login.vue',
    'pages/ApiTokens.vue',
    'pages/Passkeys.vue',
    'pages/ForcedMfaSetup.vue',
    'components/SessionLockOverlay.vue',
  ]

  for (const page of pages) {
    const source = await readFile(new URL(page, root), 'utf8')
    assert.match(source, /isWebAuthnAvailable/)
    assert.match(source, /passkeySupported/)
  }
})

test('login and locked-session UI expose a recovery message when WebAuthn is unavailable', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
  const overlay = await readFile(new URL('components/SessionLockOverlay.vue', root), 'utf8')

  assert.match(login, /passkey_unsupported_recovery/)
  assert.match(overlay, /session_lock\.unsupported/)
})

test('login clears one-time passkey flow for TOTP fallback and after failed verification', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
  const fallback = login.match(/function useTotpFallback\(\) \{([\s\S]*?)\n\}/)?.[1] || ''
  const verify = login.match(/async function verifyPasskey\([^)]*\) \{([\s\S]*?)\r?\n\}/)?.[1] || ''

  assert.match(fallback, /passkeyFlow\.value = null[\s\S]*totpRequired\.value = true/)
  assert.match(verify, /catch[\s\S]*passkeyFlow\.value = null[\s\S]*turnstile\.reset\(\)/)
  assert.match(login, /:disabled="[^"]*!!passkeyFlow/)
})

test('TOTP fallback survives a failed or cancelled passkey ceremony', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')

  // Nabídnuté metody se drží mimo jednorázovou ceremony…
  assert.match(login, /const mfaMethods = ref<string\[\]>\(\[\]\)/)
  assert.match(
    login,
    /const canUseTotpFallback = computed\(\(\) => !totpRequired\.value && mfaMethods\.value\.includes\('totp'\)\)/,
  )
  // …a tlačítko na přepnutí visí na nich, ne na passkeyFlow.
  assert.match(login, /v-if="canUseTotpFallback"[\s\S]*use_totp_instead/)
  assert.doesNotMatch(login, /v-if="passkeyFlow\.methods\.includes\('totp'\)"/)
})

test('passkey MFA step keeps the captcha token instead of re-rendering the widget', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
  const branch = login.match(/code === 'mfa_required'\) \{([\s\S]*?)\} else if/)?.[1] || ''

  // Re-render Turnstile bere fokus dokumentu a prohlížeč pak WebAuthn dialog
  // nevykreslí — v této větvi se proto resetovat nesmí.
  assert.doesNotMatch(branch, /turnstile\.reset\(\)/)
  const fallback = login.match(/function useTotpFallback\(\) \{([\s\S]*?)\r?\n\}/)?.[1] || ''
  assert.match(fallback, /turnstile\.reset\(\)/)
  assert.match(fallback, /cancelActiveWebAuthnCeremony\(\)/)
})

test('TOTP code is required for the first passkey and hidden without TOTP', async () => {
  const passkeys = await readFile(new URL('pages/Passkeys.vue', root), 'utf8')

  // Bez TOTP se pole vůbec nevykreslí…
  assert.match(passkeys, /v-if="hasTotp"/)
  // …a s TOTP, ale bez existující passkey, je povinné (label, placeholder, disabled).
  assert.match(
    passkeys,
    /const totpRequired = computed\(\(\) => hasTotp\.value && list\.value\.length === 0\)/,
  )
  assert.match(passkeys, /totpRequired \? t\('passkeys\.totp_label_required'\)/)
  assert.match(passkeys, /:required="totpRequired"/)
  assert.match(passkeys, /totpRequired && !totpCodeValid/)
  // 409 passkey_unavailable se nesmí ukázat jako generická chyba.
  assert.match(passkeys, /code === 'passkey_unavailable'[\s\S]*totp_required_error/)
})

test('API token step-up offers both factors and keeps a passkey proof after unrelated errors', async () => {
  const tokens = await readFile(new URL('pages/ApiTokens.vue', root), 'utf8')

  assert.match(tokens, /const needsStepUp = computed\(\(\) => needsTotp\.value \|\| hasPasskey\.value\)/)
  // Passkey tlačítko i TOTP pole jsou v modalu současně; TOTP mizí až po ověření.
  assert.match(tokens, /v-if="hasPasskey"[\s\S]*verify_passkey/)
  assert.match(tokens, /v-if="needsTotp && !passkeyStepUpToken"/)
  // Proof se nezahazuje preventivně před requestem.
  assert.doesNotMatch(tokens, /const proof = passkeyStepUpToken\.value\r?\n\s*passkeyStepUpToken\.value = ''/)
  assert.match(tokens, /step_up_proof_invalid'\) \{\s*\r?\n?\s*passkeyStepUpToken\.value = ''/)
})

test('passwordless login asks for discoverable passkey options before verification', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
  const authApi = await readFile(new URL('api/auth.ts', root), 'utf8')
  const flow = login.match(/async function loginWithPasskey\(\) \{([\s\S]*?)\n\}/)?.[1] || ''

  assert.match(login, /auth\.setupStatus\?\.passwordless_login_enabled/)
  assert.match(login, /type="button"[\s\S]*?@click="loginWithPasskey"/)
  assert.match(flow, /authApi\.passkeyLoginOptions\(turnstile\.token\.value \|\| undefined\)/)
  assert.match(
    flow,
    /passkeyLoginOptions[\s\S]*getCredential\(flow\.public_key\)[\s\S]*passkeyLoginVerify\(flow\.flow_token, credential\)[\s\S]*auth\.refresh\(\)/,
  )
  assert.match(authApi, /post<WebAuthnFlow>\('\/auth\/webauthn\/login\/options'/)
})

test('security management renders inside the shared profile tabs', async () => {
  const profile = await readFile(new URL('pages/PasswordChange.vue', root), 'utf8')
  const router = await readFile(new URL('router/index.ts', root), 'utf8')
  const authApi = await readFile(new URL('api/auth.ts', root), 'utf8')

  assert.match(profile, /type Tab = 'password' \| 'totp' \| 'passkeys' \| 'session-lock'/)
  assert.match(profile, /<Passkeys v-else-if="tab === 'passkeys'" \/>/)
  assert.match(profile, /sessionSecurity\.apply\(result\.session\)/)
  assert.match(profile, /<select[\s\S]*?class="sm:hidden/)
  assert.match(profile, /class="hidden sm:flex border-b/)
  assert.match(router, /profile\/passkeys[\s\S]+tab: 'passkeys'/)
  assert.match(router, /profile\/session-lock[\s\S]+tab: 'session-lock'/)
  assert.match(authApi, /put<SessionLockPreferenceUpdate>\('\/auth\/session\/lock-preference'/)
})

test('passkey management fields have visible associated labels', async () => {
  const passkeys = await readFile(new URL('pages/Passkeys.vue', root), 'utf8')

  assert.match(passkeys, /<label for="passkey-label"[\s\S]*?passkeys\.credential_label/)
  assert.match(passkeys, /<input id="passkey-label"/)
  assert.match(passkeys, /<label for="passkey-current-password"[\s\S]*?auth\.current_password/)
  assert.match(passkeys, /<input id="passkey-current-password"/)
  assert.match(passkeys, /<label for="passkey-totp"[\s\S]*?passkeys\.totp_label/)
  assert.match(passkeys, /<input id="passkey-totp"/)
})

test('passkey changes refresh the shared user profile independently of CSRF rotation', async () => {
  const passkeys = await readFile(new URL('pages/Passkeys.vue', root), 'utf8')
  const add = passkeys.match(/async function add\(\) \{([\s\S]*?)\n\}/)?.[1] || ''
  const revoke = passkeys.match(/async function revoke\(item: PasskeyCredential\) \{([\s\S]*?)\n\}/)?.[1] || ''

  assert.match(
    add,
    /if \(registered\.csrf_token\) \{[\s\S]*?auth\.setSessionCsrfToken\(registered\.csrf_token\)[\s\S]*?\}\s*await auth\.refresh\(\)[\s\S]*?await sessionSecurity\.refresh\(\{ force: true \}\)/,
  )
  assert.match(
    revoke,
    /passkeyRevoke[\s\S]*?await auth\.refresh\(\)[\s\S]*?await sessionSecurity\.refresh\(\{ force: true \}\)[\s\S]*?await load\(\)/,
  )
})

test('forced passkey setup uses existing TOTP as transition step-up', async () => {
  const setup = await readFile(new URL('pages/ForcedMfaSetup.vue', root), 'utf8')

  assert.match(setup, /auth\.user\?\.totp_enabled/)
  assert.match(setup, /totpStepUp\('passkey\.register'/)
  assert.match(setup, /authorization\.step_up_token/)
  assert.match(setup, /authorization\.current_password/)
  assert.match(setup, /await auth\.refresh\(\)[\s\S]*await sessionSecurity\.refresh\(\{ force: true \}\)/)
})

test('WebAuthn options are handed over as plain data, never as a reactive proxy', async () => {
  const webauthn = await readFile(new URL('security/webauthn.ts', root), 'utf8')
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')

  // Options putují mezi světy přes CustomEvent a strukturovaný klon; Proxy se
  // naklonovat nedá, detail dorazí jako null a obal správce hesel spadne.
  assert.match(webauthn, /function plainJson/)
  assert.match(webauthn, /const options = plainJson\(source\)/)
  assert.match(login, /publicKey: markRaw\(data\.public_key\)/)
})

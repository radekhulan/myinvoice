type JsonObject = Record<string, any>
let activeCeremony: AbortController | null = null

export function isWebAuthnAvailable(): boolean {
  return typeof window !== 'undefined'
    && typeof window.PublicKeyCredential !== 'undefined'
    && typeof navigator.credentials !== 'undefined'
}

export function cancelActiveWebAuthnCeremony(): void {
  activeCeremony?.abort()
  activeCeremony = null
}

// Signál vytváříme v témže realmu, který ho bude konzumovat.
function startCeremony(realm: Window | null): AbortController {
  cancelActiveWebAuthnCeremony()
  const Ctor = (realm as any)?.AbortController ?? AbortController
  activeCeremony = new Ctor()
  return activeCeremony as AbortController
}

function finishCeremony(controller: AbortController): void {
  if (activeCeremony === controller) activeCeremony = null
}

// Prohlížeč nemusí serverový `timeout` z options vůbec vynutit — a když se nad
// nezaostřenou stránkou nevykreslí systémový dialog, promise nedoběhne vůbec.
// Vlastní strop zaručí, že se UI nikdy nezasekne bez chybové hlášky.
const CEREMONY_GRACE_MS = 5_000
const CEREMONY_FALLBACK_TIMEOUT_MS = 120_000

/**
 * Správci hesel a passkey rozšíření běžně přepisují navigator.credentials.*.
 * Když jejich obal spadne (typicky `Cannot read properties of null`), promise
 * nikdy nedoběhne a UI by jen viselo. Nativní implementaci poznáme podle
 * `[native code]` v toString — slouží to výhradně k lepší chybové hlášce.
 */
function isNative(fn: unknown): boolean {
  try {
    return Function.prototype.toString.call(fn).includes('[native code]')
  } catch {
    return false
  }
}

export function isCredentialsApiPatched(): boolean {
  if (!isWebAuthnAvailable()) return false
  return !isNative(navigator.credentials.get) || !isNative(navigator.credentials.create)
}

/**
 * Nezávislost na obalu rozšíření.
 *
 * Správci hesel přepisují `navigator.credentials.*` a jejich obal nemusí být
 * korektní — ověřeno na Edgi, kde volání s už zrušeným AbortSignal proti
 * specifikaci neodmítne, ale promise vůbec nedoběhne, takže se přihlášení
 * passkey zasekne. Content scripty se ale běžně injektují jen do skutečně
 * navigovaných rámců, takže prázdný same-origin iframe má obvykle čistý realm
 * s nativní implementací. Když ho najdeme, ceremony pustíme tam; když ne,
 * použijeme běžnou cestu a případné zaseknutí uřízne timeout.
 *
 * Iframe musí v DOM zůstat — se zánikem elementu zaniká i jeho realm.
 */
let pristineRealm: Window | null | undefined

function nativeCredentialsRealm(): Window | null {
  if (pristineRealm !== undefined) return pristineRealm
  pristineRealm = null
  try {
    const frame = document.createElement('iframe')
    frame.setAttribute('allow', 'publickey-credentials-get *; publickey-credentials-create *')
    frame.setAttribute('aria-hidden', 'true')
    frame.tabIndex = -1
    frame.style.cssText = 'position:absolute;width:0;height:0;border:0;opacity:0;pointer-events:none'
    document.body.appendChild(frame)
    const win = frame.contentWindow as Window & typeof globalThis | null
    if (win
      && typeof win.PublicKeyCredential !== 'undefined'
      && isNative(win.navigator.credentials.get)
      && isNative(win.navigator.credentials.create)
    ) {
      pristineRealm = win
    } else {
      frame.remove()
    }
  } catch {
    pristineRealm = null
  }
  return pristineRealm
}

function credentialsRealm(): Window | null {
  return isCredentialsApiPatched() ? nativeCredentialsRealm() : null
}

async function runCeremony(
  options: JsonObject,
  run: (
    credentials: CredentialsContainer,
    signal: AbortSignal,
  ) => Promise<Credential | null>,
): Promise<JsonObject> {
  if (!isWebAuthnAvailable()) throw new Error('webauthn_unavailable')
  const realm = credentialsRealm()
  const controller = startCeremony(realm)
  const configured = Number(options.timeout)
  const limit = (Number.isFinite(configured) && configured > 0
    ? configured
    : CEREMONY_FALLBACK_TIMEOUT_MS) + CEREMONY_GRACE_MS
  let timedOut = false
  const timer = window.setTimeout(() => {
    timedOut = true
    controller.abort()
  }, limit)
  try {
    const credential = await run((realm ?? window).navigator.credentials, controller.signal)
    if (!isPublicKeyCredential(credential)) throw new Error('webauthn_cancelled')
    return credentialToJson(credential)
  } catch (e: any) {
    if (!timedOut) throw e
    // Timeout s čistým realmem je běžné vypršení; bez něj visel obal rozšíření.
    throw new Error(realm === null && isCredentialsApiPatched()
      ? 'webauthn_timeout_extension'
      : 'webauthn_timeout')
  } finally {
    window.clearTimeout(timer)
    finishCeremony(controller)
  }
}

/**
 * Credential z iframe realmu neprojde `instanceof` proti konstruktoru rodiče,
 * proto se rozhoduje podle tvaru, ne podle třídy.
 */
function isPublicKeyCredential(credential: unknown): credential is PublicKeyCredential {
  const value = credential as PublicKeyCredential | null
  return !!value
    && typeof value.id === 'string'
    && !!value.rawId
    && !!value.response
}

export function fromBase64Url(value: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - value.length % 4) % 4)
  const binary = atob(value.replace(/-/g, '+').replace(/_/g, '/') + padding)
  return Uint8Array.from(binary, char => char.charCodeAt(0))
}

export function toBase64Url(value: ArrayBuffer): string {
  const bytes = new Uint8Array(value)
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '')
}

export function requestOptionsFromJson(options: JsonObject): PublicKeyCredentialRequestOptions {
  return {
    ...options,
    challenge: fromBase64Url(options.challenge),
    allowCredentials: (options.allowCredentials || []).map((item: JsonObject) => ({
      ...item,
      id: fromBase64Url(item.id),
    })),
  } as PublicKeyCredentialRequestOptions
}

export function creationOptionsFromJson(options: JsonObject): PublicKeyCredentialCreationOptions {
  return {
    ...options,
    challenge: fromBase64Url(options.challenge),
    user: { ...options.user, id: fromBase64Url(options.user.id) },
    excludeCredentials: (options.excludeCredentials || []).map((item: JsonObject) => ({
      ...item,
      id: fromBase64Url(item.id),
    })),
  } as unknown as PublicKeyCredentialCreationOptions
}

export function credentialToJson(credential: PublicKeyCredential): JsonObject {
  const response = credential.response
  const payload: JsonObject = {
    id: credential.id,
    rawId: toBase64Url(credential.rawId),
    type: credential.type,
    authenticatorAttachment: credential.authenticatorAttachment,
    clientExtensionResults: credential.getClientExtensionResults(),
    response: {
      clientDataJSON: toBase64Url(response.clientDataJSON),
    },
  }
  // Duck typing místo instanceof — response může pocházet z iframe realmu.
  if ('signature' in response) {
    const assertion = response as AuthenticatorAssertionResponse
    payload.response.authenticatorData = toBase64Url(assertion.authenticatorData)
    payload.response.signature = toBase64Url(assertion.signature)
    payload.response.userHandle = assertion.userHandle ? toBase64Url(assertion.userHandle) : null
  } else if ('attestationObject' in response) {
    const attestation = response as AuthenticatorAttestationResponse
    payload.response.attestationObject = toBase64Url(attestation.attestationObject)
    payload.response.transports = attestation.getTransports()
  }
  return payload
}

export async function getCredential(options: JsonObject): Promise<JsonObject> {
  return runCeremony(options, (credentials, signal) => credentials.get({
    publicKey: requestOptionsFromJson(options),
    signal,
  }))
}

export async function createCredential(options: JsonObject): Promise<JsonObject> {
  return runCeremony(options, (credentials, signal) => credentials.create({
    publicKey: creationOptionsFromJson(options),
    signal,
  }))
}

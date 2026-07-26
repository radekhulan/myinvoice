type JsonObject = Record<string, any>

/**
 * Ceremony se NEPŘEDÁVÁ AbortSignal.
 *
 * Správci hesel přepisují navigator.credentials.* a jejich obal signál nemusí
 * zvládnout — na dev instanci změřeno, že volání s už zrušeným signálem proti
 * specifikaci neodmítne AbortError, ale vůbec nedoběhne. Signál navíc nic
 * nezbytného neřeší: zrušit rozdělanou ceremonii umí uživatel v systémovém
 * dialogu a náš vlastní timeout drží strop. Místo abortu proto jen zahazujeme
 * výsledek zastaralé ceremonie podle generace.
 */
let ceremonyGeneration = 0

export function isWebAuthnAvailable(): boolean {
  return typeof window !== 'undefined'
    && typeof window.PublicKeyCredential !== 'undefined'
    && typeof navigator.credentials !== 'undefined'
}

export function cancelActiveWebAuthnCeremony(): void {
  ceremonyGeneration += 1
}

function startCeremony(): number {
  ceremonyGeneration += 1
  return ceremonyGeneration
}

function isCurrentCeremony(generation: number): boolean {
  return generation === ceremonyGeneration
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

async function runCeremony(
  options: JsonObject,
  run: (credentials: CredentialsContainer) => Promise<Credential | null>,
): Promise<JsonObject> {
  if (!isWebAuthnAvailable()) throw new Error('webauthn_unavailable')
  const generation = startCeremony()
  const configured = Number(options.timeout)
  const limit = (Number.isFinite(configured) && configured > 0
    ? configured
    : CEREMONY_FALLBACK_TIMEOUT_MS) + CEREMONY_GRACE_MS

  const ceremony = run(navigator.credentials)
  const timeout = new Promise<never>((_, reject) => {
    window.setTimeout(
      () => reject(new Error(isCredentialsApiPatched()
        ? 'webauthn_timeout_extension'
        : 'webauthn_timeout')),
      limit,
    )
  })

  const credential = await Promise.race([ceremony, timeout])
  if (!isCurrentCeremony(generation)) throw new Error('webauthn_cancelled')
  if (!(credential instanceof PublicKeyCredential)) throw new Error('webauthn_cancelled')
  return credentialToJson(credential)
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

/**
 * Options musí být čistá data, ne Vue reaktivní Proxy.
 *
 * Správci hesel přepisují navigator.credentials a options si mezi světy posílají
 * přes CustomEvent, jehož `detail` se strukturovaně klonuje. Proxy se naklonovat
 * nedá, klon selže a listener dostane `detail === null` — Keeper na tom padá
 * v `switch (t.detail.type)` a naše promise nikdy nedoběhne. Options ze serveru
 * jsou čisté JSON, takže je stačí přelít přes JSON a zbavit se tím reaktivity;
 * teprve pak se dělají ArrayBuffery, které přes JSON projít nesmí.
 *
 * Chrání to všechna volání bez ohledu na to, jestli si volající flow uložil do
 * ref() (Login.vue) nebo drží prostý objekt (odemčení zámku, registrace).
 */
function plainJson(options: JsonObject): JsonObject {
  return JSON.parse(JSON.stringify(options))
}

export function requestOptionsFromJson(source: JsonObject): PublicKeyCredentialRequestOptions {
  const options = plainJson(source)
  return {
    ...options,
    challenge: fromBase64Url(options.challenge),
    allowCredentials: (options.allowCredentials || []).map((item: JsonObject) => ({
      ...item,
      id: fromBase64Url(item.id),
    })),
  } as PublicKeyCredentialRequestOptions
}

export function creationOptionsFromJson(source: JsonObject): PublicKeyCredentialCreationOptions {
  const options = plainJson(source)
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
  if (response instanceof AuthenticatorAssertionResponse) {
    payload.response.authenticatorData = toBase64Url(response.authenticatorData)
    payload.response.signature = toBase64Url(response.signature)
    payload.response.userHandle = response.userHandle ? toBase64Url(response.userHandle) : null
  } else if (response instanceof AuthenticatorAttestationResponse) {
    payload.response.attestationObject = toBase64Url(response.attestationObject)
    payload.response.transports = response.getTransports()
  }
  return payload
}

export async function getCredential(options: JsonObject): Promise<JsonObject> {
  return runCeremony(options, credentials => credentials.get({
    publicKey: requestOptionsFromJson(options),
  }))
}

export async function createCredential(options: JsonObject): Promise<JsonObject> {
  return runCeremony(options, credentials => credentials.create({
    publicKey: creationOptionsFromJson(options),
  }))
}

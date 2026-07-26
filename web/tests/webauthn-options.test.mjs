import assert from 'node:assert/strict'
import test from 'node:test'
import { reactive, ref } from 'vue'

import {
  creationOptionsFromJson,
  fromBase64Url,
  requestOptionsFromJson,
  toBase64Url,
} from '../src/security/webauthn.ts'

// Skutečná data ze serveru: base64url bez paddingu, transports jako pole.
const CHALLENGE = '8Jo0KvDAObWTp1COHJa7qhTQtRRNo5Vl2k_R5YMutTw'
const CREDENTIAL_ID = 'Y8ABk3Xg6ixZoH7fCSSxSSTHXWPaSm-Jr3nuUaT-XPA'

function assertionOptions() {
  return {
    challenge: CHALLENGE,
    timeout: 120000,
    rpId: 'dev.myinvoice.cz',
    allowCredentials: [
      { type: 'public-key', id: CREDENTIAL_ID, transports: ['internal', 'hybrid'] },
    ],
    userVerification: 'required',
  }
}

function registrationOptions() {
  return {
    challenge: CHALLENGE,
    rp: { id: 'dev.myinvoice.cz', name: 'MyInvoice.cz' },
    user: { id: CREDENTIAL_ID, name: 'user@example.invalid', displayName: 'User' },
    pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
    excludeCredentials: [
      { type: 'public-key', id: CREDENTIAL_ID, transports: ['internal'] },
    ],
    authenticatorSelection: { residentKey: 'required', userVerification: 'required' },
    timeout: 120000,
  }
}

test('base64url round-trips bez paddingu i s ním', () => {
  const bytes = fromBase64Url(CREDENTIAL_ID)
  assert.equal(bytes.length, 32)
  assert.equal(toBase64Url(bytes.buffer), CREDENTIAL_ID)
  // Stejné bajty jako uložené credential_id v DB.
  assert.equal(
    Buffer.from(bytes).toString('hex').toUpperCase(),
    '63C0019375E0EA2C59A07EDF0924B14924C75D63DA4A6F89AF79EE51A4FE5CF0',
  )
})

/**
 * Regrese: správci hesel si options posílají mezi světy přes CustomEvent, jehož
 * `detail` se strukturovaně klonuje. Vue Proxy se naklonovat nedá — klon selže,
 * listener dostane `detail === null` a ceremonie se zasekne. Options tedy musí
 * z konverze vyjít jako čistá data, i když do ní přijdou reaktivní.
 */
test('assertion options z reaktivního zdroje jsou strukturovaně klonovatelné', () => {
  const source = reactive(assertionOptions())
  const options = requestOptionsFromJson(source)

  assert.doesNotThrow(() => structuredClone(options))
  const clone = structuredClone(options)
  assert.deepEqual([...new Uint8Array(clone.challenge)], [...fromBase64Url(CHALLENGE)])
  assert.deepEqual(clone.allowCredentials[0].transports, ['internal', 'hybrid'])
})

test('registration options z reaktivního zdroje jsou strukturovaně klonovatelné', () => {
  const source = reactive(registrationOptions())
  const options = creationOptionsFromJson(source)

  assert.doesNotThrow(() => structuredClone(options))
  const clone = structuredClone(options)
  assert.equal(clone.rp.id, 'dev.myinvoice.cz')
  assert.deepEqual([...new Uint8Array(clone.user.id)], [...fromBase64Url(CREDENTIAL_ID)])
  assert.deepEqual(clone.excludeCredentials[0].transports, ['internal'])
})

test('options držené ve ref() (jako na přihlašovací stránce) taky projdou', () => {
  const flow = ref({ flowToken: 'x', publicKey: assertionOptions() })
  const options = requestOptionsFromJson(flow.value.publicKey)

  assert.doesNotThrow(() => structuredClone(options))
})

test('binární pole jsou BufferSource, ne pole čísel ani řetězec', () => {
  const options = requestOptionsFromJson(assertionOptions())

  assert.ok(ArrayBuffer.isView(options.challenge), 'challenge musí být typed array')
  assert.ok(ArrayBuffer.isView(options.allowCredentials[0].id), 'credential id musí být typed array')
  assert.equal(options.rpId, 'dev.myinvoice.cz')
  assert.equal(options.userVerification, 'required')
})

test('konverze nemodifikuje vstup', () => {
  const source = assertionOptions()
  requestOptionsFromJson(source)

  assert.equal(source.challenge, CHALLENGE, 'vstupní challenge musí zůstat řetězec')
  assert.equal(source.allowCredentials[0].id, CREDENTIAL_ID)
})

test('chybějící allowCredentials/excludeCredentials dají prázdné pole', () => {
  const assertion = requestOptionsFromJson({ challenge: CHALLENGE, rpId: 'x' })
  assert.deepEqual(assertion.allowCredentials, [])

  const registration = creationOptionsFromJson({
    challenge: CHALLENGE,
    user: { id: CREDENTIAL_ID, name: 'a', displayName: 'b' },
  })
  assert.deepEqual(registration.excludeCredentials, [])
})

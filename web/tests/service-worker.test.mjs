import assert from 'node:assert/strict'
import test from 'node:test'

import { createWorker, loadWorkerSource } from './helpers/worker-harness.mjs'

const source = await loadWorkerSource('service-worker.js')

// API nesmí přes service worker projít vůbec, ani jako pass-through přes
// `respondWith(fetch(...))`: na iOS WebKitu se při přeposlání ztratí tělo
// uploadu ze souboru a import faktur skončí na 400 no_file / no_files.
test('API requests are left entirely to the browser', async () => {
  for (const method of ['GET', 'POST']) {
    for (const pathname of ['/api', '/api/v1/invoices', '/api/admin/imports/ai-extract-pdf']) {
      const worker = createWorker(source)
      const result = worker.dispatchFetch({
        url: `https://invoice.test${pathname}`,
        method,
        destination: '',
      })

      assert.equal(result, undefined, `${method} ${pathname} nesmí volat respondWith`)
      assert.equal(worker.cacheOpenCount, 0)
      assert.equal(worker.fetchCalls.length, 0)
      assert.equal(worker.cachePutCalls.length, 0)
    }
  }
})

test('hashed Vite assets use cache-first', async () => {
  const worker = createWorker(source)
  const request = {
    url: 'https://invoice.test/assets/app-deadbeef.js',
    method: 'GET',
    destination: 'script',
  }

  await worker.dispatchFetch(request)

  assert.equal(worker.cacheOpenCount, 1)
  assert.equal(worker.fetchCalls.length, 1)
  assert.equal(worker.cachePutCalls.length, 1)
  assert.equal(worker.cachePutCalls[0][0], request)
})

test('cached hashed asset is served without hitting the network', async () => {
  const url = 'https://invoice.test/assets/app-deadbeef.js'
  const worker = createWorker(source, { cached: { [url]: { source: 'cache' } } })

  const response = await worker.dispatchFetch({ url, method: 'GET', destination: 'script' })

  assert.equal(response.source, 'cache')
  assert.equal(worker.fetchCalls.length, 0)
})

test('unhashed /styles/ asset revalidates in the background', async () => {
  const url = 'https://invoice.test/styles/invoice.css'
  const worker = createWorker(source, { cached: { [url]: { source: 'cache' } } })

  const response = await worker.dispatchFetch({ url, method: 'GET', destination: 'style' })

  // Odpověď přijde okamžitě z cache…
  assert.equal(response.source, 'cache')

  // …ale na pozadí se stáhne a uloží aktuální verze, takže se změna
  // propíše bez ručního bumpu STATIC_CACHE.
  await worker.settle()
  assert.equal(worker.fetchCalls.length, 1)
  assert.equal(worker.cachePutCalls.length, 1)
})

test('uncached /styles/ asset falls back to the network response', async () => {
  const worker = createWorker(source)
  const request = {
    url: 'https://invoice.test/styles/logo.svg',
    method: 'GET',
    destination: 'image',
  }

  const response = await worker.dispatchFetch(request)

  assert.equal(response, worker.networkResponse)
  assert.equal(worker.fetchCalls.length, 1)
  assert.equal(worker.cachePutCalls.length, 1)
})

test('non-GET requests are never cached', async () => {
  const worker = createWorker(source)
  const result = worker.dispatchFetch({
    url: 'https://invoice.test/styles/invoice.css',
    method: 'POST',
    destination: 'style',
  })

  assert.equal(result, undefined)
  assert.equal(worker.cacheOpenCount, 0)
})

test('HTML navigation stays on the network without service-worker caching', () => {
  const worker = createWorker(source)
  const result = worker.dispatchFetch({
    url: 'https://invoice.test/invoices',
    method: 'GET',
    destination: 'document',
  })

  assert.equal(result, undefined)
  assert.equal(worker.cacheOpenCount, 0)
  assert.equal(worker.fetchCalls.length, 0)
})

test('activation drops stale MyInvoice caches only', async () => {
  const worker = createWorker(source, {
    existingCaches: ['myinvoice-static-v0', 'myinvoice-static-v1', 'other-app-cache'],
  })

  await worker.dispatchActivate()

  assert.deepEqual(worker.deletedCaches, ['myinvoice-static-v0'])
})

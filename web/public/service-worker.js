const STATIC_CACHE_PREFIX = 'myinvoice-static-'
const STATIC_CACHE = `${STATIC_CACHE_PREFIX}v1`
const STATIC_DESTINATIONS = new Set(['font', 'image', 'script', 'style'])

// Vite hashuje názvy souborů a ikony jsou stabilní → cache-first je bezpečné.
const IMMUTABLE_PREFIXES = ['/assets/', '/pwa/']
// Nehashovaná statika (invoice.css, logo.svg). Cache-first by tu držel starou
// verzi až do bumpu STATIC_CACHE, proto stale-while-revalidate.
const REVALIDATE_PREFIXES = ['/styles/']

function isApiRequest(url) {
  return url.pathname === '/api' || url.pathname.startsWith('/api/')
}

function hasPrefix(pathname, prefixes) {
  return prefixes.some((prefix) => pathname.startsWith(prefix))
}

function selectStrategy(request, url) {
  if (request.method !== 'GET') return null
  if (!STATIC_DESTINATIONS.has(request.destination)) return null

  if (hasPrefix(url.pathname, IMMUTABLE_PREFIXES)) return cacheFirst
  if (hasPrefix(url.pathname, REVALIDATE_PREFIXES)) return staleWhileRevalidate

  return null
}

async function putIfStorable(cache, request, response) {
  if (response.ok && response.type === 'basic') {
    await cache.put(request, response.clone())
  }
}

async function cacheFirst(request) {
  const cache = await caches.open(STATIC_CACHE)
  const cached = await cache.match(request)
  if (cached) return cached

  const response = await fetch(request)
  await putIfStorable(cache, request, response)
  return response
}

async function staleWhileRevalidate(request, event) {
  const cache = await caches.open(STATIC_CACHE)
  const cached = await cache.match(request)

  const revalidated = fetch(request).then(async (response) => {
    await putIfStorable(cache, request, response)
    return response
  })

  if (!cached) return revalidated

  // Cache se aktualizuje na pozadí; chyba sítě nesmí shodit odpověď z cache.
  event.waitUntil(revalidated.catch(() => undefined))
  return cached
}

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting())
})

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const cacheNames = await caches.keys()
    await Promise.all(
      cacheNames
        .filter((name) => name.startsWith(STATIC_CACHE_PREFIX) && name !== STATIC_CACHE)
        .map((name) => caches.delete(name)),
    )
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  if (url.origin !== self.location.origin) return

  // API se NIKDY nesmí dostat přes service worker. Přeposlání přes
  // `respondWith(fetch(request, { cache: 'no-store' }))` sestaví z requestu
  // nový a na iOS WebKitu přitom zahodí tělo streamované ze souboru
  // z `<input type="file">`: upload faktury dorazí s CONTENT_LENGTH 0
  // a prázdným $_FILES. Bez `respondWith` request obslouží přímo prohlížeč,
  // se všemi původními vlastnostmi.
  if (isApiRequest(url)) return

  const strategy = selectStrategy(request, url)
  if (strategy) {
    event.respondWith(strategy(request, event))
  }
})

import test from 'node:test'
import assert from 'node:assert/strict'
import vm from 'node:vm'
import { readFileSync } from 'node:fs'
const source = readFileSync(
  new URL('../wordpress_plugins/omongstat/assets/js/collector.js', import.meta.url),
  'utf8',
)
function fixture({ beacon = true, storage = true } = {}) {
  const calls = []
  const store = () => {
    const data = new Map()
    return { getItem: (k) => data.get(k), setItem: (k, v) => data.set(k, v) }
  }
  const context = {
    Blob,
    Uint8Array,
    Intl,
    Date,
    Math,
    console,
    location: { pathname: '/test/' },
    document: { referrer: '' },
    screen: { width: 1200, height: 800 },
    navigator: {
      language: 'ko',
      sendBeacon: (...args) => {
        calls.push(['beacon', ...args])
        return beacon
      },
    },
    fetch: async (...args) => {
      calls.push(['fetch', ...args])
      return { ok: true }
    },
    crypto: { randomUUID: () => '01234567-89ab-cdef-0123-456789abcdef' },
  }
  context.window = context
  context.OmongStat = { collectUrl: '/wp-json/omongstat/v1/collect', postId: 1 }
  if (storage) {
    context.localStorage = store()
    context.sessionStorage = store()
  }
  return { context: vm.createContext(context), calls }
}
test('Beacon sends a JSON Blob once and identifiers survive another page', async () => {
  const { context, calls } = fixture()
  vm.runInContext(source, context)
  vm.runInContext(source, context)
  assert.equal(calls.length, 1)
  const payload = JSON.parse(await calls[0][2].text())
  assert.equal(payload.path, '/test/')
  assert.equal(payload.eventType, 'pageview')
  assert.equal(payload.visitorId.length, 36)
  context.__omongstatCollected = false
  vm.runInContext(source, context)
  assert.equal(JSON.parse(await calls[1][2].text()).visitorId, payload.visitorId)
})
test('Beacon false falls back to keepalive fetch even when storage is blocked', () => {
  const { context, calls } = fixture({ beacon: false, storage: false })
  vm.runInContext(source, context)
  assert.equal(calls[1][0], 'fetch')
  assert.equal(calls[1][2].keepalive, true)
  assert.equal(calls[1][2].headers['Content-Type'], 'application/json')
})
test('throwing Beacon falls back and old crypto uses an anonymous fallback', () => {
  const { context, calls } = fixture({ storage: false })
  context.navigator.sendBeacon = () => {
    throw Error('unavailable')
  }
  context.crypto = undefined
  vm.runInContext(source, context)
  assert.match(JSON.parse(calls[0][2].body).sessionId, /^[a-zA-Z0-9_-]{16,64}$/)
})

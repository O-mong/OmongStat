import test from 'node:test'
import assert from 'node:assert/strict'
import { fetchStatistics } from '../src/api.ts'

const config = {
  restUrl: '/wp-json/omongstat/v1/',
  nonce: 'test-nonce',
  timezone: 'UTC',
  today: '2026-09-12',
}
const range = { start: '2026-09-06', end: '2026-09-12' }

function mockResponse(url) {
  const endpoint = (url.searchParams.get('rest_route') || url.pathname).split('/').pop()
  if (endpoint === 'summary') return { pageviews: 0 }
  if (endpoint === 'technology') return { browser: [] }
  return []
}

test('fetches all endpoints with the nonce and selected dates', async (t) => {
  const requests = []
  t.mock.method(globalThis, 'fetch', async (url, options) => {
    requests.push({ url, options })
    return { ok: true, json: async () => mockResponse(url) }
  })
  globalThis.window = { location: { origin: 'https://example.org' } }

  await fetchStatistics(config, range, new AbortController().signal)

  assert.equal(requests.length, 7)
  assert.ok(requests.some(({ url }) => url.pathname.endsWith('/events/recent')))
  for (const { url, options } of requests) {
    assert.equal(url.searchParams.get('start'), range.start)
    assert.equal(url.searchParams.get('end'), range.end)
    assert.equal(options.headers['X-WP-Nonce'], config.nonce)
    assert.equal(options.cache, 'no-store')
  }
})

test('supports WordPress plain permalinks', async (t) => {
  t.mock.method(globalThis, 'fetch', async (url) => {
    assert.match(url.searchParams.get('rest_route'), /^\/omongstat\/v1\/(stats|events)\//)
    return { ok: true, json: async () => mockResponse(url) }
  })
  globalThis.window = { location: { origin: 'https://example.org' } }
  await fetchStatistics(
    { ...config, restUrl: '/?rest_route=/omongstat/v1/' },
    range,
    new AbortController().signal,
  )
})

test('reports JSON parsing failures in English', async (t) => {
  t.mock.method(globalThis, 'fetch', async () => ({
    ok: true,
    status: 200,
    json: async () => {
      throw new SyntaxError('Invalid JSON')
    },
  }))
  globalThis.window = { location: { origin: 'https://example.org' } }
  await assert.rejects(
    fetchStatistics(config, range, new AbortController().signal),
    /Could not parse the response/,
  )
})

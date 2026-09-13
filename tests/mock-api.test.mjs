import test from 'node:test'
import assert from 'node:assert/strict'
import { EventEmitter } from 'node:events'
import { createSampleEvents, sampleStatistics } from '../dev/sampleData.ts'
import { createMockHandler, mockApiPlugin } from '../dev/mockApi.ts'

const now = new Date('2026-09-12T12:00:00Z')

function request(handler, path, method = 'GET') {
  const response = new EventEmitter()
  response.status = null
  response.body = null
  response.headers = {}
  response.setHeader = (name, value) => {
    response.headers[name] = value
  }
  response.writeHead = (status, headers) => {
    response.status = status
    Object.assign(response.headers, headers)
  }
  response.end = (body) => {
    response.body = JSON.parse(body)
  }
  handler({ url: path, method }, response, () => {
    response.passedThrough = true
  })
  return response
}

test('sample summary, daily counts, distributions and recent events agree', () => {
  const events = createSampleEvents(now)
  const data = sampleStatistics(events, '2026-09-06', '2026-09-12', '2026-09-12')
  const sum = (rows) => rows.reduce((total, row) => total + Number(row.count), 0)
  assert.equal(data.timeseries.length, 7)
  assert.equal(sum(data.timeseries), data.summary.pageviews)
  assert.equal(sum(data.pages), data.summary.pageviews)
  assert.equal(sum(data.technology.browser), data.summary.pageviews)
  assert.ok(data.summary.total > data.summary.pageviews)
  assert.ok(data.summary.visitors <= data.summary.sessions)
  assert.equal(data.recent.length, 50)
  assert.ok(data.recent.every((event) => event.occurred_at <= now.toISOString()))
  assert.deepEqual(createSampleEvents(now), events)
})

test('dates outside the sample history return an empty selected period', () => {
  const data = sampleStatistics(createSampleEvents(now), '2020-01-01', '2020-01-03', '2026-09-12')
  assert.equal(data.summary.pageviews, 0)
  assert.ok(data.summary.total > 0)
  assert.deepEqual(
    data.timeseries.map((row) => row.count),
    [0, 0, 0],
  )
  assert.deepEqual(data.recent, [])
})

test('normal, empty and API-error scenarios return the expected HTTP contracts', (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] })
  const handler = createMockHandler(now)
  const normal = request(handler, '/wp-json/omongstat/v1/stats/summary')
  const empty = request(handler, '/wp-json/omongstat/v1/stats/summary?mock=empty')
  const error = request(handler, '/wp-json/omongstat/v1/events/recent?mock=error')
  t.mock.timers.tick(150)
  assert.equal(normal.status, 200)
  assert.ok(normal.body.pageviews > 0)
  assert.equal(normal.headers['Cache-Control'], 'no-store')
  assert.equal(empty.body.total, 0)
  assert.equal(empty.body.pageviews, 0)
  assert.equal(error.status, 500)
  assert.equal(error.body.code, 'omongstat_db_error')
})

test('slow scenario delays responses and cancels work when disconnected', (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] })
  const handler = createMockHandler(now)
  const slow = request(handler, '/wp-json/omongstat/v1/stats/summary?mock=slow')
  const cancelled = request(handler, '/wp-json/omongstat/v1/stats/pages?mock=slow')
  cancelled.emit('close')
  t.mock.timers.tick(2999)
  assert.equal(slow.status, null)
  t.mock.timers.tick(1)
  assert.equal(slow.status, 200)
  assert.equal(cancelled.status, null)
})

test('rejects invalid dates, unsupported routes and writes; unrelated requests pass through', () => {
  const handler = createMockHandler(now)
  for (const query of [
    'start=2026-02-30',
    'start=2026-09-12&end=2026-09-01',
    'start=2020-01-01&end=2026-09-12',
  ]) {
    assert.equal(request(handler, `/wp-json/omongstat/v1/stats/summary?${query}`).status, 400)
  }
  assert.equal(request(handler, '/wp-json/omongstat/v1/stats/summary', 'POST').status, 405)
  assert.equal(request(handler, '/wp-json/omongstat/v1/collect').status, 404)
  assert.equal(request(handler, '/wp-json/omongstat/v1/stats/pages?mock=other').status, 404)
  assert.equal(request(handler, '/src/main.tsx').passedThrough, true)
})

test('development bootstrap supplies configuration without adding a React wrapper', () => {
  const plugin = mockApiPlugin()
  assert.equal(plugin.apply, 'serve')
  const result = plugin.transformIndexHtml('<div id="root"></div>')
  assert.equal(result.html, '<div id="omongstat-root"></div>')
  assert.match(result.tags[0].children, /window\.OmongStatAdmin/)
  assert.match(result.tags[0].children, /\/wp-json\/omongstat\/v1\//)
  assert.equal(result.tags[0].injectTo, 'head-prepend')
})

test('supports plain permalinks and a server-selected scenario', (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] })
  const handler = createMockHandler(now, 'empty')
  const response = request(handler, '/?rest_route=/omongstat/v1/stats/summary')
  t.mock.timers.tick(150)
  assert.equal(response.status, 200)
  assert.equal(response.body.total, 0)
})

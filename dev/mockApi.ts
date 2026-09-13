import type { IncomingMessage, ServerResponse } from 'node:http'
import type { Plugin } from 'vite'
import type { Statistics } from '../src/types.ts'
import { createSampleEvents, sampleStatistics } from './sampleData.ts'

const prefix = '/wp-json/omongstat/v1/'
const routes = new Map<string, keyof Statistics>([
  ['stats/summary', 'summary'],
  ['stats/timeseries', 'timeseries'],
  ['stats/pages', 'pages'],
  ['stats/referrers', 'referrers'],
  ['stats/countries', 'countries'],
  ['stats/technology', 'technology'],
  ['events/recent', 'recent'],
])
const scenarios = new Set(['normal', 'empty', 'error', 'slow'])

function validDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const timestamp = Date.parse(value)
  return Number.isFinite(timestamp) && new Date(timestamp).toISOString().slice(0, 10) === value
}

function sendJson(response: ServerResponse, status: number, body: unknown) {
  response.writeHead(status, {
    'Content-Type': 'application/json',
    'Cache-Control': 'no-store',
  })
  response.end(JSON.stringify(body))
}

export function createMockHandler(now = new Date(), defaultScenario = 'normal') {
  const events = createSampleEvents(now)
  const today = now.toISOString().slice(0, 10)
  const defaultStart = new Date(Date.parse(today) - 6 * 86_400_000).toISOString().slice(0, 10)

  return (request: IncomingMessage, response: ServerResponse, next: () => void) => {
    const url = new URL(request.url ?? '/', 'http://localhost')
    const restRoute = url.searchParams.get('rest_route')
    const path = restRoute === null ? url.pathname : `/wp-json${restRoute}`
    if (!path.startsWith(prefix)) {
      next()
      return
    }

    const scenario = url.searchParams.get('mock') ?? defaultScenario
    const endpoint = routes.get(path.slice(prefix.length))
    if (!scenarios.has(scenario) || !endpoint) {
      sendJson(response, 404, {
        code: 'omongstat_mock_not_found',
        message: 'Unknown sample endpoint.',
      })
      return
    }
    if (request.method !== 'GET') {
      response.setHeader('Allow', 'GET')
      sendJson(response, 405, { message: 'The sample API only supports GET requests.' })
      return
    }

    const start = url.searchParams.get('start') ?? defaultStart
    const end = url.searchParams.get('end') ?? today
    const rangeDays = (Date.parse(end) - Date.parse(start)) / 86_400_000
    if (!validDate(start) || !validDate(end) || rangeDays < 0 || rangeDays > 365) {
      sendJson(response, 400, {
        code: 'omongstat_dates',
        message: 'Choose valid dates in order, covering at most 366 days.',
      })
      return
    }

    const timer = setTimeout(
      () => {
        if (response.destroyed) return
        if (scenario === 'error') {
          sendJson(response, 500, {
            code: 'omongstat_db_error',
            message: 'Simulated statistics failure.',
          })
          return
        }
        const data = sampleStatistics(scenario === 'empty' ? [] : events, start, end, today)
        sendJson(response, 200, data[endpoint])
      },
      scenario === 'slow' ? 3000 : 150,
    )

    response.once('close', () => clearTimeout(timer))
  }
}

export function mockApiPlugin(scenario = 'normal'): Plugin {
  if (!scenarios.has(scenario)) {
    throw new Error('OMONGSTAT_MOCK_SCENARIO must be normal, empty, error, or slow.')
  }

  const now = new Date()
  return {
    name: 'omongstat-sample-api',
    apply: 'serve',
    configureServer(server) {
      server.middlewares.use(createMockHandler(now, scenario))
    },
    transformIndexHtml(html) {
      // Supply the WordPress page bootstrap without changing React or its entry point.
      const config = {
        restUrl: prefix,
        nonce: 'sample-api',
        timezone: 'UTC',
        today: now.toISOString().slice(0, 10),
      }
      return {
        html: html.replace('id="root"', 'id="omongstat-root"'),
        tags: [
          {
            tag: 'script',
            injectTo: 'head-prepend',
            children: `window.OmongStatAdmin = ${JSON.stringify(config)};`,
          },
        ],
      }
    },
  }
}

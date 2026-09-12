import type { AdminConfig, DateRange, Statistics } from './types'

type Endpoint = keyof Statistics

const endpoints: Endpoint[] = [
  'summary',
  'timeseries',
  'pages',
  'referrers',
  'countries',
  'technology',
  'recent',
]

function endpointUrl(config: AdminConfig, endpoint: Endpoint, range: DateRange) {
  const url = new URL(config.restUrl, window.location.origin)
  const route = endpoint === 'recent' ? 'events/recent' : `stats/${endpoint}`
  const restRoute = url.searchParams.get('rest_route')

  // WordPress supports both pretty permalinks and ?rest_route= URLs.
  if (restRoute !== null) {
    url.searchParams.set('rest_route', `${restRoute.replace(/\/$/, '')}/${route}`)
  } else {
    url.pathname = `${url.pathname.replace(/\/$/, '')}/${route}`
  }

  url.searchParams.set('start', range.start)
  url.searchParams.set('end', range.end)
  return url
}

function hasExpectedShape(endpoint: Endpoint, body: unknown): boolean {
  if (endpoint === 'summary') {
    return (
      typeof body === 'object' &&
      body !== null &&
      'pageviews' in body &&
      typeof body.pageviews === 'number'
    )
  }

  if (endpoint === 'technology') {
    return (
      typeof body === 'object' && body !== null && 'browser' in body && Array.isArray(body.browser)
    )
  }

  return Array.isArray(body)
}

async function fetchEndpoint(
  config: AdminConfig,
  endpoint: Endpoint,
  range: DateRange,
  signal: AbortSignal,
) {
  const response = await fetch(endpointUrl(config, endpoint, range), {
    headers: { 'X-WP-Nonce': config.nonce },
    signal,
    cache: 'no-store',
  })

  let body: unknown
  try {
    body = await response.json()
  } catch {
    throw new Error(`${endpoint}: Could not parse the response (HTTP ${response.status}).`)
  }

  if (!response.ok) {
    const message =
      typeof body === 'object' && body !== null && 'message' in body
        ? String(body.message)
        : 'Request failed'
    throw new Error(`${endpoint}: ${message} (HTTP ${response.status}).`)
  }

  if (!hasExpectedShape(endpoint, body)) {
    throw new Error(`${endpoint}: Unexpected response format.`)
  }

  return [endpoint, body] as const
}

export async function fetchStatistics(
  config: AdminConfig,
  range: DateRange,
  signal: AbortSignal,
): Promise<Statistics> {
  const results = await Promise.all(
    endpoints.map((endpoint) => fetchEndpoint(config, endpoint, range, signal)),
  )
  return Object.fromEntries(results) as unknown as Statistics
}

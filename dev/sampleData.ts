import type { AnalyticsEvent, DistributionRow, Statistics } from '../src/types.ts'

const dayMilliseconds = 86_400_000

interface SampleEvent extends AnalyticsEvent {
  visitorId: string | null
  sessionId: string | null
  country: string
  browser: string
  operatingSystem: string
  deviceType: string
  screen: string | null
}

const paths = ['/', '/getting-started/', '/wordpress-performance/', '/about/', '/archive/']
const referrers = [
  '',
  'https://www.google.com/',
  'https://github.com/',
  'https://news.ycombinator.com/',
]
const countries = ['US', 'KR', 'DE', 'GB', 'JP']
const devices = [
  { browser: 'Chrome', os: 'Windows', type: 'Desktop', screen: '1920 × 1080' },
  { browser: 'Safari', os: 'iOS', type: 'Mobile', screen: '390 × 844' },
  { browser: 'Firefox', os: 'Linux', type: 'Desktop', screen: '1440 × 900' },
  { browser: 'Chrome', os: 'Android', type: 'Mobile', screen: '412 × 915' },
  { browser: 'Safari', os: 'macOS', type: 'Desktop', screen: '2560 × 1600' },
  { browser: 'Safari', os: 'iOS', type: 'Tablet', screen: '820 × 1180' },
]

/** Deterministic visits for the last 90 UTC dates, with no persistence. */
export function createSampleEvents(now = new Date()): SampleEvent[] {
  const today = Date.parse(now.toISOString().slice(0, 10))
  const events: SampleEvent[] = []

  for (let dayOffset = 89; dayOffset >= 0; dayOffset--) {
    const dayStart = today - dayOffset * dayMilliseconds
    const dayNumber = Math.floor(dayStart / dayMilliseconds)
    const count = 35 + ((dayNumber * 17) % 85)
    const availableTime = dayOffset === 0 ? now.getTime() - dayStart : dayMilliseconds

    for (let index = 0; index < count; index++) {
      const device = devices[(index + dayNumber) % devices.length]
      const imported = index % 11 === 0
      const timestamp = new Date(
        dayStart + Math.floor((index / count) * availableTime),
      ).toISOString()
      events.push({
        id: `${dayNumber}-${index}`,
        occurred_at: timestamp,
        occurred_at_local: timestamp.slice(0, 19).replace('T', ' '),
        path: paths[(index * 3 + dayNumber) % paths.length],
        referrer: referrers[index % referrers.length],
        source: imported ? 'log_import' : 'collector',
        is_bot: '0',
        visitorId: imported ? null : `visitor-${(dayNumber * 7 + Math.floor(index / 2) * 3) % 300}`,
        sessionId: imported ? null : `session-${dayNumber}-${Math.floor(index / 2)}`,
        country: imported ? 'Unknown' : countries[(index + dayNumber) % countries.length],
        browser: device.browser,
        operatingSystem: device.os,
        deviceType: device.type,
        screen: imported ? null : device.screen,
      })
    }
  }

  return events
}

function distribution(
  events: SampleEvent[],
  labelFor: (event: SampleEvent) => string | null,
): DistributionRow[] {
  const counts = new Map<string, number>()
  for (const event of events) {
    const label = labelFor(event)
    if (label !== null) counts.set(label, (counts.get(label) ?? 0) + 1)
  }
  return [...counts]
    .map(([label, count]) => ({ label, count }))
    .sort(
      (left, right) =>
        Number(right.count) - Number(left.count) || left.label.localeCompare(right.label),
    )
    .slice(0, 50)
}

export function sampleStatistics(
  events: SampleEvent[],
  start: string,
  end: string,
  today: string,
): Statistics {
  const selected = events.filter((event) => {
    const date = event.occurred_at.slice(0, 10)
    return date >= start && date <= end
  })
  const dailyCounts = new Map<string, number>()
  for (const event of selected) {
    const date = event.occurred_at.slice(0, 10)
    dailyCounts.set(date, (dailyCounts.get(date) ?? 0) + 1)
  }

  const timeseries: DistributionRow[] = []
  for (let day = Date.parse(start); day <= Date.parse(end); day += dayMilliseconds) {
    const label = new Date(day).toISOString().slice(0, 10)
    timeseries.push({ label, count: dailyCounts.get(label) ?? 0 })
  }

  return {
    summary: {
      total: events.length,
      today: events.filter((event) => event.occurred_at.startsWith(today)).length,
      pageviews: selected.length,
      visitors: new Set(selected.map((event) => event.visitorId).filter(Boolean)).size,
      sessions: new Set(selected.map((event) => event.sessionId).filter(Boolean)).size,
    },
    timeseries,
    pages: distribution(selected, (event) => event.path),
    referrers: distribution(selected, (event) => event.referrer || 'Unknown'),
    countries: distribution(selected, (event) => event.country),
    technology: {
      browser: distribution(selected, (event) => event.browser),
      operating_system: distribution(selected, (event) => event.operatingSystem),
      device_type: distribution(selected, (event) => event.deviceType),
      screen: distribution(selected, (event) => event.screen),
      is_bot: distribution(selected, (event) => event.is_bot),
    },
    recent: [...selected]
      .sort((left, right) => right.occurred_at.localeCompare(left.occurred_at))
      .slice(0, 50)
      .map(({ id, occurred_at, occurred_at_local, path, referrer, source, is_bot }) => ({
        id,
        occurred_at,
        occurred_at_local,
        path,
        referrer,
        source,
        is_bot,
      })),
  }
}

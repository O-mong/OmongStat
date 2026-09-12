export interface AdminConfig {
  restUrl: string
  nonce: string
  timezone: string
  today: string
}

export interface DateRange {
  start: string
  end: string
}

export interface DistributionRow {
  label: string
  count: number | string
}

export interface AnalyticsEvent {
  id: string
  occurred_at: string
  occurred_at_local: string
  path: string
  referrer: string | null
  source: string
  is_bot: string
}

export interface Summary {
  total: number
  today: number
  pageviews: number
  visitors: number
  sessions: number
}

export interface Technology {
  browser: DistributionRow[]
  operating_system: DistributionRow[]
  device_type: DistributionRow[]
  screen: DistributionRow[]
  is_bot: DistributionRow[]
}

export interface Statistics {
  summary: Summary
  timeseries: DistributionRow[]
  pages: DistributionRow[]
  referrers: DistributionRow[]
  countries: DistributionRow[]
  technology: Technology
  recent: AnalyticsEvent[]
}

declare global {
  interface Window {
    OmongStatAdmin: AdminConfig
  }
}

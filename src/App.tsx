import { useState } from 'react'
import Distribution from './components/Distribution'
import RecentEvents from './components/RecentEvents'
import TrafficChart from './components/TrafficChart'
import { useStatistics } from './useStatistics'
import type { Summary, Technology } from './types'

const config = window.OmongStatAdmin

const summaryLabels: Record<keyof Summary, string> = {
  total: 'Total pageviews',
  today: 'Today’s pageviews',
  pageviews: 'Selected period',
  visitors: 'Unique visitors',
  sessions: 'Sessions',
}

const technologyLabels: Record<keyof Technology, string> = {
  browser: 'Browsers',
  operating_system: 'Operating systems',
  device_type: 'Device types',
  screen: 'Screen sizes',
  is_bot: 'Estimated bots (1: bot, 0: other)',
}

function daysBefore(date: string, days: number): string {
  const value = new Date(`${date}T12:00:00Z`)
  value.setUTCDate(value.getUTCDate() - days)
  return value.toISOString().slice(0, 10)
}

export default function App() {
  const [preset, setPreset] = useState('7')
  const [start, setStart] = useState(daysBefore(config.today, 6))
  const [end, setEnd] = useState(config.today)
  const [refresh, setRefresh] = useState(0)
  const { data, error } = useStatistics(config, { start, end }, refresh)

  function selectPreset(value: string) {
    setPreset(value)
    if (value !== 'custom') {
      setEnd(config.today)
      setStart(daysBefore(config.today, Number(value) - 1))
    }
  }

  return (
    <div className="omongstat">
      <div className="omongstat-filters">
        <label>
          Period{' '}
          <select value={preset} onChange={(event) => selectPreset(event.target.value)}>
            <option value="1">Today</option>
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days</option>
            <option value="custom">Custom range</option>
          </select>
        </label>
        <label>
          Start{' '}
          <input
            type="date"
            value={start}
            onChange={(event) => {
              setPreset('custom')
              setStart(event.target.value)
            }}
          />
        </label>
        <label>
          End{' '}
          <input
            type="date"
            value={end}
            onChange={(event) => {
              setPreset('custom')
              setEnd(event.target.value)
            }}
          />
        </label>
        <button onClick={() => setRefresh((value) => value + 1)}>Refresh</button>
        <span>Time zone: {config.timezone}</span>
      </div>

      {error && (
        <p role="alert" className="omongstat-error">
          {error}
        </p>
      )}
      {!error && !data && <p role="status">Loading statistics…</p>}

      {data && (
        <>
          <div className="omongstat-cards">
            {(Object.keys(summaryLabels) as (keyof Summary)[]).map((key) => (
              <section key={key}>
                <h2>{summaryLabels[key]}</h2>
                <strong>{data.summary[key].toLocaleString('en-US')}</strong>
              </section>
            ))}
          </div>
          <p>
            Historical events without identifiers are excluded from visitor and session counts. Bot
            detection is an estimate based on the User-Agent.
          </p>
          <TrafficChart rows={data.timeseries} />
          <div className="omongstat-grid">
            <Distribution title="Popular paths" rows={data.pages} />
            <Distribution
              title="Referrers (Unknown: direct or unavailable)"
              rows={data.referrers}
            />
            <Distribution title="Countries" rows={data.countries} />
            {(Object.keys(technologyLabels) as (keyof Technology)[]).map((key) => (
              <Distribution key={key} title={technologyLabels[key]} rows={data.technology[key]} />
            ))}
          </div>
          <RecentEvents events={data.recent} />
        </>
      )}
    </div>
  )
}

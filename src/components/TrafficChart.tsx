import type { DistributionRow } from '../types'
import Distribution from './Distribution'

export default function TrafficChart({ rows }: { rows: DistributionRow[] }) {
  const maximum = Math.max(1, ...rows.map((row) => Number(row.count)))

  return (
    <section>
      <h2>Daily traffic</h2>
      <div className="omongstat-chart" aria-label="Daily pageviews">
        {rows.map((row) => (
          <div key={row.label} title={`${row.label}: ${row.count}`}>
            <span style={{ height: `${Math.max(1, (Number(row.count) / maximum) * 140)}px` }} />
            <small>{row.label.slice(5)}</small>
          </div>
        ))}
      </div>
      <details>
        <summary>View daily counts</summary>
        <Distribution title="Daily pageviews" rows={rows} />
      </details>
    </section>
  )
}

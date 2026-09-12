import type { AnalyticsEvent } from '../types'

export default function RecentEvents({ events }: { events: AnalyticsEvent[] }) {
  return (
    <section>
      <h2>Recent events (selected period, up to 50)</h2>
      {events.length === 0 ? (
        <p>No events found for the selected period.</p>
      ) : (
        <div className="omongstat-scroll">
          <table>
            <thead>
              <tr>
                <th>Time</th>
                <th>Path</th>
                <th>Referrer</th>
                <th>Source</th>
                <th>Likely bot</th>
              </tr>
            </thead>
            <tbody>
              {events.map((event) => (
                <tr key={event.id}>
                  <td>{event.occurred_at_local}</td>
                  <td>{event.path}</td>
                  <td>{event.referrer || '—'}</td>
                  <td>{event.source}</td>
                  <td>{Number(event.is_bot) ? 'Yes' : 'No'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

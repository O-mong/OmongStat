import type { DistributionRow } from '../types'

interface Props {
  title: string
  rows: DistributionRow[]
}

export default function Distribution({ title, rows }: Props) {
  return (
    <section>
      <h2>{title}</h2>
      {rows.length === 0 ? (
        <p>No data available.</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Item</th>
              <th>Pageviews</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.label}>
                <td>{row.label}</td>
                <td>{Number(row.count).toLocaleString('en-US')}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  )
}

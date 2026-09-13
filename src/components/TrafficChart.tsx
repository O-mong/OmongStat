import { useState, type ComponentType } from 'react'
import type { DistributionRow } from '../types'
import Distribution from './Distribution'
import "./components.css"

import {
  ResponsiveContainer,
  BarChart,
  Bar,
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
} from 'recharts'

type ChartType = 'bar' | 'line'

interface ChartProps {
  rows: DistributionRow[]
}

const chartTooltipProps = {
  contentStyle: {
    backgroundColor: '#ffffff',
    border: '1px solid #dcdcde',
    borderRadius: '2px',
    color: '#1d2327',
  },
  labelStyle: { color: '#50575e' },
  itemStyle: { color: '#1d2327' },
}

const chartComponents: Record<ChartType, ComponentType<ChartProps>> = {
  bar: BarChartComponent,
  line: LineChartComponent,
}

export default function TrafficChart({ rows }: ChartProps) {
  const [chartType, setChartType] = useState<ChartType>('bar')
  const ChartComponent = chartComponents[chartType]

  return (
    <section>
      <div className="omongstat-chart-header">
        <h2>Daily traffic</h2>
        <label>
          Chart type
          <select
            value={chartType}
            onChange={(event) =>
              setChartType(event.target.value as ChartType)
            }
          >
            <option value="bar">Bar</option>
            <option value="line">Line</option>
          </select>
        </label>
      </div>

      <ChartComponent rows={rows} />

      <details>
        <summary>View daily counts</summary>
        <Distribution title="Daily pageviews" rows={rows} />
      </details>
    </section>
  )
}

function BarChartComponent({ rows }: { rows: DistributionRow[] }) {
  const data = rows.map((row) => ({
    date: row.label,
    count: Number(row.count),
  }))

  return (
    <div
      className="omongstat-chart"
      role="img"
      aria-label="Daily pageviews bar chart"
    >
      <ResponsiveContainer width="100%" height={300}>
        <BarChart data={data}>
          <CartesianGrid strokeDasharray="3 3" vertical={false} />
          <XAxis
            dataKey="date"
            tickFormatter={(date: string) => date.slice(5)}
          />
          <YAxis allowDecimals={false} />
          <Tooltip {...chartTooltipProps} cursor={false} />
          <Bar dataKey="count" name="Pageview" fill="#8884d8" />
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

function LineChartComponent({ rows }: { rows: DistributionRow[] }) {
  const data = rows.map((row) => ({
    date: row.label,
    count: Number(row.count),
  }))

  return (
    <div className="omongstat-chart">
      <ResponsiveContainer width="100%" height={300}>
        <LineChart data={data}>
          <CartesianGrid strokeDasharray="3 3" vertical={false} />
          <XAxis
            dataKey="date"
            tickFormatter={(date: string) => date.slice(5)}
          />
          <YAxis allowDecimals={false} />
          <Tooltip {...chartTooltipProps} cursor={false} />
          <Line
            type="monotone"
            dataKey="count"
            name="Pageviews"
            stroke="#8884d8"
            strokeWidth={2}
            activeDot={{ r: 6 }}
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  )
}

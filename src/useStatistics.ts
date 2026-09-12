import { useEffect, useState } from 'react'
import { fetchStatistics } from './api'
import type { AdminConfig, DateRange, Statistics } from './types'

interface RequestResult {
  key: string
  data: Statistics | null
  error: string
}

export function useStatistics(config: AdminConfig, range: DateRange, refresh: number) {
  const { start, end } = range
  const requestKey = `${start}:${end}:${refresh}`
  const invalidRange = !start || !end || start > end
  const [result, setResult] = useState<RequestResult | null>(null)

  useEffect(() => {
    if (invalidRange) return

    const controller = new AbortController()
    fetchStatistics(config, { start, end }, controller.signal)
      .then((data) => {
        if (!controller.signal.aborted) {
          setResult({ key: requestKey, data, error: '' })
        }
      })
      .catch((error) => {
        if (!controller.signal.aborted) {
          setResult({
            key: requestKey,
            data: null,
            error: error instanceof Error ? error.message : 'Request failed.',
          })
        }
      })

    return () => controller.abort()
  }, [config, start, end, requestKey, invalidRange])

  if (invalidRange) {
    return { data: null, error: 'Choose a valid start and end date.' }
  }

  // A result belongs to one date range and refresh; older data stays hidden while loading.
  if (result?.key !== requestKey) return { data: null, error: '' }
  return result
}

;(() => {
  const config = window.OmongStat
  if (!config?.collectUrl || window.__omongstatCollected) return
  window.__omongstatCollected = true
  const makeId = () => {
    if (globalThis.crypto?.randomUUID) return crypto.randomUUID()
    if (globalThis.crypto?.getRandomValues)
      return Array.from(crypto.getRandomValues(new Uint8Array(16)), (value) =>
        value.toString(16).padStart(2, '0'),
      ).join('')
    // Older browsers: anonymous identifier only, never an authentication token.
    return (
      Date.now().toString(36) +
      Math.random().toString(36).slice(2) +
      Math.random().toString(36).slice(2)
    )
  }
  const storedId = (storageName, key) => {
    try {
      const storage = window[storageName]
      let value = storage.getItem(key)
      if (!/^[a-zA-Z0-9_-]{16,64}$/.test(value || '')) {
        value = makeId()
        storage.setItem(key, value)
      }
      return value
    } catch {
      return makeId()
    }
  }
  let timezone = ''
  try {
    timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''
  } catch {
    /* optional */
  }
  const payload = JSON.stringify({
    path: location.pathname,
    postId: Number(config.postId) || 0,
    referrer: document.referrer,
    visitorId: storedId('localStorage', 'omongstat_visitor'),
    sessionId: storedId('sessionStorage', 'omongstat_session'),
    language: navigator.language || '',
    timezone,
    screenWidth: Math.min(65535, Math.max(0, Math.round(screen.width || 0))),
    screenHeight: Math.min(65535, Math.max(0, Math.round(screen.height || 0))),
    eventType: 'pageview',
  })
  try {
    if (
      navigator.sendBeacon?.(config.collectUrl, new Blob([payload], { type: 'application/json' }))
    )
      return
  } catch (error) {
    if (config.debug) console.error('[OmongStat] Beacon failed:', error)
  }
  fetch(config.collectUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: payload,
    keepalive: true,
  })
    .then((response) => {
      if (!response.ok) throw new Error(`HTTP ${response.status}`)
    })
    .catch((error) => {
      if (config.debug) console.error('[OmongStat] collection failed:', error)
    })
})()

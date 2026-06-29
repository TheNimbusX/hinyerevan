const METRIKA_ID = 110244608

/** Track SPA navigations after the initial page load. */
export function trackYandexMetrikaPage(path) {
  try {
    if (typeof window.ym !== 'function') return
    const url = path.startsWith('http') ? path : `${window.location.origin}${path}`
    window.ym(METRIKA_ID, 'hit', url, {
      title: document.title,
      referer: document.referrer,
    })
  } catch {
    // Metrika is optional — never break navigation.
  }
}

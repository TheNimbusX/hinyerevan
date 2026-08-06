const METRIKA_ID = 110244608

export function trackYandexMetrikaPage(path) {
  try {
    if (typeof window.ym !== 'function') return
    const url = path.startsWith('http') ? path : `${window.location.origin}${path}`
    window.ym(METRIKA_ID, 'hit', url, {
      title: document.title,
      referer: document.referrer,
    })
  } catch {
  }
}

const DATE_LOCALES = {
  hy: 'hy-AM',
  ru: 'ru-RU',
  en: 'en-US',
}

const DIRECTION_KEYS = [
  'topShot',
  'north',
  'northEast',
  'east',
  'southEast',
  'south',
  'southWest',
  'west',
  'northWest',
]

export function dateLocale(lang) {
  return DATE_LOCALES[lang] || DATE_LOCALES.hy
}

export function formatDate(value, lang, options = {}) {
  if (!value) return ''
  const date = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  return date.toLocaleDateString(dateLocale(lang), {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    ...options,
  })
}

export function formatDateTime(value, lang, options = {}) {
  if (!value) return ''
  const date = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(date.getTime()) || date.getFullYear() < 1971) return ''

  return date.toLocaleString(dateLocale(lang), {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    ...options,
  })
}

export function directionLabel(direction, t) {
  const index = Number(direction)
  const key = DIRECTION_KEYS[Number.isFinite(index) ? index : 1] || 'north'
  return t(key)
}

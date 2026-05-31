const API_URL = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'

/** Absolute URL to a backend endpoint — used for full-page OAuth redirects. */
export function apiUrl(path) {
  return `${API_URL}${path}`
}
const TOKEN_KEY = 'hinyerevan_token'
const LANGUAGE_KEY = 'hinyerevan_language'
const CACHE_PREFIX = 'hinyerevan:api-cache:'

function getRequestLang() {
  return localStorage.getItem(LANGUAGE_KEY) || 'hy'
}

/** Append ?lang= for non-Armenian UI (backend translates dynamic content). */
export function withLang(path) {
  const lang = getRequestLang()
  if (lang === 'hy') return path
  if (/[?&]lang=/.test(path)) return path
  const separator = path.includes('?') ? '&' : '?'
  return `${path}${separator}lang=${encodeURIComponent(lang)}`
}

export function getToken() {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token) {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token)
  } else {
    localStorage.removeItem(TOKEN_KEY)
  }

  window.dispatchEvent(new CustomEvent('hinyerevan:auth-changed', { detail: { token } }))
}

export function clearApiCache() {
  Object.keys(localStorage)
    .filter((key) => key.startsWith(CACHE_PREFIX))
    .forEach((key) => localStorage.removeItem(key))
}

export async function api(path, options = {}) {
  const headers = {
    Accept: 'application/json',
    ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
    ...options.headers,
  }

  const token = getToken()
  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  const localizedPath =
    options.skipLang || (options.method || 'GET').toUpperCase() !== 'GET' ? path : withLang(path)

  const controller = new AbortController()
  const timeoutMs = options.timeoutMs ?? 45000
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs)

  let response
  try {
    response = await fetch(`${API_URL}${localizedPath}`, {
      ...options,
      headers,
      signal: options.signal ?? controller.signal,
      body:
        options.body instanceof FormData || typeof options.body === 'string'
          ? options.body
          : options.body
            ? JSON.stringify(options.body)
            : undefined,
    })
  } catch (error) {
    if (error?.name === 'AbortError') {
      throw new Error('Request timed out')
    }
    throw error
  } finally {
    clearTimeout(timeoutId)
  }

  if (response.status === 204) {
    return null
  }

  // Uploads can be rejected by PHP / proxy before Laravel sees it.
  if (response.status === 413) {
    throw new Error('File is too large')
  }

  const payload = await response.json().catch(() => null)
  if (!response.ok) {
    // Laravel validation: { message, errors: { field: [messages...] } }
    if (payload && typeof payload === 'object' && payload.errors && typeof payload.errors === 'object') {
      const firstField = Object.keys(payload.errors)[0]
      const firstMessage = firstField ? payload.errors[firstField]?.[0] : null
      if (typeof firstMessage === 'string' && firstMessage.trim() !== '') {
        throw new Error(firstMessage)
      }
    }

    const message = payload?.message || 'Request failed'
    throw new Error(message)
  }

  if ((options.method || 'GET').toUpperCase() !== 'GET') {
    clearApiCache()
  }

  return payload
}

export async function cachedApi(path, options = {}) {
  const ttl = options.ttl ?? 10 * 60 * 1000
  const localizedPath = withLang(path)
  const cacheKey = options.cacheKey || `${CACHE_PREFIX}${localizedPath}`
  const now = Date.now()

  try {
    const cached = JSON.parse(localStorage.getItem(cacheKey) || 'null')
    if (cached && now - cached.savedAt < ttl) {
      return cached.payload
    }
  } catch {
    localStorage.removeItem(cacheKey)
  }

  const payload = await api(localizedPath, { skipLang: true })
  try {
    localStorage.setItem(cacheKey, JSON.stringify({ savedAt: now, payload }))
  } catch {
    localStorage.removeItem(cacheKey)
  }

  return payload
}

export function imageUrl(path) {
  if (!path) return ''
  if (path.startsWith('http') || path.startsWith('/demo') || path.startsWith('data:')) {
    return path
  }

  return `${API_URL.replace(/\/api$/, '')}${path}`
}

export function safeAvatarUrl(photo, fallback = '/Logo2026.png') {
  if (!photo) return fallback

  if (photo.includes('hinyerevan.com/photos/users/')) {
    return imageUrl(`/api/photos/file/users/${photo.split('/').pop()}`)
  }

  if (photo.startsWith('http://graph.facebook.com/')) {
    return photo.replace('http://', 'https://')
  }

  if (photo.startsWith('https://graph.facebook.com/')) {
    return photo
  }

  if (photo.startsWith('http')) {
    return fallback
  }

  return imageUrl(`/api/photos/file/users/${photo}`)
}

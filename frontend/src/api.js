import { getUiLanguage } from './utils/browserTranslate'

const API_URL = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const TOKEN_KEY = 'hinyerevan_token'
const CACHE_PREFIX = 'hinyerevan:api-cache:'

/** Absolute URL to a backend endpoint — used for full-page OAuth redirects. */
export function apiUrl(path) {
  return `${API_URL}${path}`
}

export function withLang(path, { translateScope } = {}) {
  const lang = getUiLanguage()
  if (lang === 'hy') {
    return path
  }
  const separator = path.includes('?') ? '&' : '?'
  let localized = `${path}${separator}lang=${encodeURIComponent(lang)}`
  if (translateScope === 'main') {
    localized += '&translate=main'
  }
  return localized
}

function isPhotoDetailPath(path) {
  return /^\/photos\/\d+$/.test(path.split('?')[0])
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

export function clearApiCacheForLanguage(lang) {
  const prefix = `${CACHE_PREFIX}${lang}:`
  Object.keys(localStorage)
    .filter((key) => key.startsWith(prefix))
    .forEach((key) => localStorage.removeItem(key))
}

/** Drop cached GET payloads for one API path (all languages). */
export function clearApiCacheForPath(path) {
  const base = path.split('?')[0]
  Object.keys(localStorage)
    .filter((key) => key.startsWith(CACHE_PREFIX) && key.includes(`:${base}`))
    .forEach((key) => localStorage.removeItem(key))
}

function readCacheEntry(key, ttl) {
  try {
    const cached = JSON.parse(localStorage.getItem(key) || 'null')
    if (cached && Date.now() - cached.savedAt < ttl) {
      return cached.payload
    }
  } catch {
    localStorage.removeItem(key)
  }

  return null
}

function writeCacheEntry(key, payload) {
  try {
    localStorage.setItem(key, JSON.stringify({ savedAt: Date.now(), payload }))
  } catch {
    localStorage.removeItem(key)
  }
}

function queueLocalizedRefresh(path, lang, cacheKey) {
  const translateScope = isPhotoDetailPath(path) ? 'main' : undefined
  void api(path, { translateScope })
    .then((payload) => {
      writeCacheEntry(cacheKey, payload)
      window.dispatchEvent(
        new CustomEvent('hinyerevan:localized-ready', { detail: { path, lang } }),
      )
    })
    .catch(() => {})
}

/** Fast Armenian payload first, translated version loads in the background when needed. */
export async function localizedApi(path, options = {}) {
  const ttl = options.ttl ?? 10 * 60 * 1000
  const lang = getUiLanguage()
  const cacheKey = options.cacheKey || `${CACHE_PREFIX}${lang}:${path}`
  const hyKey = `${CACHE_PREFIX}hy:${path}`

  const cached = readCacheEntry(cacheKey, ttl)
  if (cached) {
    return cached
  }

  if (lang !== 'hy') {
    const hyCached = readCacheEntry(hyKey, ttl)
    if (hyCached) {
      queueLocalizedRefresh(path, lang, cacheKey, ttl)
      return hyCached
    }

    const hyPayload = await api(path, { skipLang: true })
    writeCacheEntry(hyKey, hyPayload)
    queueLocalizedRefresh(path, lang, cacheKey, ttl)
    return hyPayload
  }

  const payload = await api(path)
  writeCacheEntry(cacheKey, payload)
  return payload
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
    options.skipLang || (options.method || 'GET').toUpperCase() !== 'GET'
      ? path
      : withLang(path, { translateScope: options.translateScope })

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
    let message = payload?.message || 'Request failed'
    if (payload && typeof payload === 'object' && payload.errors && typeof payload.errors === 'object') {
      const firstField = Object.keys(payload.errors)[0]
      const firstMessage = firstField ? payload.errors[firstField]?.[0] : null
      if (typeof firstMessage === 'string' && firstMessage.trim() !== '') {
        message = firstMessage
      }
    }

    const err = new Error(message)
    err.status = response.status
    throw err
  }

  if ((options.method || 'GET').toUpperCase() !== 'GET') {
    clearApiCache()
  }

  return payload
}

export async function cachedApi(path, options = {}) {
  return localizedApi(path, options)
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
    const id = photo.split('/').pop()
    return imageUrl(`/api/photos/file/users/${id}?w=512&v=2`)
  }

  if (photo.includes('graph.facebook.com') || photo.includes('fbcdn.net')) {
    const https = photo.replace(/^http:\/\//, 'https://')
    try {
      const url = new URL(https)
      url.searchParams.set('width', '320')
      url.searchParams.set('height', '320')
      return url.toString()
    } catch {
      return https
    }
  }

  if (photo.includes('googleusercontent.com')) {
    if (/=s\d+-c/.test(photo)) return photo.replace(/=s\d+-c/, '=s256-c')
    return photo.includes('?') ? `${photo}&sz=256` : `${photo}?sz=256`
  }

  if (photo.startsWith('http')) {
    return fallback
  }

  return imageUrl(`/api/photos/file/users/${photo}?w=512&v=2`)
}

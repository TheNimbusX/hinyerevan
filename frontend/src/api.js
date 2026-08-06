import { getUiLanguage } from './utils/browserTranslate'
import { socialAvatarUrl } from './utils/socialProviderIcons'

const API_URL = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const TOKEN_KEY = 'hinyerevan_token'
const DEV_AUTH_KEY = 'hinyerevan_dev_auth'
const CACHE_PREFIX = 'hinyerevan:api-cache:'

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

function isPhotosListPath(path) {
  const base = path.split('?')[0]
  return base === '/photos' && path.includes('?')
}

function defaultCacheTtl(path) {
  if (isPhotosListPath(path)) return 90 * 1000
  if (isPhotoDetailPath(path.split('?')[0])) return 5 * 60 * 1000
  return 10 * 60 * 1000
}

export function getToken() {
  return localStorage.getItem(TOKEN_KEY)
}

export function getDevAuthToken() {
  return sessionStorage.getItem(DEV_AUTH_KEY)
}

export function setDevAuthToken(token) {
  if (token) {
    sessionStorage.setItem(DEV_AUTH_KEY, token)
  } else {
    sessionStorage.removeItem(DEV_AUTH_KEY)
  }
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

export function clearApiCacheForPath(path) {
  const base = path.split('?')[0]
  Object.keys(localStorage)
    .filter((key) => key.startsWith(CACHE_PREFIX) && key.includes(`:${base}`))
    .forEach((key) => localStorage.removeItem(key))
}

export function clearPhotosApiCache(photoId = null) {
  Object.keys(localStorage)
    .filter((key) => {
      if (!key.startsWith(CACHE_PREFIX)) return false
      const entry = key.slice(CACHE_PREFIX.length)
      if (entry.includes('/photos?')) return true
      if (entry.includes('/photos/markers')) return true
      if (photoId && entry.includes(`/photos/${photoId}`)) return true
      return false
    })
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

export async function localizedApi(path, options = {}) {
  const basePath = path.split('?')[0]
  if (getToken() && isPhotoDetailPath(basePath)) {
    return api(path, { translateScope: options.translateScope })
  }

  const ttl = options.ttl ?? defaultCacheTtl(path)
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

  const devToken = getDevAuthToken()
  if (devToken) {
    headers['X-Dev-Auth'] = devToken
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

  if (response.status === 413) {
    throw new Error('File is too large')
  }

  const payload = await response.json().catch(() => null)
  if (!response.ok) {
    let message = payload?.message || 'Request failed'
    const errors = payload?.errors && typeof payload.errors === 'object' ? payload.errors : null
    if (errors) {
      const fieldPriority = [
        'login',
        'password',
        'uid',
        'email',
        'code',
        'recaptcha_token',
        'first_name',
        'last_name',
        'password_confirmation',
        'current_password',
      ]
      let picked = ''
      for (const field of fieldPriority) {
        const candidate = errors[field]?.[0]
        if (typeof candidate === 'string' && candidate.trim() !== '') {
          picked = candidate.trim()
          break
        }
      }
      if (!picked) {
        const firstField = Object.keys(errors)[0]
        const firstMessage = firstField ? errors[firstField]?.[0] : null
        if (typeof firstMessage === 'string' && firstMessage.trim() !== '') {
          picked = firstMessage.trim()
        }
      }
      if (picked) message = picked
    }

    const err = new Error(message)
    err.status = response.status
    err.errors = errors
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

const WATERMARK_CACHE_VERSION = 9

export function imageUrl(path) {
  if (!path) return ''
  if (path.startsWith('http') || path.startsWith('/demo') || path.startsWith('data:')) {
    return path
  }

  let url = `${API_URL.replace(/\/api$/, '')}${path}`
  if (/\/api\/photos\/file\/(large|original)\//.test(path)) {
    url += `${url.includes('?') ? '&' : '?'}wm=${WATERMARK_CACHE_VERSION}`
  }

  return url
}

export function fullPhotoPath(images) {
  return images?.original || images?.large || images?.thumb || ''
}

export function previewPhotoPath(images) {
  return images?.large || images?.thumb || images?.original || ''
}

export function photoDownloadUrl(images, title = 'photo') {
  const variant = fullPhotoPath(images)
  if (!variant) return ''
  const base = imageUrl(variant)
  const separator = base.includes('?') ? '&' : '?'
  const filename = encodeURIComponent(String(title).replace(/[^\p{L}\p{N}._-]+/gu, '_').slice(0, 80) || 'photo')
  return `${base}${separator}download=1&filename=${filename}`
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

export function avatarForUser(user, fallback = '/Logo2026.png') {
  const NO_PHOTO = '\u0000no-photo'
  const own = safeAvatarUrl(user?.photo, NO_PHOTO)
  if (own !== NO_PHOTO) return own
  return socialAvatarUrl(user?.network) || fallback
}

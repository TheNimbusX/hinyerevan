export const SCROLL_RESTORE_KEY = 'hinyerevan:scroll-restore'
export const GALLERY_RESTORE_KEY = 'hinyerevan:gallery-restore'
export const HOME_MAP_RESTORE_KEY = 'hinyerevan:home-map-restore'

export function saveScrollRestore(fromPath) {
  if (!fromPath || typeof window === 'undefined') return
  sessionStorage.setItem(
    SCROLL_RESTORE_KEY,
    JSON.stringify({ path: fromPath, scrollY: window.scrollY }),
  )
}

export function consumeScrollRestore(targetPath) {
  const raw = sessionStorage.getItem(SCROLL_RESTORE_KEY)
  if (!raw) return null

  try {
    const saved = JSON.parse(raw)
    if (saved.path !== targetPath) return null
    sessionStorage.removeItem(SCROLL_RESTORE_KEY)
    const scrollY = Number(saved.scrollY)
    return Number.isFinite(scrollY) ? scrollY : 0
  } catch {
    sessionStorage.removeItem(SCROLL_RESTORE_KEY)
    return null
  }
}

export function saveGalleryRestore(state) {
  sessionStorage.setItem(GALLERY_RESTORE_KEY, JSON.stringify(state))
}

export function consumeGalleryRestore() {
  const raw = sessionStorage.getItem(GALLERY_RESTORE_KEY)
  if (!raw) return null

  sessionStorage.removeItem(GALLERY_RESTORE_KEY)
  try {
    return JSON.parse(raw)
  } catch {
    return null
  }
}

export function hasGalleryRestore() {
  return Boolean(sessionStorage.getItem(GALLERY_RESTORE_KEY))
}

export function saveHomeMapRestore(state) {
  sessionStorage.setItem(HOME_MAP_RESTORE_KEY, JSON.stringify(state))
}

export function peekHomeMapRestore() {
  const raw = sessionStorage.getItem(HOME_MAP_RESTORE_KEY)
  if (!raw) return null

  try {
    return JSON.parse(raw)
  } catch {
    return null
  }
}

export function consumeHomeMapRestore() {
  const raw = sessionStorage.getItem(HOME_MAP_RESTORE_KEY)
  if (!raw) return null

  sessionStorage.removeItem(HOME_MAP_RESTORE_KEY)
  try {
    return JSON.parse(raw)
  } catch {
    return null
  }
}

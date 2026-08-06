export function isInAppBrowser() {
  if (typeof document !== 'undefined' && document.documentElement.classList.contains('in-app-browser')) {
    return true
  }
  if (typeof navigator === 'undefined') return false
  const ua = navigator.userAgent || ''
  if (/Telegram|FBAN|FBAV|Instagram|Line\//i.test(ua)) return true
  if (typeof window.TelegramWebviewProxy !== 'undefined') return true
  if (typeof window.TelegramWebview !== 'undefined') return true
  try {
    if (document.referrer && /t\.me|telegram\.(org|me)/i.test(document.referrer)) return true
  } catch {
    // ignore
  }
  return false
}

export function markInAppBrowser() {
  if (!isInAppBrowser()) return
  document.documentElement.classList.add('in-app-browser')
}

export function removeSplashOverlay() {
  const splash = document.getElementById('app-splash')
  if (!splash || splash.classList.contains('is-dismissed')) return
  splash.classList.add('app-splash--hide', 'is-dismissed')
  splash.style.cssText =
    'display:none!important;opacity:0!important;visibility:hidden!important;pointer-events:none!important'
  window.setTimeout(() => splash.remove(), 60)
}

export function removeStuckUiOverlays() {
  removeSplashOverlay()
  document.querySelectorAll('.mobile-menu-backdrop').forEach((node) => node.remove())
  document.body.style.overflow = ''
  document.documentElement.classList.remove('menu-open')
}

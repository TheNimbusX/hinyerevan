import { createApp } from 'vue'
import './styles/main.scss'
import App from './App.vue'
import router from './router'
import { applyRouteMeta } from './utils/seo'
import { isInAppBrowser, markInAppBrowser, removeSplashOverlay, removeStuckUiOverlays } from './utils/overlayCleanup'
import { setupLeaflet } from './utils/leafletSetup'

setupLeaflet()
markInAppBrowser()

document.documentElement.lang = 'hy'

const app = createApp(App)
app.use(router)

let splashDismissed = false

function dismissSplash() {
  if (splashDismissed) return
  splashDismissed = true

  const splash = document.getElementById('app-splash')
  if (!splash) return

  const inApp = isInAppBrowser()
  const elapsed = Date.now() - (window.__splashStart || 0)
  const minVisible = inApp ? 850 : 1000
  const wait = Math.max(0, minVisible - elapsed)

  setTimeout(() => {
    removeSplashOverlay()
  }, wait)
}

router.isReady().then(() => {
  app.mount('#app')
  applyRouteMeta(router.currentRoute.value)
  dismissSplash()
}).catch(() => {
  dismissSplash()
})

setTimeout(dismissSplash, isInAppBrowser() ? 2200 : 3500)

window.addEventListener('pageshow', () => {
  removeStuckUiOverlays()
  splashDismissed = true
})

window.addEventListener('hinyerevan:language-changed', () => {
  applyRouteMeta(router.currentRoute.value)
})

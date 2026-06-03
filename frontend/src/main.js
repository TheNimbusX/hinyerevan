import { createApp } from 'vue'
import './styles/main.scss'
import App from './App.vue'
import router from './router'
import { applyRouteMeta } from './utils/seo'
import { currentLanguage } from './i18n'
import { setupLeaflet } from './utils/leafletSetup'

setupLeaflet()

document.documentElement.lang = 'hy'

const app = createApp(App)
app.use(router)

function dismissSplash() {
  const splash = document.getElementById('app-splash')
  if (!splash) return
  const elapsed = Date.now() - (window.__splashStart || 0)
  const wait = Math.max(0, 1000 - elapsed)
  setTimeout(() => {
    splash.classList.add('app-splash--hide')
    setTimeout(() => splash.remove(), 600)
  }, wait)
}

router.isReady().then(() => {
  app.mount('#app')
  applyRouteMeta(router.currentRoute.value)
  dismissSplash()
})

window.addEventListener('hinyerevan:language-changed', () => {
  applyRouteMeta(router.currentRoute.value)
})

import { createApp } from 'vue'
import './styles/main.scss'
import App from './App.vue'
import router from './router'
import { applyRouteMeta } from './utils/seo'
import { currentLanguage } from './i18n'

document.documentElement.lang = currentLanguage.value || 'hy'

createApp(App).use(router).mount('#app')

applyRouteMeta(router.currentRoute.value)

window.addEventListener('hinyerevan:language-changed', () => {
  applyRouteMeta(router.currentRoute.value)
})

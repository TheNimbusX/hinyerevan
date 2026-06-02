import { currentLanguage, translateKey } from '../i18n'

const SITE_NAME = 'HinYerevan.com'
const DEFAULT_IMAGE = '/Logo2026.png'

function siteOrigin() {
  if (typeof window !== 'undefined') return window.location.origin
  return import.meta.env.VITE_SITE_URL || ''
}

function absoluteUrl(path) {
  if (!path) return ''
  if (path.startsWith('http')) return path
  return `${siteOrigin()}${path.startsWith('/') ? path : `/${path}`}`
}

function upsertMeta(selector, attributes) {
  let element = document.head.querySelector(selector)
  if (!element) {
    element = document.createElement('meta')
    document.head.appendChild(element)
  }
  Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, value))
}

function upsertLink(rel, href) {
  if (!href) return
  let element = document.head.querySelector(`link[rel="${rel}"]`)
  if (!element) {
    element = document.createElement('link')
    element.setAttribute('rel', rel)
    document.head.appendChild(element)
  }
  element.setAttribute('href', href)
}

export function setPageMeta({ title, description, image, path, type = 'website', noindex = false } = {}) {
  const lang = currentLanguage.value
  const pageTitle = title ? `${title} — ${SITE_NAME}` : SITE_NAME
  const pageDescription = description || translateKey('metaDescriptionDefault', lang)
  const pageUrl = absoluteUrl(path || (typeof window !== 'undefined' ? window.location.pathname : '/'))
  const pageImage = absoluteUrl(image || DEFAULT_IMAGE)

  document.title = pageTitle
  document.documentElement.lang = 'hy'

  upsertMeta('meta[name="description"]', { name: 'description', content: pageDescription })
  upsertMeta('meta[name="robots"]', {
    name: 'robots',
    content: noindex ? 'noindex, nofollow' : 'index, follow',
  })
  upsertLink('canonical', pageUrl)

  upsertMeta('meta[property="og:site_name"]', { property: 'og:site_name', content: SITE_NAME })
  upsertMeta('meta[property="og:title"]', { property: 'og:title', content: pageTitle })
  upsertMeta('meta[property="og:description"]', { property: 'og:description', content: pageDescription })
  upsertMeta('meta[property="og:type"]', { property: 'og:type', content: type })
  upsertMeta('meta[property="og:url"]', { property: 'og:url', content: pageUrl })
  upsertMeta('meta[property="og:image"]', { property: 'og:image', content: pageImage })
  upsertMeta('meta[property="og:locale"]', {
    property: 'og:locale',
    content: lang === 'ru' ? 'ru_RU' : lang === 'en' ? 'en_US' : 'hy_AM',
  })

  upsertMeta('meta[name="twitter:card"]', { name: 'twitter:card', content: 'summary_large_image' })
  upsertMeta('meta[name="twitter:title"]', { name: 'twitter:title', content: pageTitle })
  upsertMeta('meta[name="twitter:description"]', { name: 'twitter:description', content: pageDescription })
  upsertMeta('meta[name="twitter:image"]', { name: 'twitter:image', content: pageImage })
}

export function applyRouteMeta(route) {
  const lang = currentLanguage.value
  const titleKey = route.meta?.titleKey
  const descriptionKey = route.meta?.descriptionKey || 'metaDescriptionDefault'

  setPageMeta({
    title: titleKey ? translateKey(titleKey, lang) : undefined,
    description: translateKey(descriptionKey, lang),
    path: route.fullPath,
    noindex: Boolean(route.meta?.noindex),
  })
}

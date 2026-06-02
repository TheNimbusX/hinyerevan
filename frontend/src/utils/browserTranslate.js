const SOURCE = 'hy'
const UI_LANG_KEY = 'hinyerevan_language'

/** Fields from API JSON that may contain Armenian user content. */
const TEXT_KEYS = new Set(['title', 'body', 'content', 'direction_label'])

const stringCache = new Map()
const translatorPromises = new Map()

export function getUiLanguage() {
  return localStorage.getItem(UI_LANG_KEY) || 'hy'
}

export function isBrowserTranslateSupported() {
  return typeof globalThis.Translator !== 'undefined'
}

/** Call synchronously from a click handler (user activation required by Chrome). */
export function prepareBrowserTranslator(targetLang) {
  if (targetLang === 'hy' || !isBrowserTranslateSupported()) {
    return
  }
  void getTranslator(targetLang)
}

export async function getTranslator(targetLang) {
  if (targetLang === 'hy' || !isBrowserTranslateSupported()) {
    return null
  }

  if (translatorPromises.has(targetLang)) {
    return translatorPromises.get(targetLang)
  }

  const promise = (async () => {
    try {
      const availability = await Translator.availability({
        sourceLanguage: SOURCE,
        targetLanguage: targetLang,
      })

      if (availability === 'unavailable') {
        return null
      }

      return await Translator.create({
        sourceLanguage: SOURCE,
        targetLanguage: targetLang,
      })
    } catch {
      return null
    }
  })()

  translatorPromises.set(targetLang, promise)
  return promise
}

async function translatePlain(text, targetLang) {
  const trimmed = text.trim()
  if (!trimmed || targetLang === 'hy') {
    return text
  }

  const cacheKey = `${targetLang}\0${trimmed}`
  if (stringCache.has(cacheKey)) {
    return stringCache.get(cacheKey)
  }

  const translator = await getTranslator(targetLang)
  if (!translator) {
    return text
  }

  try {
    const translated = await translator.translate(trimmed)
    const result = translated || text
    stringCache.set(cacheKey, result)
    return result
  } catch {
    return text
  }
}

function shouldTranslateString(value) {
  if (typeof value !== 'string') return false
  const text = value.trim()
  if (text.length < 2 || text.length > 700) return false
  if (/^https?:\/\//i.test(text)) return false
  if (/^[a-f0-9]{16,}$/i.test(text)) return false
  return /\p{L}/u.test(text)
}

function stripHtml(html) {
  if (!html.includes('<')) {
    return html
  }
  const node = document.createElement('div')
  node.innerHTML = html
  return (node.textContent || '').replace(/\s+/g, ' ').trim()
}

async function translateHtmlField(html, targetLang) {
  if (!html || targetLang === 'hy') {
    return html
  }

  if (!html.includes('<')) {
    return translatePlain(html, targetLang)
  }

  const plain = stripHtml(html)
  if (!plain) {
    return html
  }

  const translated = await translatePlain(plain, targetLang)
  return `<p>${escapeHtml(translated)}</p>`
}

function escapeHtml(text) {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

function collectEntries(value, entries, arrayPath = '') {
  if (Array.isArray(value)) {
    if (arrayPath.includes('/photos/markers') || value.length > 60) {
      return
    }
    value.forEach((item, index) => collectEntries(item, entries, `${arrayPath}[${index}]`))
    return
  }

  if (!value || typeof value !== 'object') {
    return
  }

  for (const [key, child] of Object.entries(value)) {
    if (TEXT_KEYS.has(key) && shouldTranslateString(child)) {
      entries.push({ key, container: value })
    } else if (child && typeof child === 'object') {
      collectEntries(child, entries, arrayPath)
    }
  }
}

async function translateEntries(entries, targetLang) {
  const unique = [...new Set(entries.map(({ container, key }) => container[key]))]
  const bySource = new Map()

  const chunkSize = 8
  for (let i = 0; i < unique.length; i += chunkSize) {
    const chunk = unique.slice(i, i + chunkSize)
    await Promise.all(
      chunk.map(async (source) => {
        const translated =
          source.includes('<') && source.includes('>')
            ? await translateHtmlField(source, targetLang)
            : await translatePlain(source, targetLang)
        bySource.set(source, translated)
      }),
    )
  }

  for (const { container, key } of entries) {
    const source = container[key]
    if (bySource.has(source)) {
      container[key] = bySource.get(source)
    }
  }
}

export async function translateApiPayload(payload, targetLang, requestPath = '') {
  if (!payload || targetLang === 'hy' || !isBrowserTranslateSupported()) {
    return payload
  }

  if (requestPath.includes('/photos/markers')) {
    return payload
  }

  const clone = structuredClone(payload)
  const entries = []
  collectEntries(clone, entries)

  if (entries.length === 0) {
    return payload
  }

  await translateEntries(entries, targetLang)
  return clone
}

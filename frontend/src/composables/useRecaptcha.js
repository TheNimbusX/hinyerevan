import { computed, nextTick, ref } from 'vue'
import { getUiLanguage } from '../utils/browserTranslate'

let recaptchaScriptPromise = null

function recaptchaLang() {
  const lang = getUiLanguage()
  return lang === 'ru' ? 'ru' : lang === 'en' ? 'en' : 'hy'
}

function loadRecaptchaScript() {
  if (recaptchaScriptPromise) return recaptchaScriptPromise

  recaptchaScriptPromise = new Promise((resolve, reject) => {
    if (window.grecaptcha?.render) {
      resolve()
      return
    }
    const script = document.createElement('script')
    const hl = recaptchaLang()
    script.src = `https://www.recaptcha.net/recaptcha/api.js?render=explicit&hl=${hl}`
    script.async = true
    script.defer = true
    script.onload = resolve
    script.onerror = reject
    document.head.appendChild(script)
  })

  return recaptchaScriptPromise
}

function whenGrecaptchaReady(timeout = 6000) {
  return new Promise((resolve) => {
    const start = Date.now()
    const tick = () => {
      if (window.grecaptcha?.render) {
        resolve(true)
      } else if (Date.now() - start > timeout) {
        resolve(false)
      } else {
        setTimeout(tick, 100)
      }
    }
    tick()
  })
}

export function useRecaptcha() {
  const siteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY || ''
  const enabled = computed(() => siteKey !== '')
  const el = ref(null)
  const loadFailed = ref(false)
  const loading = ref(false)
  const token = ref('')
  let widgetId = null

  async function render() {
    if (!enabled.value) return

    loadFailed.value = false
    loading.value = true
    await nextTick()

    if (!el.value) {
      await nextTick()
    }

    try {
      await loadRecaptchaScript()
    } catch {
      loadFailed.value = true
      loading.value = false
      return
    }

    const ready = await whenGrecaptchaReady()
    await nextTick()
    const grecaptcha = window.grecaptcha
    if (!ready || !grecaptcha?.render || !el.value) {
      loadFailed.value = true
      loading.value = false
      return
    }

    if (widgetId !== null) {
      grecaptcha.reset(widgetId)
      token.value = ''
      loading.value = false
      return
    }

    try {
      widgetId = grecaptcha.render(el.value, {
        sitekey: siteKey,
        callback: (value) => {
          token.value = value
          loadFailed.value = false
          loading.value = false
        },
        'expired-callback': () => {
          token.value = ''
        },
        'error-callback': () => {
          loadFailed.value = true
          loading.value = false
        },
      })
      loading.value = false
    } catch {
      loadFailed.value = true
      loading.value = false
    }
  }

  function reset() {
    token.value = ''
    if (widgetId !== null && window.grecaptcha?.reset) {
      window.grecaptcha.reset(widgetId)
    }
  }

  return {
    enabled,
    el,
    loadFailed,
    loading,
    token,
    render,
    reset,
  }
}

import { onBeforeUnmount, onMounted, watch } from 'vue'
import { currentLanguage } from '../i18n'

/** Refetch localized API data when UI language changes (without remounting the page). */
export function useLanguageReload(reload, { immediate = false } = {}) {
  watch(currentLanguage, () => {
    reload({ soft: true })
  })

  if (immediate) {
    onMounted(() => reload())
  }
}

/** Apply translated payloads when a background localization fetch completes. */
export function useLocalizedReady(handler) {
  function onReady(event) {
    handler(event.detail)
  }

  onMounted(() => {
    window.addEventListener('hinyerevan:localized-ready', onReady)
  })

  onBeforeUnmount(() => {
    window.removeEventListener('hinyerevan:localized-ready', onReady)
  })
}

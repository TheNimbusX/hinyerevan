/** UI language from localStorage (shared with i18n.js). */
const UI_LANG_KEY = 'hinyerevan_language'

export function getUiLanguage() {
  return localStorage.getItem(UI_LANG_KEY) || 'hy'
}

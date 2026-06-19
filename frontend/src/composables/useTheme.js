import { onBeforeUnmount, onMounted, ref } from 'vue'

const STORAGE_KEY = 'hinyerevan_theme'

function systemTheme() {
  if (typeof window === 'undefined') return 'light'
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function hasStoredTheme() {
  const stored = localStorage.getItem(STORAGE_KEY)
  return stored === 'dark' || stored === 'light'
}

function preferredTheme() {
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored === 'dark' || stored === 'light') {
    return stored
  }
  return systemTheme()
}

function apply(theme) {
  document.documentElement.setAttribute('data-theme', theme)
}

const theme = ref(preferredTheme())
apply(theme.value)

export function useTheme() {
  function setTheme(next) {
    theme.value = next
    localStorage.setItem(STORAGE_KEY, next)
    apply(next)
  }

  function toggleTheme() {
    setTheme(theme.value === 'dark' ? 'light' : 'dark')
  }

  function onStorage(event) {
    if (event.key !== STORAGE_KEY) return
    if (event.newValue === 'dark' || event.newValue === 'light') {
      theme.value = event.newValue
      apply(theme.value)
    }
  }

  function onSystemThemeChange() {
    if (hasStoredTheme()) return
    theme.value = systemTheme()
    apply(theme.value)
  }

  onMounted(() => {
    window.addEventListener('storage', onStorage)
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', onSystemThemeChange)
  })
  onBeforeUnmount(() => {
    window.removeEventListener('storage', onStorage)
    window.matchMedia('(prefers-color-scheme: dark)').removeEventListener('change', onSystemThemeChange)
  })

  return { theme, setTheme, toggleTheme }
}

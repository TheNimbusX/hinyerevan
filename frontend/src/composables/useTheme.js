import { onBeforeUnmount, onMounted, ref } from 'vue'

const STORAGE_KEY = 'hinyerevan_theme'

function preferredTheme() {
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored === 'dark' || stored === 'light') {
    return stored
  }
  return 'dark'
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

  // Keep multiple instances in sync via storage events
  function onStorage(event) {
    if (event.key !== STORAGE_KEY) return
    if (event.newValue === 'dark' || event.newValue === 'light') {
      theme.value = event.newValue
      apply(theme.value)
    }
  }

  onMounted(() => window.addEventListener('storage', onStorage))
  onBeforeUnmount(() => window.removeEventListener('storage', onStorage))

  return { theme, setTheme, toggleTheme }
}

import { ref, watch } from 'vue'

const STORAGE_KEY = 'hinyerevan:mini-map-type'
export const MINI_MAP_TYPES = ['scheme', 'satellite']

function readStored() {
  try {
    const value = localStorage.getItem(STORAGE_KEY)
    return MINI_MAP_TYPES.includes(value) ? value : 'scheme'
  } catch {
    return 'scheme'
  }
}

// Shared across all small maps.
const miniMapType = ref(readStored())

watch(miniMapType, (value) => {
  try {
    localStorage.setItem(STORAGE_KEY, value)
  } catch {
    // storage unavailable
  }
})

export function useMiniMapType() {
  return miniMapType
}

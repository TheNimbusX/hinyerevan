import { ref } from 'vue'

const _signal = ref(0)

export function useMapReset() {
  return {
    resetSignal: _signal,
    triggerReset: () => { _signal.value++ },
  }
}

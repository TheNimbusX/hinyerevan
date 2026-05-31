import { useRouter } from 'vue-router'
import { getToken } from '../api'

export function useAuthGate() {
  const router = useRouter()

  function requireAuth(redirectPath = '/profile', mode = 'login') {
    if (getToken()) {
      router.push(redirectPath)
      return true
    }

    window.dispatchEvent(
      new CustomEvent('hinyerevan:open-auth', {
        detail: { mode, redirect: redirectPath },
      }),
    )
    return false
  }

  return { requireAuth }
}

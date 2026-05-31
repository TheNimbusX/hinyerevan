/** Matches `User::TYPE_ADMIN` on the API. */
export const USER_TYPE_ADMIN = 5

export function isAdminUser(user) {
  return Number(user?.type) === USER_TYPE_ADMIN
}

function looksLikeProviderId(value) {
  return /^\d{8,}$/.test(String(value ?? '').trim())
}

/** Human-readable name; never show long numeric Facebook IDs. */
export function userDisplayName(user, t) {
  const fallback = typeof t === 'function' ? t('member') : 'Member'
  if (!user) return fallback

  const display = user.display_name || user.name
  if (display && !looksLikeProviderId(display)) return display

  const full = [user.first_name, user.last_name].filter(Boolean).join(' ').trim()
  if (full) return full

  if (user.identity && !looksLikeProviderId(user.identity)) return user.identity

  if (user.uid && !looksLikeProviderId(user.uid)) return user.uid

  if (user.email?.includes('@')) {
    const local = user.email.split('@')[0]
    if (local && !looksLikeProviderId(local)) return local
  }

  return fallback
}

export function userProfilePath(user) {
  if (!user) return '/'
  return `/users/${user.unique || user.uid || ''}`
}

export function parseBirthdate(bdate) {
  if (!bdate || bdate === '1970-01-01') {
    return { birth_day: '', birth_month: '', birth_year: '' }
  }

  const [year, month, day] = String(bdate).split('-').map((part) => Number(part))
  if (!year || !month || !day) {
    return { birth_day: '', birth_month: '', birth_year: '' }
  }

  return { birth_day: day, birth_month: month, birth_year: year }
}

export function sexLabel(sex, t) {
  return Number(sex) === 1 ? t('male') : t('female')
}

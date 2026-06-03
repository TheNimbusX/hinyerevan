import { safeAvatarUrl } from '../api'

export function commentInitials(name) {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
  if (!parts.length) return '?'
  return parts
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase()
}

export function commentAvatarUrl(item) {
  if (!item) return ''
  if (item.source === 'facebook') {
    const picture = item.author?.picture
    return typeof picture === 'string' && picture ? picture : ''
  }
  return safeAvatarUrl(item.author?.photo)
}

export function commentDisplayName(item, t) {
  if (!item?.author) return typeof t === 'function' ? t('member') : 'Member'
  const name = item.author.display_name || item.author.name
  return name || (typeof t === 'function' ? t('member') : 'Member')
}

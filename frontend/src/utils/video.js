export function youtubeId(url) {
  if (!url) return ''
  const value = String(url).trim()
  const patterns = [
    /youtube\.com\/watch\?v=([\w-]{6,})/i,
    /youtu\.be\/([\w-]{6,})/i,
    /youtube\.com\/embed\/([\w-]{6,})/i,
    /youtube\.com\/shorts\/([\w-]{6,})/i,
  ]
  for (const pattern of patterns) {
    const match = value.match(pattern)
    if (match) return match[1]
  }
  return ''
}

export function youtubeEmbedUrl(url, { autoplay = false } = {}) {
  const id = youtubeId(url)
  if (!id) return ''
  const params = autoplay ? '?rel=0&autoplay=1' : '?rel=0'
  return `https://www.youtube.com/embed/${id}${params}`
}

export function youtubeWatchUrl(url) {
  const id = youtubeId(url)
  return id ? `https://www.youtube.com/watch?v=${id}` : ''
}

export function youtubeThumb(url) {
  const id = youtubeId(url)
  return id ? `https://img.youtube.com/vi/${id}/hqdefault.jpg` : ''
}

export function isYoutubeUrl(url) {
  return Boolean(youtubeId(url))
}

export function playVideo(url, title = '') {
  if (!url || typeof window === 'undefined') return
  window.dispatchEvent(new CustomEvent('hinyerevan:play-video', { detail: { url, title } }))
}

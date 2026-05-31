/** Extract the YouTube video id from any common YouTube URL form. */
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

/** Embed URL for a YouTube video link. */
export function youtubeEmbedUrl(url, { autoplay = false } = {}) {
  const id = youtubeId(url)
  if (!id) return ''
  const params = autoplay ? '?rel=0&autoplay=1' : '?rel=0'
  return `https://www.youtube.com/embed/${id}${params}`
}

/** Canonical watch URL (used as a fallback link to open on YouTube). */
export function youtubeWatchUrl(url) {
  const id = youtubeId(url)
  return id ? `https://www.youtube.com/watch?v=${id}` : ''
}

/** Preview thumbnail URL for a YouTube video link. */
export function youtubeThumb(url) {
  const id = youtubeId(url)
  return id ? `https://img.youtube.com/vi/${id}/hqdefault.jpg` : ''
}

/** Validate that a string looks like a usable YouTube link. */
export function isYoutubeUrl(url) {
  return Boolean(youtubeId(url))
}

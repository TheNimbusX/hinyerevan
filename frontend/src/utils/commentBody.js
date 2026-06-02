/**
 * Display legacy comment text (HTML entities / <br> tags) as plain text with breaks.
 */
export function formatCommentBody(body) {
  if (!body) return ''

  let text = body
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&amp;/gi, '&')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;/gi, "'")

  text = text.replace(/<\s*br\s*\/?\s*>/gi, '\n')
  text = text.replace(/<\/\s*p\s*>/gi, '\n')
  text = text.replace(/<[^>]+>/g, '')
  text = text.replace(/\r\n?|\n{3,}/g, '\n\n')

  return text.trim()
}

export function normalizeCreatedComment(raw) {
  return {
    ...raw,
    source: raw.source || 'site',
    replies: Array.isArray(raw.replies) ? raw.replies : [],
  }
}

function matchesReplyTarget(item, replyTo) {
  if (!replyTo) return false
  if (replyTo.source === 'facebook' && replyTo.facebook_comment_id) {
    return item.facebook_comment_id === replyTo.facebook_comment_id
  }
  return item.id === replyTo.id
}

function sortByDate(list) {
  return [...list].sort((a, b) => String(a.datetime || '').localeCompare(String(b.datetime || '')))
}

/**
 * Insert `node` under the matching parent. Returns `{ threads, inserted }`.
 */
function insertReply(threads, replyTo, node) {
  let inserted = false

  const next = threads.map((item) => {
    if (!inserted && matchesReplyTarget(item, replyTo)) {
      inserted = true
      return { ...item, replies: sortByDate([...(item.replies || []), node]) }
    }

    if (!inserted && item.replies?.length) {
      const result = insertReply(item.replies, replyTo, node)
      if (result.inserted) {
        inserted = true
        return { ...item, replies: result.threads }
      }
    }

    return item
  })

  return { threads: next, inserted }
}

/**
 * Append a freshly-created comment into an existing thread tree (immutably).
 * - No reply target → appended at root (sorted by date).
 * - Reply target → nested under the matching comment; falls back to root if not found.
 *
 * @param {Array<object>|undefined} threads
 * @param {object} comment
 * @param {object|null} replyTo
 */
export function appendCommentToThreads(threads, comment, replyTo = null) {
  const node = normalizeCreatedComment(comment)
  const list = Array.isArray(threads) ? threads : []

  if (!replyTo) {
    return sortByDate([...list, node])
  }

  const { threads: updated, inserted } = insertReply(list, replyTo, node)
  return inserted ? updated : sortByDate([...list, node])
}

export function countComments(threads) {
  if (!Array.isArray(threads)) return 0
  return threads.reduce((sum, item) => sum + 1 + countComments(item.replies || []), 0)
}

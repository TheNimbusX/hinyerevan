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

function insertReply(threads, replyTo, node) {
  let inserted = false

  const next = threads.map((item) => {
    if (matchesReplyTarget(item, replyTo)) {
      inserted = true
      return {
        ...item,
        replies: [...(item.replies || []), node],
      }
    }

    if (item.replies?.length) {
      const replies = insertReply(item.replies, replyTo, node)
      if (replies !== item.replies) {
        inserted = true
        return { ...item, replies }
      }
    }

    return item
  })

  return inserted ? next : threads
}

/**
 * @param {Array<object>|undefined} threads
 * @param {object} comment
 * @param {CommentTarget|null} replyTo
 */
export function appendCommentToThreads(threads, comment, replyTo = null) {
  const node = normalizeCreatedComment(comment)
  const list = Array.isArray(threads) ? [...threads] : []

  if (!replyTo) {
    return [...list, node].sort((a, b) => String(a.datetime || '').localeCompare(String(b.datetime || '')))
  }

  const updated = insertReply(list, replyTo, node)
  return updated.length === list.length ? [...list, node] : updated
}

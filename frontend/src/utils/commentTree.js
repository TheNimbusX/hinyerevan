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

export function removeCommentById(threads, id) {
  const list = Array.isArray(threads) ? threads : []
  let removed = 0

  const next = list.reduce((acc, item) => {
    if (item.id === id) {
      removed += 1 + countComments(item.replies || [])
      return acc
    }
    if (item.replies?.length) {
      const result = removeCommentById(item.replies, id)
      if (result.removed) {
        removed += result.removed
        acc.push({ ...item, replies: result.threads })
        return acc
      }
    }
    acc.push(item)
    return acc
  }, [])

  return { threads: next, removed }
}

export function buildCommentPostBody(text, replyTo, { postToFacebook = false } = {}) {
  const body = { body: String(text || '').trim() }
  if (postToFacebook) {
    body.post_to_facebook = true
  }
  if (!replyTo) return body

  if (replyTo.source === 'facebook' && replyTo.facebook_comment_id) {
    body.reply_to_facebook_comment_id = replyTo.facebook_comment_id
    return body
  }

  if (replyTo.source === 'site' && replyTo.id) {
    body.to = replyTo.id
  }

  return body
}

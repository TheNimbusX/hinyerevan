/** Total likes shown in UI: site favorites + synced Facebook likes. */
export function photoDisplayLikes(photo) {
  if (!photo) return 0
  if (photo.likes_total != null) return photo.likes_total
  const site = photo.site_likes_count ?? photo.likes_count ?? 0
  const fb = photo.facebook?.likes ?? 0
  return site + fb
}

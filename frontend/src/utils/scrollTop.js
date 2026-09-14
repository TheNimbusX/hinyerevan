const THRESHOLD = 200

// Scrolls the photo sheet or page up; true if it did.
export function scrollUpIfScrolled() {
  const sheet = document.querySelector('.photo-sheet-scroll')
  if (sheet && sheet.scrollTop > THRESHOLD) {
    sheet.scrollTo({ top: 0, behavior: 'smooth' })
    return true
  }
  if (window.scrollY > THRESHOLD) {
    window.scrollTo({ top: 0, behavior: 'smooth' })
    return true
  }
  return false
}

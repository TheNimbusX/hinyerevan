const DIRECTION_ANGLES = {
  0: 0,
  1: 0,
  2: 45,
  3: 90,
  4: 135,
  5: 180,
  6: 225,
  7: 270,
  8: 315,
}

const ARROW_PATH =
  'M24 6 L31.2 23.2 C31.7 24.2 31 25.4 29.9 25.4 H26.4 V34.8 C26.4 36.1 25.3 37.2 24 37.2 C22.7 37.2 21.6 36.1 21.6 34.8 V25.4 H18.1 C17 25.4 16.3 24.2 16.8 23.2 Z'

function topshotSvg() {
  return `
    <circle cx="24" cy="24" r="12" fill="#fff" />
    <circle cx="24" cy="24" r="12" fill="none" stroke="#294fb3" stroke-width="2.5" />
    <circle cx="24" cy="24" r="5.5" fill="#ff910f" />
  `
}

function arrowSvg(angle) {
  return `
    <g transform="rotate(${angle} 24 24)">
      <path fill="#ff910f" stroke="#ffffff" stroke-width="1.75" stroke-linejoin="round" d="${ARROW_PATH}" />
    </g>
  `
}

/**
 * Inline SVG for Leaflet divIcon (directional photo pin).
 */
export function directionMarkerSvg(direction, size = 28) {
  const angle = DIRECTION_ANGLES[direction] ?? 0
  const body = direction === 0 ? topshotSvg() : arrowSvg(angle)

  return `<svg class="map-pin-svg" viewBox="0 0 48 48" width="${size}" height="${size}" aria-hidden="true">${body}</svg>`
}

/**
 * Cluster badge HTML — plain circle + text (reliable in Leaflet divIcon).
 */
export function clusterMarkerHtml(count) {
  const label = count > 99 ? '99+' : String(count)
  const size = label.length > 2 ? 30 : label.length > 1 ? 28 : 26
  const fontSize = label.length > 2 ? 9 : label.length > 1 ? 10 : 11

  return `<div class="map-cluster-pin" style="width:${size}px;height:${size}px;font-size:${fontSize}px" aria-label="${label}"><span>${label}</span></div>`
}

export { DIRECTION_ANGLES }

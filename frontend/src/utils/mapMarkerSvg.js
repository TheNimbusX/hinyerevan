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

function topshotSvg(centerFill = '#ae2b21') {
  return `
    <circle cx="24" cy="24" r="12" fill="#fff" />
    <circle cx="24" cy="24" r="12" fill="none" stroke="#294fb3" stroke-width="2.5" />
    <circle cx="24" cy="24" r="5.5" fill="${centerFill}" />
  `
}

function arrowSvg(angle, fill = '#ae2b21') {
  return `
    <g transform="rotate(${angle} 24 24)">
      <path fill="${fill}" stroke="#ffffff" stroke-width="1.75" stroke-linejoin="round" d="${ARROW_PATH}" />
    </g>
  `
}

/**
 * Inline SVG for Leaflet divIcon (directional photo pin).
 */
export function directionMarkerSvg(direction, size = 28, options = {}) {
  const { fill = '#ae2b21', centerFill = fill } = options
  const angle = DIRECTION_ANGLES[direction] ?? 0
  const body = direction === 0 ? topshotSvg(centerFill) : arrowSvg(angle, fill)

  return `<svg class="map-pin-svg" viewBox="0 0 48 48" width="${size}" height="${size}" aria-hidden="true">${body}</svg>`
}

/** Size the cluster circle from digit count (full number, no 99+ cap). */
export function clusterMarkerMetrics(count) {
  const label = String(Math.max(0, Number(count) || 0))
  const len = label.length

  if (len <= 1) return { label, size: 26, fontSize: 11 }
  if (len === 2) return { label, size: 28, fontSize: 10 }
  if (len === 3) return { label, size: 34, fontSize: 9 }
  if (len === 4) return { label, size: 40, fontSize: 8 }

  return { label, size: 46, fontSize: 7 }
}

/** Inline cluster colors — Telegram WebView may paint before the CSS bundle loads. */
const CLUSTER_STYLE =
  'display:flex;align-items:center;justify-content:center;border-radius:50%;color:#fff;font-weight:700;line-height:1;border:2px solid #fff;background:radial-gradient(circle at 32% 28%,rgba(255,255,255,0.32) 0 16%,transparent 17%),linear-gradient(145deg,#d6493b,#8a1c14);box-shadow:0 3px 8px rgba(138,28,20,0.3)'

/**
 * Cluster badge HTML — plain circle + text (reliable in Leaflet divIcon).
 */
export function clusterMarkerHtml(count) {
  const { label, size, fontSize } = clusterMarkerMetrics(count)

  return `<div class="map-cluster-pin" style="width:${size}px;height:${size}px;font-size:${fontSize}px;${CLUSTER_STYLE}" aria-label="${label}"><span style="text-shadow:0 1px 1px rgba(0,0,0,0.28)">${label}</span></div>`
}

export function videoMarkerSvg(size = 28, options = {}) {
  const { fill = '#ae2b21' } = options
  return `<svg xmlns="http://www.w3.org/2000/svg" class="map-pin-svg" viewBox="0 0 48 48" width="${size}" height="${size}" aria-hidden="true"><circle cx="24" cy="24" r="20" fill="${fill}" stroke="#fff" stroke-width="2.5"/><path fill="#fff" d="M20 15l15 9-15 9z"/></svg>`
}

export { DIRECTION_ANGLES }

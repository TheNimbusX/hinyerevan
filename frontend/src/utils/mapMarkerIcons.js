import L from 'leaflet'
import { clusterMarkerHtml, clusterMarkerMetrics, directionMarkerSvg, videoMarkerSvg } from './mapMarkerSvg'

const DIRECTIONS = [0, 1, 2, 3, 4, 5, 6, 7, 8]
const PIN_SIZE = 28
const PIN_ANCHOR = PIN_SIZE / 2

/** @type {Record<number, L.DivIcon>} */
let directionIcons = {}

/** @type {Map<number, L.DivIcon>} */
const clusterIcons = new Map()

/** @type {Record<number, L.DivIcon>} */
let activeDirectionIcons = {}

/** @type {L.Icon|null} */
let videoIcon = null

/** @type {L.DivIcon|null} */
let activeVideoIcon = null

const ACTIVE_PIN_FILL = '#ff910f'
const ACTIVE_RING_SIZE = PIN_SIZE + 18

function svgDataUri(direction, options = {}) {
  const svg = directionMarkerSvg(direction, PIN_SIZE, options).replace(
    '<svg ',
    '<svg xmlns="http://www.w3.org/2000/svg" ',
  )
  return `data:image/svg+xml,${encodeURIComponent(svg)}`
}

export function initMapMarkerIcons() {
  directionIcons = {}
  activeDirectionIcons = {}
  videoIcon = null
  activeVideoIcon = null
  clusterIcons.clear()
  for (const direction of DIRECTIONS) {
    directionIcons[direction] = L.icon({
      className: 'camera-direction-icon',
      iconUrl: svgDataUri(direction),
      iconSize: [PIN_SIZE, PIN_SIZE],
      iconAnchor: [PIN_ANCHOR, PIN_ANCHOR],
    })
  }
}

export function getDirectionIcon(direction) {
  if (!directionIcons[1]) initMapMarkerIcons()
  const key = Number(direction)
  if (Number.isFinite(key) && directionIcons[key]) return directionIcons[key]
  return directionIcons[1]
}

export function getActiveDirectionIcon(direction) {
  if (!activeDirectionIcons[1]) {
    for (const dir of DIRECTIONS) {
      const imgUri = svgDataUri(dir, { fill: ACTIVE_PIN_FILL, centerFill: ACTIVE_PIN_FILL })
      activeDirectionIcons[dir] = L.divIcon({
        className: 'camera-direction-icon camera-direction-icon--active',
        html: `<span class="marker-active-ring"></span><img src="${imgUri}" width="${PIN_SIZE}" height="${PIN_SIZE}" alt="" style="position:relative;z-index:1">`,
        iconSize: [ACTIVE_RING_SIZE, ACTIVE_RING_SIZE],
        iconAnchor: [ACTIVE_RING_SIZE / 2, ACTIVE_RING_SIZE / 2],
      })
    }
  }
  const key = Number(direction)
  if (Number.isFinite(key) && activeDirectionIcons[key]) return activeDirectionIcons[key]
  return activeDirectionIcons[1]
}

export function getVideoIcon() {
  if (!videoIcon) {
    const uri = `data:image/svg+xml,${encodeURIComponent(videoMarkerSvg(PIN_SIZE))}`
    videoIcon = L.icon({
      className: 'camera-direction-icon camera-direction-icon--video',
      iconUrl: uri,
      iconSize: [PIN_SIZE, PIN_SIZE],
      iconAnchor: [PIN_ANCHOR, PIN_ANCHOR],
    })
  }
  return videoIcon
}

export function getActiveVideoIcon() {
  if (!activeVideoIcon) {
    const uri = `data:image/svg+xml,${encodeURIComponent(videoMarkerSvg(PIN_SIZE, { fill: ACTIVE_PIN_FILL }))}`
    activeVideoIcon = L.divIcon({
      className: 'camera-direction-icon camera-direction-icon--active camera-direction-icon--video',
      html: `<span class="marker-active-ring"></span><img src="${uri}" width="${PIN_SIZE}" height="${PIN_SIZE}" alt="" style="position:relative;z-index:1">`,
      iconSize: [ACTIVE_RING_SIZE, ACTIVE_RING_SIZE],
      iconAnchor: [ACTIVE_RING_SIZE / 2, ACTIVE_RING_SIZE / 2],
    })
  }
  return activeVideoIcon
}

export function getClusterIcon(count) {
  const safeCount = Math.max(0, Number(count) || 0)
  if (!clusterIcons.has(safeCount)) {
    const { size } = clusterMarkerMetrics(safeCount)
    clusterIcons.set(
      safeCount,
      L.divIcon({
        className: 'photo-cluster-icon',
        html: clusterMarkerHtml(safeCount),
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
      }),
    )
  }
  return clusterIcons.get(safeCount)
}

export function createClusterIconFactory() {
  return (cluster) => getClusterIcon(cluster.getChildCount())
}

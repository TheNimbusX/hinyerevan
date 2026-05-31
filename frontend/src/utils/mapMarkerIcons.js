import L from 'leaflet'
import { clusterMarkerHtml, directionMarkerSvg } from './mapMarkerSvg'

const DIRECTIONS = [0, 1, 2, 3, 4, 5, 6, 7, 8]
const PIN_SIZE = 28
const PIN_ANCHOR = PIN_SIZE / 2

/** @type {Record<number, L.DivIcon>} */
let directionIcons = {}

/** @type {Map<number, L.DivIcon>} */
const clusterIcons = new Map()

function svgDataUri(direction) {
  // Image-based icons (vs inline-SVG divIcons) let the browser decode each of
  // the 9 direction pins once and reuse them — dramatically cheaper than
  // parsing thousands of inline SVG nodes when the cluster expands on zoom.
  // A standalone SVG used as an <img> source must carry the xmlns attribute.
  const svg = directionMarkerSvg(direction, PIN_SIZE).replace(
    '<svg ',
    '<svg xmlns="http://www.w3.org/2000/svg" ',
  )
  return `data:image/svg+xml,${encodeURIComponent(svg)}`
}

export function initMapMarkerIcons() {
  directionIcons = {}
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

export function getClusterIcon(count) {
  const key = count > 99 ? 99 : count
  if (!clusterIcons.has(key)) {
    const size = count > 99 ? 30 : count > 9 ? 28 : 26
    clusterIcons.set(
      key,
      L.divIcon({
        className: 'photo-cluster-icon',
        html: clusterMarkerHtml(count),
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
      }),
    )
  }
  return clusterIcons.get(key)
}

export function createClusterIconFactory() {
  return (cluster) => getClusterIcon(cluster.getChildCount())
}

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import { cachedApi, imageUrl } from '../api'
import { useI18n } from '../i18n'
import { useTheme } from '../composables/useTheme'
import {
  applyMapTileLayer,
  getMapTileLayer,
  MAP_CLUSTER_MAX_ZOOM,
  MAP_MAX_ZOOM,
  MAP_MIN_ZOOM,
} from '../utils/mapTiles'
import { createClusterIconFactory, getDirectionIcon, initMapMarkerIcons } from '../utils/mapMarkerIcons'
import { setupLeaflet } from '../utils/leafletSetup'
import { directionLabel, formatDateTime } from '../utils/locale'

const props = defineProps({
  photoId: { type: [Number, String], required: true },
  lat: { type: [Number, String], required: true },
  lng: { type: [Number, String], required: true },
  direction: { type: Number, default: 1 },
})

const router = useRouter()
const { t, currentLanguage } = useI18n()
const { theme } = useTheme()
const mapElement = ref(null)
const markers = ref([])
const mapLoading = ref(true)

let map
let tileLayer
let markerLayer
let loadingFallbackTimer

function clusterRadiusForZoom(zoom) {
  if (zoom >= 19) return 26
  if (zoom >= 17) return 42
  if (zoom >= 15) return 52
  if (zoom >= 13) return 60
  return 72
}

function escapeHtml(value = '') {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

function markerPreview(marker) {
  const dir = directionLabel(marker.direction, t)
  const added = formatDateTime(marker.datetime, currentLanguage.value)
  const videoBadge = marker.has_video
    ? '<span class="marker-preview-video" aria-hidden="true">▶</span>'
    : ''
  const dateLine = added
    ? `<time class="marker-preview-date">${escapeHtml(added)}</time>`
    : ''
  return `
    <a class="marker-preview-card" href="/photos/${marker.id}">
      <span class="marker-preview-media">
        <span class="marker-preview-skeleton" aria-hidden="true"></span>
        <img src="${imageUrl(marker.thumb_url)}" alt="" loading="lazy" decoding="async"
          onload="this.classList.add('is-loaded')" onerror="this.classList.add('is-loaded')">
      </span>
      <span class="marker-preview-year">${marker.year}</span>
      ${videoBadge}
      <strong>${escapeHtml(marker.title)}</strong>
      <small>${escapeHtml(dir)}</small>
      ${dateLine}
    </a>
  `
}

function onMapPreviewClick(event) {
  const card = event.target.closest?.('.marker-preview-card')
  if (!card) return
  if (event.defaultPrevented || event.button !== 0) return
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return

  const href = card.getAttribute('href')
  if (!href) return

  event.preventDefault()
  const id = href.split('/').pop()
  if (Number(id) !== Number(props.photoId)) {
    router.push(`/photos/${id}`)
  }
}

function createLeafletMarker(data) {
  const isActive = Number(data.id) === Number(props.photoId)
  const layer = L.marker([data.lat, data.lng], {
    icon: getDirectionIcon(data.direction),
    zIndexOffset: isActive ? 500 : 0,
  })

  layer.on('click', () => {
    if (Number(data.id) !== Number(props.photoId)) {
      router.push(`/photos/${data.id}`)
    }
  })

  layer.on('mouseover', function bindTooltipOnce() {
    if (!this.getTooltip()) {
      this.bindTooltip(markerPreview(data), {
        className: 'marker-preview-tooltip',
        direction: 'top',
        interactive: true,
        offset: [0, -14],
        opacity: 1,
      })
    }
    this.openTooltip()
  })

  return layer
}

function rebuildMarkers() {
  if (!markerLayer) return

  initMapMarkerIcons()
  markerLayer.clearLayers()

  for (const data of markers.value) {
    markerLayer.addLayer(createLeafletMarker(data))
  }
}

function photoPosition() {
  const la = Number(props.lat)
  const ln = Number(props.lng)
  if (!Number.isFinite(la) || !Number.isFinite(ln)) return null
  return [la, ln]
}

function finishMapLoading() {
  window.clearTimeout(loadingFallbackTimer)
  mapLoading.value = false
}

function initMap() {
  const position = photoPosition()
  if (!position || map || !mapElement.value) {
    if (!position) finishMapLoading()
    return
  }

  setupLeaflet()
  initMapMarkerIcons()

  const layer = getMapTileLayer('google', theme.value, currentLanguage.value)

  map = L.map(mapElement.value, {
    center: position,
    zoom: 15,
    minZoom: MAP_MIN_ZOOM,
    maxZoom: MAP_MAX_ZOOM,
    zoomControl: true,
    scrollWheelZoom: true,
    dragging: true,
    doubleClickZoom: true,
    attributionControl: false,
    crs: layer.crs,
  })

  markerLayer = L.markerClusterGroup({
    showCoverageOnHover: false,
    spiderfyOnMaxZoom: true,
    zoomToBoundsOnClick: true,
    animate: false,
    animateAddingMarkers: false,
    chunkedLoading: true,
    chunkInterval: 200,
    chunkDelay: 40,
    removeOutsideVisibleBounds: true,
    maxClusterRadius: clusterRadiusForZoom,
    disableClusteringAtZoom: MAP_CLUSTER_MAX_ZOOM,
    iconCreateFunction: createClusterIconFactory(),
  }).addTo(map)

  tileLayer = L.tileLayer(layer.url, layer.options).addTo(map)
  mapElement.value.addEventListener('click', onMapPreviewClick)

  if (markers.value.length) rebuildMarkers()

  tileLayer.once('load', finishMapLoading)
  loadingFallbackTimer = window.setTimeout(finishMapLoading, 1400)

  map.whenReady(() => {
    map.invalidateSize()
    setTimeout(() => map?.invalidateSize(), 150)
  })
}

function focusPhoto() {
  const position = photoPosition()
  if (!position || !map) return

  map.setView(position, Math.max(map.getZoom(), 15), { animate: false })
  rebuildMarkers()
}

function destroyMap() {
  window.clearTimeout(loadingFallbackTimer)
  mapElement.value?.removeEventListener('click', onMapPreviewClick)
  map?.remove()
  map = null
  tileLayer = null
  markerLayer = null
}

async function loadMarkers() {
  try {
    const data = await cachedApi('/photos/markers')
    markers.value = Array.isArray(data) ? data : []
    if (map) rebuildMarkers()
  } catch {
    markers.value = []
  }
}

onMounted(async () => {
  mapLoading.value = true
  if (!photoPosition()) {
    finishMapLoading()
    return
  }

  await loadMarkers()
  await nextTick()
  initMap()
  if (!map) finishMapLoading()
})

onBeforeUnmount(() => {
  destroyMap()
})

watch(() => [props.photoId, props.lat, props.lng, props.direction], () => {
  focusPhoto()
})

watch([theme, currentLanguage], () => {
  if (!map) return
  tileLayer = applyMapTileLayer(map, tileLayer, 'google', theme.value, currentLanguage.value)
  requestAnimationFrame(() => map?.invalidateSize())
})
</script>

<template>
  <div class="photo-mini-map-shell">
    <div
      v-show="mapLoading"
      class="photo-mini-map-skeleton"
      aria-hidden="true"
    />
    <div
      ref="mapElement"
      class="photo-mini-map"
      :class="{ 'is-loading': mapLoading }"
    />
  </div>
</template>

<style lang="scss">
.photo-mini-map-shell {
  position: relative;
  width: 100%;
  min-height: 260px;
}

.photo-mini-map-skeleton {
  position: absolute;
  inset: 0;
  z-index: 2;
  border-radius: $radius-xl - 4;
  pointer-events: none;
  background:
    linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.42), transparent),
    #e8eef5;
  background-size: 220px 100%, 100% 100%;
  background-repeat: no-repeat, no-repeat;
  animation: skeleton-shimmer 1.1s infinite linear;
}

.photo-mini-map {
  width: 100%;
  min-height: 260px;
  border-radius: $radius-xl - 4;
  overflow: hidden;
  background: #e8eef5;
  opacity: 1;
  transition: opacity 0.2s ease;

  &.is-loading {
    opacity: 0;
  }

  .leaflet-container {
    width: 100%;
    height: 100%;
    min-height: 260px;
    font-family: inherit;
  }

  .leaflet-control-zoom {
    border: 0;
    box-shadow: $shadow-sm;
  }
}

[data-theme='dark'] {
  .photo-mini-map-skeleton {
    background:
      linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.06), transparent),
      #20262f;
    background-size: 220px 100%, 100% 100%;
    background-repeat: no-repeat, no-repeat;
  }

  .photo-mini-map {
    background: #20262f;
  }
}
</style>

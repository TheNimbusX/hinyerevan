<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import 'leaflet.markercluster/dist/MarkerCluster.Default.css'
import Slider from '@vueform/slider'
import '@vueform/slider/themes/default.css'
import { api, cachedApi, imageUrl, safeAvatarUrl } from '../api'
import { useAuthGate } from '../composables/useAuthGate'
import { useI18n } from '../i18n'
import { useTheme } from '../composables/useTheme'
import { getMapTileLayer, MAP_MAX_ZOOM, MAP_TYPES, normalizeMapType } from '../utils/mapTiles'
import { createClusterIconFactory, getDirectionIcon, initMapMarkerIcons } from '../utils/mapMarkerIcons'
import { directionLabel, formatDateTime } from '../utils/locale'
import googleLogo from '../assets/logos/google-logo.svg'
import yandexLogo from '../assets/logos/yandex-logo.svg'
import PhotoDetailSheet from '../components/PhotoDetailSheet.vue'

const markers = ref([])
const photos = ref([])
const news = ref([])
const ratings = ref(null)
const loading = ref(true)
const markersLoading = ref(false)
const mapProvider = ref('google')
const mapType = ref('scheme')
const mapPanelOpen = ref(false)
const activePhotoId = ref(null)
const DEFAULT_CENTER = [40.179136, 44.511623]
const DEFAULT_ZOOM = 13
const earliestAllowedYear = 1500
const latestAllowedYear = new Date().getFullYear()
const yearRange = ref([earliestAllowedYear, latestAllowedYear])
const activeYearRange = ref([earliestAllowedYear, latestAllowedYear])
const rangeTouched = ref(false)
const loadError = ref('')
const { t, currentLanguage } = useI18n()
const { theme } = useTheme()
const { requireAuth } = useAuthGate()
const router = useRouter()
const route = useRoute()
const userFilter = computed(() => (route.query.user ? String(route.query.user) : ''))
const reviewFilter = computed(() => route.query.review === '1' || route.query.review === 'true')
const filteredUserName = ref('')
let suppressNextRangeWatch = false
let markerSyncFrame
let markerSyncTimer
let yearApplyFrame

const mapElement = ref(null)
let map
let tileLayer
let overlayLayer
let markerLayer
/** @type {Map<number, { layer: import('leaflet').Marker, year: number, data: object }>} */
let markerRegistry = new Map()
/** @type {Map<number, { layer: import('leaflet').Marker, year: number, data: object }[]>} */
let markersByYear = new Map()
/** @type {Set<import('leaflet').Marker>} */
let shownLayers = new Set()

function validYear(year) {
  const numericYear = Number(year)

  return Number.isInteger(numericYear) && numericYear >= earliestAllowedYear && numericYear <= latestAllowedYear
}

function clampYear(year) {
  const numericYear = Math.round(Number(year) || minYear.value)

  return Math.min(maxYear.value, Math.max(minYear.value, numericYear))
}

function normalizedRange(from, to) {
  const nextFrom = clampYear(from)
  const nextTo = clampYear(to)

  return nextFrom <= nextTo ? [nextFrom, nextTo] : [nextTo, nextFrom]
}

function resolveYearBounds(markerList = markers.value) {
  let min = Infinity
  let max = -Infinity

  const list = Array.isArray(markerList) ? markerList : []
  for (const marker of list) {
    const year = Number(marker.year)
    if (!validYear(year)) continue
    if (year < min) min = year
    if (year > max) max = year
  }

  if (!Number.isFinite(min)) {
    return [earliestAllowedYear, latestAllowedYear]
  }

  return [min, max]
}

const yearBounds = computed(() => resolveYearBounds())

const minYear = computed(() => yearBounds.value[0])
const maxYear = computed(() => yearBounds.value[1])

const availableMapTypes = computed(() => MAP_TYPES[mapProvider.value] || MAP_TYPES.google)

const MAP_TYPE_LABELS = {
  scheme: 'mapTypeScheme',
  satellite: 'mapTypeSatellite',
  hybrid: 'mapTypeHybrid',
  terrain: 'mapTypeTerrain',
}

function mapTypeLabel(type) {
  return t(MAP_TYPE_LABELS[type] || 'mapTypeScheme')
}

function selectProvider(provider) {
  if (mapProvider.value === provider) return
  mapProvider.value = provider
  mapType.value = normalizeMapType(provider, mapType.value)
}

function currentTileLayer() {
  return getMapTileLayer(mapProvider.value, theme.value, currentLanguage.value, mapType.value)
}

function markersEndpoint() {
  const params = new URLSearchParams()
  if (userFilter.value) params.set('user', userFilter.value)
  if (reviewFilter.value) params.set('review', '1')
  const qs = params.toString()
  return qs ? `/photos/markers?${qs}` : '/photos/markers'
}

async function loadFilteredUserLabel() {
  if (!userFilter.value) {
    filteredUserName.value = ''
    return
  }
  try {
    const u = await cachedApi(`/users/${encodeURIComponent(userFilter.value)}`)
    filteredUserName.value = u?.name || u?.uid || userFilter.value
  } catch {
    filteredUserName.value = userFilter.value
  }
}

function clearUserFilter() {
  const query = { ...route.query }
  delete query.user
  router.push({ path: '/', query })
}

function toggleReviewFilter() {
  const query = { ...route.query }
  if (reviewFilter.value) {
    delete query.review
  } else {
    query.review = '1'
  }
  router.push({ path: '/', query })
}

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
    ? `<span class="marker-preview-video" aria-hidden="true">▶</span>`
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

function openPhoto(id) {
  activePhotoId.value = Number(id)
}

function applyMarkerYearBounds(forceReset = false) {
  const [nextMin, nextMax] = resolveYearBounds()

  if (forceReset || !rangeTouched.value) {
    rangeTouched.value = false
    setYearRange(nextMin, nextMax, false)
    applyYearRangeNow()
    return
  }

  const [from, to] = yearRange.value
  setYearRange(Math.max(nextMin, from), Math.min(nextMax, to), false)
  applyYearRangeNow()
}

function setYearRange(from, to, markTouched = true) {
  if (markTouched) {
    rangeTouched.value = true
  } else {
    suppressNextRangeWatch = true
  }
  yearRange.value = normalizedRange(from, to)
}

function userAvatarUrl(user) {
  return safeAvatarUrl(user?.photo)
}

function createLeafletMarker(data) {
  const layer = L.marker([data.lat, data.lng], {
    icon: getDirectionIcon(data.direction),
  })

  layer.on('click', () => {
    openPhoto(data.id)
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

function onMapPreviewClick(event) {
  const card = event.target.closest?.('.marker-preview-card')
  if (!card) return
  if (event.defaultPrevented || event.button !== 0) return
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return

  const href = card.getAttribute('href')
  if (!href) return

  event.preventDefault()
  const id = href.split('/').pop()
  openPhoto(id)
}

function rebuildMarkerRegistry() {
  initMapMarkerIcons()
  markerLayer?.clearLayers()
  markerRegistry = new Map()
  markersByYear = new Map()
  shownLayers = new Set()

  for (const data of markers.value) {
    const year = Number(data.year)
    const entry = {
      layer: createLeafletMarker(data),
      year,
      data,
    }
    markerRegistry.set(data.id, entry)

    if (!validYear(year)) continue

    if (!markersByYear.has(year)) {
      markersByYear.set(year, [])
    }
    markersByYear.get(year).push(entry)
  }
}

function layersInYearRange(from, to) {
  const layers = []

  for (let year = from; year <= to; year += 1) {
    const bucket = markersByYear.get(year)
    if (!bucket) continue

    for (const entry of bucket) {
      layers.push(entry.layer)
    }
  }

  return layers
}

function syncMarkersToFilter() {
  if (!markerLayer) return

  const [from, to] = activeYearRange.value
  const nextLayers = layersInYearRange(from, to)
  const nextSet = new Set(nextLayers)

  if (!shownLayers.size && nextLayers.length) {
    markerLayer.addLayers(nextLayers)
    shownLayers = nextSet
    return
  }

  if (!nextLayers.length) {
    if (shownLayers.size) markerLayer.clearLayers()
    shownLayers = new Set()
    return
  }

  const toRemove = []
  for (const layer of shownLayers) {
    if (!nextSet.has(layer)) toRemove.push(layer)
  }

  const toAdd = []
  for (const layer of nextLayers) {
    if (!shownLayers.has(layer)) toAdd.push(layer)
  }

  const changeCount = toRemove.length + toAdd.length
  const changeRatio = changeCount / Math.max(shownLayers.size, 1)

  if (changeRatio > 0.35) {
    markerLayer.clearLayers()
    markerLayer.addLayers(nextLayers)
    shownLayers = nextSet
    return
  }

  if (toRemove.length) markerLayer.removeLayers(toRemove)
  if (toAdd.length) markerLayer.addLayers(toAdd)
  shownLayers = nextSet
}

function scheduleMarkerSync() {
  window.cancelAnimationFrame(markerSyncFrame)
  window.clearTimeout(markerSyncTimer)
  markerSyncTimer = window.setTimeout(() => {
    markerSyncFrame = window.requestAnimationFrame(syncMarkersToFilter)
  }, 100)
}

function initMap() {
  if (map || !mapElement.value) return

  initMapMarkerIcons()

  const layer = currentTileLayer()
  map = L.map(mapElement.value, {
    center: DEFAULT_CENTER,
    zoom: DEFAULT_ZOOM,
    minZoom: 11,
    maxZoom: MAP_MAX_ZOOM,
    zoomControl: true,
    scrollWheelZoom: true,
    crs: layer.crs,
    attributionControl: false,
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
    disableClusteringAtZoom: MAP_MAX_ZOOM,
    iconCreateFunction: createClusterIconFactory(),
  }).addTo(map)
  setTileLayer()
  mapElement.value.removeEventListener('click', onMapPreviewClick)
  mapElement.value.addEventListener('click', onMapPreviewClick)
  if (markers.value.length) rebuildMarkerRegistry()
  syncMarkersToFilter()
}

function resetMarkerState() {
  window.cancelAnimationFrame(markerSyncFrame)
  window.clearTimeout(markerSyncTimer)
  markerLayer?.clearLayers()
  shownLayers = new Set()
}

async function rebuildMapForProvider() {
  if (!map) return

  const center = map.getCenter()
  const zoom = map.getZoom()

  map.remove()
  map = null
  tileLayer = null
  overlayLayer = null
  markerLayer = null
  shownLayers = new Set()

  await nextTick()
  initMap()
  map.setView(center, zoom, { animate: false })
  syncMarkersToFilter()
}

function setTileLayer() {
  if (!map) return
  if (tileLayer) {
    tileLayer.remove()
    tileLayer = null
  }
  if (overlayLayer) {
    overlayLayer.remove()
    overlayLayer = null
  }
  const layer = currentTileLayer()
  tileLayer = L.tileLayer(layer.url, layer.options).addTo(map)
  if (layer.overlayUrl) {
    overlayLayer = L.tileLayer(layer.overlayUrl, layer.overlayOptions).addTo(map)
  }
}

function applyYearRangeNow() {
  activeYearRange.value = normalizedRange(yearRange.value[0], yearRange.value[1])
}

function scheduleYearApply() {
  window.cancelAnimationFrame(yearApplyFrame)
  yearApplyFrame = window.requestAnimationFrame(applyYearRangeNow)
}

function applyYearRange() {
  rangeTouched.value = true
  scheduleYearApply()
}

function onYearSliderChange(value) {
  rangeTouched.value = true
  yearRange.value = normalizedRange(value[0], value[1])
  scheduleYearApply()
}

async function loadSecondaryContent() {
  // Loaded separately so a failure here never blocks the map markers.
  const [ratingData, photoData, newsData] = await Promise.allSettled([
    cachedApi('/ratings'),
    cachedApi('/photos?per_page=3'),
    cachedApi('/news?per_page=3'),
  ])
  if (ratingData.status === 'fulfilled') ratings.value = ratingData.value
  if (photoData.status === 'fulfilled') photos.value = photoData.value.data || []
  if (newsData.status === 'fulfilled') news.value = newsData.value.data || []
  loadFilteredUserLabel()
}

onMounted(async () => {
  try {
    const markerData = await api(markersEndpoint())
    markers.value = Array.isArray(markerData) ? markerData : []
    applyMarkerYearBounds(true)
  } catch (event) {
    loadError.value = event.message
  } finally {
    loading.value = false
    await nextTick()
    if (!map) {
      initMap()
    } else {
      syncMarkersToFilter()
    }
  }

  loadSecondaryContent()
})

watch(mapProvider, rebuildMapForProvider)
watch(mapType, () => {
  if (!map) return
  setTileLayer()
})
watch([theme, currentLanguage], () => {
  if (!map) return
  setTileLayer()
})
watch(activeYearRange, scheduleMarkerSync, { deep: true })

async function reloadMarkersForFilter() {
  loadFilteredUserLabel()
  markersLoading.value = true
  try {
    const markerData = await api(markersEndpoint())
    // Let the loading indicator paint before the heavy marker rebuild blocks the thread.
    await nextTick()
    markers.value = Array.isArray(markerData) ? markerData : []
    rangeTouched.value = false
    rebuildMarkerRegistry()
    applyMarkerYearBounds(true)
    syncMarkersToFilter()
    if (map) map.setView(DEFAULT_CENTER, DEFAULT_ZOOM, { animate: true })
  } catch {
    // keep current data on transient errors
  } finally {
    markersLoading.value = false
  }
}

watch([userFilter, reviewFilter], reloadMarkersForFilter)

watch(currentLanguage, () => {
  rebuildMarkerRegistry()
  syncMarkersToFilter()
})
watch(yearRange, ([from, to]) => {
  const normalized = normalizedRange(from, to)

  if (suppressNextRangeWatch) {
    suppressNextRangeWatch = false
    activeYearRange.value = normalized
    return
  }

  if (normalized[0] !== from || normalized[1] !== to) {
    yearRange.value = normalized
    activeYearRange.value = normalized
    return
  }

  applyYearRange()
})
watch(yearBounds, () => {
  applyMarkerYearBounds(false)
})

onBeforeUnmount(() => {
  resetMarkerState()
  window.cancelAnimationFrame(yearApplyFrame)
  mapElement.value?.removeEventListener('click', onMapPreviewClick)
  map?.remove()
  map = null
})
</script>

<template>
  <section class="legacy-map-shell">
    <div class="real-map">
      <div ref="mapElement" class="leaflet-map"></div>
      <div class="map-tools" :class="{ open: mapPanelOpen }">
        <button
          type="button"
          class="map-tools-toggle"
          :class="{ open: mapPanelOpen, active: reviewFilter }"
          :aria-expanded="mapPanelOpen"
          :aria-label="t('mapSettings')"
          @click="mapPanelOpen = !mapPanelOpen"
        >
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path d="M3 7h11M18 7h3M3 17h3M10 17h11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            <circle cx="16" cy="7" r="2.4" fill="none" stroke="currentColor" stroke-width="2" />
            <circle cx="8" cy="17" r="2.4" fill="none" stroke="currentColor" stroke-width="2" />
          </svg>
          <span class="map-tools-toggle-label">{{ t('mapSettings') }}</span>
        </button>
        <transition name="tools-panel">
          <div v-if="mapPanelOpen" class="map-tools-panel">
            <div class="map-tools-group">
              <span class="map-tools-label">{{ t('switchMap') }}</span>
              <div class="map-actions">
                <button :class="{ active: mapProvider === 'google' }" @click="selectProvider('google')">
                  <img class="map-logo" :src="googleLogo" alt="" />{{ t('google') }}
                </button>
                <button :class="{ active: mapProvider === 'yandex' }" @click="selectProvider('yandex')">
                  <img class="map-logo" :src="yandexLogo" alt="" />{{ t('yandex') }}
                </button>
              </div>
              <div class="map-types">
                <button
                  v-for="type in availableMapTypes"
                  :key="type"
                  type="button"
                  :class="{ active: mapType === type }"
                  @click="mapType = type"
                >
                  {{ mapTypeLabel(type) }}
                </button>
              </div>
            </div>
            <div class="map-tools-group">
              <button
                type="button"
                class="map-review-toggle"
                :class="{ active: reviewFilter }"
                :aria-pressed="reviewFilter"
                @click="toggleReviewFilter"
              >
                <span class="map-review-dot" aria-hidden="true"></span>
                {{ t('reviewFilter') }}
              </button>
            </div>
          </div>
        </transition>
      </div>
      <div v-if="userFilter" class="map-left-controls">
        <div class="map-user-filter">
          <span class="map-user-filter-label">
            {{ t('userPhotos') }}<template v-if="filteredUserName">: <strong>{{ filteredUserName }}</strong></template>
          </span>
          <button type="button" @click="clearUserFilter">{{ t('showAllPhotos') }}</button>
        </div>
      </div>
      <transition name="fade">
        <div v-if="loading || markersLoading" class="map-loading">
          <span class="map-loading-spinner" aria-hidden="true"></span>
          {{ t('loadingMarkers') }}
        </div>
      </transition>
      <span v-if="loadError" class="map-loading error">{{ loadError }}</span>
    </div>
  </section>

  <section class="year-filter">
    <span class="year-filter-label">{{ t('yearRange') }}</span>
    <div class="year-filter-track">
      <Slider
        v-model="yearRange"
        :min="minYear"
        :max="maxYear"
        :step="1"
        :tooltips="false"
        :lazy="false"
        class="year-slider"
        @change="onYearSliderChange"
      />
      <div class="year-ticks">
        <span>{{ minYear }}</span>
        <span>{{ maxYear }}</span>
      </div>
    </div>
    <span class="year-filter-values">
      <strong>{{ yearRange[0] }}</strong>
      <em>—</em>
      <strong>{{ yearRange[1] }}</strong>
    </span>
  </section>

  <div class="hero-actions under-map">
    <RouterLink class="button" to="/photos">{{ t('explorePhotos') }}</RouterLink>
    <button class="button button-ghost" type="button" @click="requireAuth('/photos/add')">{{ t('addPhoto') }}</button>
  </div>

  <section class="section-grid rating-grid">
    <article class="panel latest-panel">
      <h2>{{ t('latestPhotos') }}</h2>
      <RouterLink v-for="photo in photos" :key="photo.id" class="latest-photo" :to="`/photos/${photo.id}`">
        <img :src="imageUrl(photo.images.thumb)" :alt="photo.title" />
        <span>{{ photo.year }}</span>
        <strong>{{ photo.title }}</strong>
      </RouterLink>
    </article>
    <article class="panel">
      <h2>{{ t('photosByViews') }}</h2>
      <RouterLink
        v-for="photo in ratings?.photos_by_views || []"
        :key="photo.id"
        class="rating-photo-row"
        :to="`/photos/${photo.id}`"
      >
        <img :src="imageUrl(`/api/photos/file/thumb/${photo.file_id}`)" :alt="photo.title" />
        <span>
          <strong>{{ photo.title }}</strong>
          <small>{{ photo.year }} · {{ photo.views }} {{ t('views') }}</small>
        </span>
      </RouterLink>
    </article>
    <article class="panel">
      <h2>{{ t('photosByComments') }}</h2>
      <RouterLink
        v-for="photo in ratings?.photos_by_comments || []"
        :key="photo.id"
        class="rating-photo-row"
        :to="`/photos/${photo.id}`"
      >
        <img :src="imageUrl(`/api/photos/file/thumb/${photo.file_id}`)" :alt="photo.title" />
        <span>
          <strong>{{ photo.title }}</strong>
          <small>{{ photo.year }} · {{ photo.comments_count }} {{ t('comments') }}</small>
        </span>
      </RouterLink>
    </article>
    <article class="panel">
      <h2>{{ t('usersByPhotos') }}</h2>
      <RouterLink v-for="user in ratings?.users_by_photos || []" :key="user.id" class="author-row" :to="`/users/${user.unique}`">
        <img class="author-avatar" :src="userAvatarUrl(user)" :alt="`${user.first_name} ${user.last_name}`" />
        <span>{{ user.first_name }} {{ user.last_name }}</span>
        <strong>{{ user.photos_count }} {{ t('photosCount') }}</strong>
      </RouterLink>
    </article>
    <article class="panel">
      <h2>{{ t('usersByComments') }}</h2>
      <RouterLink v-for="user in ratings?.users_by_comments || []" :key="user.id" class="author-row" :to="`/users/${user.unique}`">
        <img class="author-avatar" :src="userAvatarUrl(user)" :alt="`${user.first_name} ${user.last_name}`" />
        <span>{{ user.first_name }} {{ user.last_name }}</span>
        <strong>{{ user.comments_count }} {{ t('comments') }}</strong>
      </RouterLink>
    </article>
    <article class="panel news-panel">
      <h2>{{ t('latestNews') }}</h2>
      <RouterLink v-for="item in news" :key="item.id" class="news-row" :to="`/news/${item.id}`">
        <strong>{{ item.title }}</strong>
        <span>{{ new Date(item.date).toLocaleDateString() }}</span>
      </RouterLink>
    </article>
  </section>

  <PhotoDetailSheet
    :photo-id="activePhotoId"
    @close="activePhotoId = null"
    @navigate="openPhoto"
  />
</template>

<style lang="scss">
.legacy-map-shell {
  position: relative;
  width: 100vw;
  margin-left: calc(50% - 50vw);
  margin-right: calc(50% - 50vw);
  margin-bottom: 22px;
  overflow: hidden;
  border-radius: 0;
  border-top: 1px solid $line;
  border-bottom: 1px solid $line;

  @include mq-down($bp-md) {
    margin-bottom: 14px;
  }
}

.real-map {
  position: relative;
  height: min(82vh, 820px);
  overflow: hidden;
  background: $bg-deep;

  @include mq-down($bp-md) {
    height: 78vh;
    min-height: 460px;
    max-height: calc(100vh - 80px);
  }
}

.leaflet-map {
  width: 100%;
  height: 100%;
}

// ---------- Collapsible map tools (type + filters) ---------------
.map-tools {
  position: absolute;
  top: calc(var(--header-h) + 14px);
  right: 16px;
  z-index: 600;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;

  @include mq-down($bp-md) {
    top: auto;
    bottom: 14px;
    right: 10px;
    flex-direction: column-reverse;
  }
}

.map-tools-toggle {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 9px 14px;
  border: 1px solid rgba($ink, 0.08);
  border-radius: $radius-pill;
  color: $ink;
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: blur(10px);
  box-shadow: $shadow-md;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  @include interactive((background, color, box-shadow, transform));

  svg {
    flex-shrink: 0;
  }

  &:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(23, 52, 126, 0.18);
  }

  &.open {
    color: #fff;
    border-color: transparent;
    background: linear-gradient(135deg, $primary, $primary-dark);
    box-shadow: 0 10px 24px rgba($primary, 0.32);
  }

  // Subtle dot when the review filter is on but the panel is closed.
  &.active:not(.open)::after {
    content: '';
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: $review-color;
    box-shadow: 0 0 0 3px rgba($review-color, 0.2);
  }

  @include focus-ring(rgba($primary, 0.42), 2px);
}

.map-tools-toggle-label {
  @include mq-down($bp-sm) {
    display: none;
  }
}

.map-tools-panel {
  display: grid;
  gap: 12px;
  width: min(78vw, 248px);
  padding: 12px;
  border: 1px solid rgba($ink, 0.08);
  border-radius: $radius-lg;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(12px);
  box-shadow: $shadow-lg;
}

.map-tools-group {
  display: grid;
  gap: 7px;
}

.map-tools-label {
  padding-left: 2px;
  color: $muted;
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.tools-panel-enter-active,
.tools-panel-leave-active {
  transition:
    opacity 0.2s ease,
    transform 0.24s cubic-bezier(0.22, 1, 0.36, 1);
  transform-origin: top right;
}

.tools-panel-enter-from,
.tools-panel-leave-to {
  opacity: 0;
  transform: translateY(-8px) scale(0.97);
}

@include mq-down($bp-md) {
  .tools-panel-enter-active,
  .tools-panel-leave-active {
    transform-origin: bottom right;
  }

  .tools-panel-enter-from,
  .tools-panel-leave-to {
    transform: translateY(8px) scale(0.97);
  }
}

.map-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;

  @include mq-down($bp-md) {
    grid-template-columns: 1fr 1fr;
    gap: 4px;
  }

  button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border: 0;
    border-radius: $radius-pill;
    padding: 7px 11px;
    color: $primary;
    background: $primary-light;
    cursor: pointer;
    font-size: 12px;
    font-weight: 500;
    @include interactive((background, color, box-shadow, transform));

    &:hover {
      background: darken($primary-light, 4%);
    }

    &.active {
      color: #fff;
      background: linear-gradient(135deg, $primary, $primary-dark);
      box-shadow: 0 8px 18px rgba($primary, 0.22);
    }

    @include focus-ring(rgba($primary, 0.42), 2px);
  }
}

.map-logo {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  object-fit: contain;
}

.map-types {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;

  button {
    flex: 1 1 auto;
    min-width: 0;
    padding: 5px 9px;
    border: 1px solid rgba($ink, 0.08);
    border-radius: $radius-pill;
    color: $muted;
    background: rgba($ink, 0.02);
    cursor: pointer;
    font-size: 11px;
    font-weight: 500;
    white-space: nowrap;
    @include interactive((background, color, border-color));

    &:hover {
      color: $ink;
      background: rgba($ink, 0.05);
    }

    &.active {
      color: #fff;
      border-color: transparent;
      background: linear-gradient(135deg, $accent, $accent-dark);
      box-shadow: 0 6px 14px rgba($accent-dark, 0.26);
    }

    @include focus-ring(rgba($accent, 0.42), 2px);
  }

  @include mq-down($bp-md) {
    button {
      flex: 1 1 calc(50% - 3px);
      padding: 5px 6px;
    }
  }
}

.map-left-controls {
  position: absolute;
  top: calc(var(--header-h) + 14px);
  left: 60px;
  z-index: 600;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
  max-width: min(70vw, 420px);

  @include mq-down($bp-md) {
    top: calc(10px + 52px + 10px);
    left: 52px;
    right: 10px;
    max-width: none;
  }
}

.map-review-toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 8px 12px;
  border: 1px solid rgba($ink, 0.1);
  border-radius: $radius-md;
  color: $ink;
  background: rgba($ink, 0.02);
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.2;
  text-align: left;
  @include interactive((background, color, border-color));

  .map-review-dot {
    flex-shrink: 0;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: $review-color;
    box-shadow: 0 0 0 3px rgba($review-color, 0.22);
  }

  &:hover {
    background: rgba($review-color, 0.08);
    border-color: rgba($review-color, 0.3);
  }

  &.active {
    color: #fff;
    border-color: transparent;
    background: linear-gradient(135deg, $review-color, $review-color-dark);
    box-shadow: 0 6px 14px rgba($review-color-dark, 0.28);

    .map-review-dot {
      background: #fff;
      box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.3);
    }
  }

  @include focus-ring(rgba($review-color, 0.42), 2px);
}

.map-user-filter {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  max-width: 100%;
  padding: 8px 8px 8px 14px;
  border: 1px solid rgba($ink, 0.08);
  border-radius: $radius-pill;
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: blur(10px);
  box-shadow: $shadow-md;

  @include mq-down($bp-md) {
    padding: 7px 7px 7px 14px;
  }

  .map-user-filter-label {
    color: $muted;
    font-size: 12px;
    font-weight: 500;
    @include truncate;

    strong {
      color: $ink;
      font-weight: 600;
    }
  }

  button {
    flex-shrink: 0;
    padding: 5px 11px;
    border: 0;
    border-radius: $radius-pill;
    color: #fff;
    background: linear-gradient(135deg, $primary, $primary-dark);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    @include interactive((box-shadow, transform));

    &:hover {
      box-shadow: 0 6px 14px rgba($primary, 0.3);
    }

    @include focus-ring(rgba($primary, 0.42), 2px);
  }
}

.map-loading {
  position: absolute;
  top: 50%;
  left: 50%;
  z-index: 650;
  display: inline-flex;
  align-items: center;
  gap: 9px;
  transform: translate(-50%, -50%);
  padding: 10px 16px;
  border-radius: $radius-pill;
  color: $primary;
  background: #fff;
  font-size: 13px;
  font-weight: 600;
  box-shadow: 0 14px 34px rgba(23, 52, 126, 0.18);

  &.error {
    color: $danger;
  }
}

.map-loading-spinner {
  width: 15px;
  height: 15px;
  border: 2px solid rgba($primary, 0.25);
  border-top-color: $primary;
  border-radius: 50%;
  animation: map-spin 0.7s linear infinite;
}

@keyframes map-spin {
  to {
    transform: rotate(360deg);
  }
}

// ---------- Year filter (lives BELOW the map) --------------------
.year-filter {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 16px;
  width: 100%;
  max-width: 100%;
  margin: 14px 0 12px;
  padding: 10px 18px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface;
  box-shadow: $shadow-sm;

  @include mq-down($bp-md) {
    grid-template-columns: 1fr auto;
    gap: 10px;
    padding: 10px 14px;
    border-radius: $radius-md;
  }

  @include mq-down($bp-sm) {
    grid-template-columns: 1fr;
    gap: 6px;
    padding: 10px 14px 8px;
    margin-inline: 0;
  }
}

.hero-actions.under-map {
  @include mq-down($bp-sm) {
    flex-direction: column;

    .button,
    .button-ghost {
      width: 100%;
      justify-content: center;
    }
  }
}

.year-filter-track {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.year-filter-label {
  color: $muted;
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  white-space: nowrap;

  @include mq-down($bp-md) {
    display: none;
  }
}

.year-filter-values {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 12px;
  border-radius: $radius-pill;
  background: linear-gradient(135deg, $accent, $accent-dark);
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  box-shadow: 0 6px 14px rgba($accent-dark, 0.28);

  strong {
    font-weight: 600;
    font-variant-numeric: tabular-nums;
  }

  em {
    opacity: 0.7;
    font-style: normal;
    font-weight: 400;
  }
}

.year-slider {
  --slider-bg: #e4eaf6;
  --slider-connect-bg: #{$accent};
  --slider-handle-bg: #fff;
  --slider-handle-border: 0;
  --slider-handle-ring-color: rgba(255, 145, 15, 0.22);
  --slider-height: 4px;
  --slider-radius: 999px;
  --slider-handle-width: 14px;
  --slider-handle-height: 14px;
  --slider-handle-shadow: 0 4px 10px rgba($accent-dark, 0.32);
  padding: 0;
  min-width: 0;

  &.slider-target {
    height: 18px;
    padding-inline: 8px;
    border: 0;
    background: transparent;
    box-shadow: none;
  }

  .slider-base {
    top: 7px;
    height: 4px;
    border: 0;
    border-radius: $radius-pill;
    background: #e4eaf6;
    box-shadow: inset 0 1px 1px rgba(23, 52, 126, 0.08);
  }

  .slider-connect {
    height: 100%;
    border-radius: $radius-pill;
    background: linear-gradient(90deg, $accent, $accent-dark);
  }

  .slider-handle {
    top: -5px;
    width: 14px;
    height: 14px;
    border: 0;
    border-radius: 50%;
    background: #fff;
    box-shadow:
      0 0 0 2px $accent,
      0 4px 8px rgba($accent-dark, 0.32);
    cursor: grab;
    @include interactive((box-shadow, transform));

    &:hover,
    &:focus {
      transform: scale(1.18);
      box-shadow:
        0 0 0 2px $accent,
        0 0 0 6px rgba($accent, 0.2),
        0 6px 14px rgba($accent-dark, 0.4);
    }

    &:active {
      cursor: grabbing;
      transform: scale(1.22);
    }
  }

  .slider-touch-area {
    inset: -10px;
    width: auto;
    height: auto;
  }

  .slider-tooltip {
    display: none;
  }
}

.year-ticks {
  display: flex;
  justify-content: space-between;
  padding: 0 8px;
  color: $muted;
  font-size: 10px;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
}

.camera-direction-icon,
.photo-cluster-icon {
  background: transparent !important;
  border: 0 !important;
}

.map-pin-svg {
  display: block;
  overflow: visible;
  filter: drop-shadow(0 3px 6px rgba($accent-dark, 0.28));
  @include interactive((transform, filter));
}

.camera-direction-icon:hover .map-pin-svg {
  transform: translateY(-2px) scale(1.12);
  filter: drop-shadow(0 8px 14px rgba($accent-dark, 0.38));
}

.map-cluster-pin {
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  color: #fff;
  font-weight: 700;
  line-height: 1;
  border: 2px solid #fff;
  background:
    radial-gradient(circle at 32% 28%, rgba(255, 255, 255, 0.38) 0 16%, transparent 17%),
    linear-gradient(145deg, lighten($accent, 4%), $accent-dark);
  box-shadow:
    0 0 0 2px rgba($accent, 0.14),
    0 3px 8px rgba($accent-dark, 0.28);
  @include interactive((transform, box-shadow));

  span {
    position: relative;
    z-index: 1;
    text-shadow: 0 1px 1px rgba(0, 0, 0, 0.28);
    pointer-events: none;
  }
}

.photo-cluster-icon:hover .map-cluster-pin {
  transform: scale(1.08);
  box-shadow:
    0 0 0 3px rgba($accent, 0.12),
    0 6px 12px rgba($accent-dark, 0.32);
}

// ---------- Ratings / latest blocks ------------------------------
.rating-grid {
  grid-template-columns: repeat(3, minmax(0, 1fr));

  .panel {
    padding: 18px;
  }

  h2 {
    margin-bottom: 8px;
    font-size: 16px;
    line-height: 1.25;
  }

  @include mq-down($bp-md) {
    grid-template-columns: 1fr;
    padding: 0;
  }
}

.latest-panel,
.news-panel {
  background:
    linear-gradient(145deg, rgba(255, 255, 255, 0.98), rgba(237, 243, 255, 0.92)),
    #fff;
}

.latest-photo,
.news-row {
  display: grid;
  grid-template-columns: 58px minmax(0, 1fr);
  gap: 10px;
  align-items: center;
  padding: 8px 4px;
  border-top: 1px solid $line;
  border-radius: $radius-xs;
  color: inherit;
  text-decoration: none;
  @include interactive((background, transform));

  &:hover {
    background: rgba($primary, 0.04);
    transform: translateX(2px);
  }

  @include focus-ring(rgba($primary, 0.35), 0px);
}

.latest-photo {
  img {
    width: 58px;
    height: 44px;
    border-radius: $radius-sm;
    object-fit: cover;
    background: $surface-soft;
  }

  span {
    grid-column: 2;
    width: max-content;
    padding: 2px 8px;
    border-radius: $radius-pill;
    color: #fff;
    background: $accent;
    font-size: 12px;
    font-weight: 500;
  }

  strong {
    grid-column: 2;
    font-size: 13px;
    font-weight: 500;
    @include truncate;
  }
}

.news-row {
  grid-template-columns: 1fr auto;

  strong {
    font-size: 13px;
    font-weight: 500;
    @include truncate;
  }

  span {
    color: $muted;
    font-size: 12px;
  }
}

.rating-photo-row,
.author-row {
  display: grid;
  grid-template-columns: 40px minmax(0, 1fr) auto;
  gap: 10px;
  align-items: center;
  padding: 8px 4px;
  border-top: 1px solid $line;
  border-radius: $radius-xs;
  color: inherit;
  text-decoration: none;
  @include interactive((background, transform));

  &:hover {
    background: rgba($primary, 0.04);
    transform: translateX(2px);
  }

  @include focus-ring(rgba($primary, 0.35), 0px);

  img {
    width: 40px;
    height: 34px;
    border-radius: $radius-xs + 2;
    object-fit: cover;
    background: $primary-light;
  }

  span {
    display: grid;
    min-width: 0;

    &:nth-child(2) {
      @include truncate;
    }
  }

  strong {
    color: $primary-dark;
    font-weight: 600;
    @include truncate;
  }

  small {
    color: $muted;
    font-size: 11px;
    font-weight: 400;
  }
}

.author-row {
  grid-template-columns: 40px minmax(0, 1fr) auto;
}

.author-avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  object-fit: cover;
  background: $primary-light;
  box-shadow: 0 8px 18px rgba($primary, 0.18);
}
</style>

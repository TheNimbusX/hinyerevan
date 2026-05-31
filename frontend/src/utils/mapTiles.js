import L from 'leaflet'

/** Site language code → Google Maps `hl` parameter */
const GOOGLE_HL = {
  hy: 'hy',
  ru: 'ru',
  en: 'en',
}

/** Site language code → Yandex `lang` parameter */
const YANDEX_LANG = {
  hy: 'hy_AM',
  ru: 'ru_RU',
  en: 'en_US',
}

/** Leaflet zoom ceiling for homepage map (tiles upscale via maxNativeZoom). */
export const MAP_MAX_ZOOM = 20
export const MAP_TILE_NATIVE_ZOOM = 19

/** Night-style palette for Google raster tiles (undocumented `apistyle` param). */
const GOOGLE_DARK_APISTYLE = [
  's.t:3|p.h:0x242f3e|p.s:-100|p.l:-25|p.v:on',
  's.t:4|p.h:0xadadad|p.s:-100|p.l:40|p.v:on',
  's.t:5|p.h:0x818b99|p.s:-20|p.l:-5|p.v:on',
  's.t:6|p.h:0x818b99|p.s:-20|p.l:-40|p.v:on',
  's.t:8|p.h:0x181818|p.s:-100|p.l:-25|p.v:on',
  's.t:9|p.h:0xadadad|p.s:-100|p.l:-25|p.v:on',
].join(',')

export function googleMapLanguage(siteLang) {
  return GOOGLE_HL[siteLang] || GOOGLE_HL.ru
}

export function yandexMapLanguage(siteLang) {
  return YANDEX_LANG[siteLang] || YANDEX_LANG.ru
}

/** Google `lyrs` code per map type. */
const GOOGLE_LYRS = {
  scheme: 'm',
  satellite: 's',
  hybrid: 'y',
  terrain: 'p',
}

/** Yandex `l` layer code per map type. */
const YANDEX_LAYER = {
  scheme: 'map',
  satellite: 'sat',
  hybrid: 'sat', // base imagery, labels added as overlay (skl)
}

/** Map types available for each provider (used to build the UI selector). */
export const MAP_TYPES = {
  google: ['scheme', 'satellite', 'hybrid', 'terrain'],
  yandex: ['scheme', 'satellite', 'hybrid'],
}

/** Fall back to a supported type when switching providers. */
export function normalizeMapType(provider, type) {
  const available = MAP_TYPES[provider] || MAP_TYPES.google
  return available.includes(type) ? type : 'scheme'
}

/** Satellite imagery is served from a dedicated Yandex host. */
function yandexTileUrl(layerCode, lang, extra = '') {
  const host = layerCode === 'sat' ? 'core-sat.maps.yandex.net' : 'core-renderer-tiles.maps.yandex.net'
  return `https://${host}/tiles?l=${layerCode}&x={x}&y={y}&z={z}&scale=1&lang=${lang}${extra}`
}

function baseTileOptions(extra = {}) {
  return {
    maxZoom: MAP_MAX_ZOOM,
    maxNativeZoom: MAP_TILE_NATIVE_ZOOM,
    updateWhenZooming: false,
    updateWhenIdle: true,
    keepBuffer: 2,
    ...extra,
  }
}

/**
 * @param {'google'|'yandex'} provider
 * @param {'light'|'dark'} theme
 * @param {string} siteLang - hy | ru | en
 * @param {'scheme'|'satellite'|'hybrid'|'terrain'} [type='scheme']
 * @returns {{ url: string, crs: L.CRS, options: object, overlayUrl?: string, overlayOptions?: object }}
 */
export function getMapTileLayer(provider, theme, siteLang, type = 'scheme') {
  const isDark = theme === 'dark'
  const mapType = normalizeMapType(provider, type)

  if (provider === 'yandex') {
    const lang = yandexMapLanguage(siteLang)
    const layerCode = YANDEX_LAYER[mapType] || YANDEX_LAYER.scheme
    // Dark night style only makes sense for the vector scheme.
    const themeParam = isDark && mapType === 'scheme' ? '&theme=dark' : ''
    const config = {
      url: yandexTileUrl(layerCode, lang, themeParam),
      crs: L.CRS.EPSG3395,
      options: baseTileOptions({ attribution: '© Yandex' }),
    }

    if (mapType === 'hybrid') {
      config.overlayUrl = yandexTileUrl('skl', lang)
      config.overlayOptions = baseTileOptions({ attribution: '© Yandex', pane: 'overlayPane' })
    }

    return config
  }

  const hl = googleMapLanguage(siteLang)
  const lyrs = GOOGLE_LYRS[mapType] || GOOGLE_LYRS.scheme
  let url = `https://mt{s}.google.com/vt/lyrs=${lyrs}&hl=${hl}&x={x}&y={y}&z={z}`
  // Night palette only applies to the plain scheme; imagery layers stay natural.
  if (isDark && mapType === 'scheme') {
    url += `&apistyle=${encodeURIComponent(GOOGLE_DARK_APISTYLE)}`
  }

  return {
    url,
    crs: L.CRS.EPSG3857,
    options: baseTileOptions({
      attribution: '© Google',
      subdomains: ['0', '1', '2', '3'],
    }),
  }
}

/**
 * Attach or replace a tile layer on an existing Leaflet map.
 * @returns {import('leaflet').TileLayer}
 */
/**
 * Lightweight tiles for photo detail / upload previews (no Google dependency).
 * @param {'light'|'dark'} theme
 */
export function getMiniMapTileLayer(theme) {
  const isDark = theme === 'dark'

  return {
    url: isDark
      ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
      : 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
    crs: L.CRS.EPSG3857,
    options: {
      attribution: '© OpenStreetMap © CARTO',
      subdomains: 'abcd',
      maxZoom: MAP_MAX_ZOOM,
      maxNativeZoom: MAP_TILE_NATIVE_ZOOM,
      updateWhenZooming: false,
      updateWhenIdle: true,
      keepBuffer: 2,
    },
  }
}

export function applyMapTileLayer(map, tileLayerRef, provider, theme, siteLang, type = 'scheme') {
  if (!map) return tileLayerRef

  if (tileLayerRef) {
    tileLayerRef.remove()
  }

  const config = getMapTileLayer(provider, theme, siteLang, type)
  const layer = L.tileLayer(config.url, config.options)
  layer.addTo(map)
  return layer
}

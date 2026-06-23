<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { api, fullPhotoPath, imageUrl } from '../api'
import { useI18n } from '../i18n'
import { useTheme } from '../composables/useTheme'
import { applyMapTileLayer, getMapTileLayer } from '../utils/mapTiles'
import { getDirectionIcon } from '../utils/mapMarkerIcons'
import { setupLeaflet } from '../utils/leafletSetup'
import { isYoutubeUrl, youtubeId } from '../utils/video'
import CompassNeedle from './CompassNeedle.vue'
import DirectionCompassPicker from './DirectionCompassPicker.vue'

const props = defineProps({
  photoId: {
    type: Number,
    required: true,
  },
})

const emit = defineEmits(['close', 'saved'])

const { t, currentLanguage } = useI18n()
const { theme } = useTheme()

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const author = ref(null)
const previewUrl = ref('')
const mediaTab = ref('photo')
const publishToFacebook = ref(false)
const facebookConfigured = ref(true)
const existingFacebookPost = ref(false)
const editMapElement = ref(null)
let editMap
let editMapTileLayer
let editMarker

const form = ref({
  title: '',
  year: '',
  lat: '',
  lng: '',
  direction: 1,
  file: null,
  video: '',
  needs_location_review: false,
  is_winter: false,
  published: true,
  facebook_comment: '',
})

const MAX_UPLOAD_BYTES = 10 * 1024 * 1024

const videoThumbPreview = computed(() => {
  const id = youtubeId(form.value.video)
  return id ? `https://img.youtube.com/vi/${id}/hqdefault.jpg` : ''
})

const currentPreview = computed(() => {
  if (mediaTab.value === 'video') return videoThumbPreview.value
  return previewUrl.value
})

function editMarkerIcon() {
  return getDirectionIcon(form.value.direction)
}

function bindMarkerDrag(marker) {
  marker.off('dragend')
  marker.on('dragend', () => {
    const latlng = marker.getLatLng()
    form.value.lat = latlng.lat.toFixed(12)
    form.value.lng = latlng.lng.toFixed(12)
  })
}

function setCoordinates(latlng) {
  form.value.lat = latlng.lat.toFixed(12)
  form.value.lng = latlng.lng.toFixed(12)
  if (!editMarker) {
    editMarker = L.marker(latlng, { icon: editMarkerIcon(), draggable: true }).addTo(editMap)
    bindMarkerDrag(editMarker)
  } else {
    editMarker.setLatLng(latlng)
    editMarker.setIcon(editMarkerIcon())
  }
}

function initEditMap() {
  if (editMap || !editMapElement.value) return
  setupLeaflet()
  const layer = getMapTileLayer('google', theme.value, currentLanguage.value)
  const lat = Number(form.value.lat) || 40.179136
  const lng = Number(form.value.lng) || 44.511623
  editMap = L.map(editMapElement.value, {
    center: [lat, lng],
    zoom: 13,
    attributionControl: false,
    crs: layer.crs,
  })
  editMapTileLayer = L.tileLayer(layer.url, layer.options).addTo(editMap)
  editMap.on('click', (event) => setCoordinates(event.latlng))
  if (form.value.lat && form.value.lng) {
    setCoordinates(L.latLng(lat, lng))
  }
  requestAnimationFrame(() => editMap?.invalidateSize())
}

function selectFile(event) {
  const file = event.target.files[0]
  error.value = ''

  if (!file) {
    form.value.file = null
    return
  }

  if (file.size > MAX_UPLOAD_BYTES) {
    form.value.file = null
    event.target.value = ''
    error.value = `${t('chooseFile')}: max ${Math.floor(MAX_UPLOAD_BYTES / 1024 / 1024)}MB`
    return
  }

  form.value.file = file
  previewUrl.value = URL.createObjectURL(file)
}

function fillFromPhoto(photo) {
  author.value = photo.author
  mediaTab.value = photo.video ? 'video' : 'photo'
  existingFacebookPost.value = Boolean(photo.facebook_post_id || photo.facebook_post_url)
  previewUrl.value = fullPhotoPath(photo.images) ? imageUrl(fullPhotoPath(photo.images)) : ''
  form.value = {
    title: photo.title || '',
    year: String(photo.year || ''),
    lat: photo.lat != null ? String(photo.lat) : '',
    lng: photo.lng != null ? String(photo.lng) : '',
    direction: photo.direction ?? 1,
    file: null,
    video: photo.video || '',
    needs_location_review: Boolean(photo.needs_location_review),
    is_winter: Boolean(photo.is_winter),
    published: photo.published !== false,
    facebook_comment: photo.facebook_comment || '',
  }
}

async function mountEditMap() {
  await nextTick()
  initEditMap()
  if (!editMap || !form.value.lat || !form.value.lng) return
  const latlng = L.latLng(Number(form.value.lat), Number(form.value.lng))
  editMap.setView(latlng, 15)
  setCoordinates(latlng)
  requestAnimationFrame(() => editMap?.invalidateSize())
}

async function loadPhoto() {
  loading.value = true
  error.value = ''
  editMarker?.remove()
  editMarker = null
  editMap?.remove()
  editMap = null
  try {
    const photo = await api(`/admin/photos/${props.photoId}`)
    fillFromPhoto(photo)
  } catch (event) {
    error.value = event.message
  } finally {
    loading.value = false
    if (!error.value) {
      await mountEditMap()
    }
  }
}

async function save() {
  error.value = ''

  const isVideo = mediaTab.value === 'video'

  if (isVideo) {
    if (!form.value.video || !isYoutubeUrl(form.value.video)) {
      error.value = t('videoLinkHelp')
      return
    }
  } else if (!previewUrl.value && !form.value.file) {
    error.value = t('chooseFile')
    return
  }

  if (!form.value.lat || !form.value.lng) {
    error.value = t('clickMapToSetPoint')
    return
  }

  if (form.value.file && form.value.file.size > MAX_UPLOAD_BYTES) {
    error.value = `File is too large (max ${Math.floor(MAX_UPLOAD_BYTES / 1024 / 1024)}MB)`
    return
  }

  saving.value = true

  const body = new FormData()
  body.append('_method', 'PUT')
  body.append('title', form.value.title)
  body.append('year', form.value.year)
  body.append('lat', form.value.lat)
  body.append('lng', form.value.lng)
  body.append('direction', String(form.value.direction))
  body.append('needs_location_review', form.value.needs_location_review ? '1' : '0')
  body.append('is_winter', form.value.is_winter ? '1' : '0')
  body.append('published', form.value.published ? '1' : '0')
  body.append('media_type', isVideo ? 'video' : 'photo')

  if (isVideo) {
    body.append('video', form.value.video)
  } else if (form.value.file) {
    body.append('file', form.value.file)
  }

  if (publishToFacebook.value && !existingFacebookPost.value) {
    body.append('publish_to_facebook', '1')
    body.append('facebook_comment', form.value.facebook_comment || '')
  }

  try {
    const updated = await api(`/admin/photos/${props.photoId}`, { method: 'POST', body })
    emit('saved', updated)
  } catch (event) {
    error.value = event.message
  } finally {
    saving.value = false
  }
}

watch([theme, currentLanguage], () => {
  if (!editMap) return
  editMapTileLayer = applyMapTileLayer(editMap, editMapTileLayer, 'google', theme.value, currentLanguage.value)
})

watch(
  () => form.value.direction,
  () => {
    if (editMarker) editMarker.setIcon(editMarkerIcon())
  },
)

watch(
  () => props.photoId,
  () => {
    loadPhoto()
  },
)

onMounted(async () => {
  try {
    const page = await api('/facebook/page')
    facebookConfigured.value = page?.configured !== false
  } catch {
    facebookConfigured.value = false
  }
  await loadPhoto()
})

onBeforeUnmount(() => {
  editMarker?.remove()
  editMarker = null
  editMap?.remove()
  editMap = null
})
</script>

<template>
  <div class="admin-photo-editor-backdrop" @click.self="emit('close')">
    <section class="admin-photo-editor panel" role="dialog" aria-modal="true">
      <header class="admin-photo-editor__head">
        <div>
          <p class="eyebrow">{{ t('adminEditPhoto') }}</p>
          <h2>#{{ photoId }}</h2>
          <p v-if="author" class="admin-photo-editor__author">
            {{ t('filterByAuthor') }}: <strong>{{ author.name || author.uid }}</strong>
            <span class="muted">({{ t('adminPhotoAuthorLocked') }})</span>
          </p>
        </div>
        <button type="button" class="admin-photo-editor__close" :aria-label="t('cancel')" @click="emit('close')">×</button>
      </header>

      <p v-if="loading" class="admin-photo-editor__status">{{ t('loading') }}</p>

      <form v-else class="admin-photo-editor__form" @submit.prevent="save">
        <label>
          <span>{{ t('title') }}</span>
          <input v-model="form.title" :placeholder="t('title')" required />
        </label>

        <div class="admin-photo-editor__map-shell">
          <div ref="editMapElement" class="admin-photo-editor__map"></div>
          <span class="admin-photo-editor__map-hint">{{ t('clickMapToSetPoint') }}</span>
        </div>

        <label class="check-line review-check">
          <input v-model="form.needs_location_review" type="checkbox" />
          <span>
            {{ t('needsLocationReview') }}
            <small>{{ t('needsLocationReviewHelp') }}</small>
          </span>
        </label>

        <label class="check-line review-check">
          <input v-model="form.is_winter" type="checkbox" />
          <span>
            {{ t('winterPhoto') }}
            <small>{{ t('winterPhotoHelp') }}</small>
          </span>
        </label>

        <label class="check-line">
          <input v-model="form.published" type="checkbox" />
          <span>{{ t('published') }}</span>
        </label>

        <div class="upload-field">
          <span class="upload-field-label">{{ t('direction') }}</span>
          <DirectionCompassPicker v-model="form.direction" />
        </div>

        <label>
          <span>{{ t('year') }}</span>
          <input v-model="form.year" inputmode="numeric" :placeholder="t('year')" required />
        </label>

        <div class="media-tabs" role="tablist">
          <button
            type="button"
            role="tab"
            :class="{ on: mediaTab === 'photo' }"
            :aria-selected="mediaTab === 'photo'"
            @click="mediaTab = 'photo'"
          >
            {{ t('mediaTabPhoto') }}
          </button>
          <button
            type="button"
            role="tab"
            :class="{ on: mediaTab === 'video' }"
            :aria-selected="mediaTab === 'video'"
            @click="mediaTab = 'video'"
          >
            {{ t('mediaTabVideo') }}
          </button>
        </div>

        <template v-if="mediaTab === 'photo'">
          <label class="file-picker">
            <input type="file" accept="image/*" @change="selectFile" />
            <span>{{ form.file?.name || t('adminReplaceImage') }}</span>
          </label>
        </template>

        <template v-else>
          <label>
            <span>{{ t('videoLink') }}</span>
            <input
              v-model="form.video"
              type="url"
              inputmode="url"
              required
              placeholder="https://www.youtube.com/watch?v=…"
            />
            <small>{{ t('videoThumbNote') }}</small>
          </label>
        </template>

        <div v-if="currentPreview" class="upload-preview-wrap">
          <img class="upload-preview" :src="currentPreview" alt="" />
          <span class="upload-preview-direction" :title="t('direction')">
            <CompassNeedle :direction="form.direction" size="md" />
          </span>
        </div>

        <template v-if="!existingFacebookPost">
          <label class="check-line">
            <input v-model="publishToFacebook" type="checkbox" />
            <span>{{ t('publishToFacebook') }}</span>
          </label>
          <p v-if="publishToFacebook && !facebookConfigured" class="facebook-config-hint">
            {{ t('facebookNotConfigured') }}
          </p>
          <textarea
            v-if="publishToFacebook"
            v-model="form.facebook_comment"
            :placeholder="t('facebookComment')"
            class="facebook-comment"
          ></textarea>
        </template>
        <p v-else-if="existingFacebookPost" class="muted-hint">
          {{ t('adminPhotoFacebookLocked') }}
        </p>

        <div class="admin-photo-editor__actions">
          <button class="button" type="submit" :disabled="saving">
            {{ saving ? t('loading') : t('save') }}
          </button>
          <button type="button" class="admin-photo-editor__cancel" @click="emit('close')">{{ t('cancel') }}</button>
        </div>

        <p v-if="error" class="error">{{ error }}</p>
      </form>
    </section>
  </div>
</template>

<style lang="scss">
.admin-photo-editor-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgba(12, 18, 32, 0.55);
  backdrop-filter: blur(4px);
}

.admin-photo-editor {
  display: flex;
  flex-direction: column;
  width: min(720px, 100%);
  max-height: calc(100vh - 40px);
  padding: 0;
  overflow: hidden;
}

.admin-photo-editor__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 20px 22px 0;

  h2 {
    margin: 4px 0 0;
    font-size: 22px;
  }
}

.admin-photo-editor__author {
  margin: 8px 0 0;
  font-size: 13px;

  .muted {
    color: $muted;
    font-weight: 400;
  }
}

.admin-photo-editor__close {
  width: 36px;
  height: 36px;
  border: 0;
  border-radius: 50%;
  background: $surface-soft;
  color: $muted;
  cursor: pointer;
  font-size: 22px;
  line-height: 1;
}

.admin-photo-editor__status {
  padding: 24px 22px;
}

.admin-photo-editor__form {
  display: grid;
  gap: 12px;
  padding: 16px 22px 24px;
  overflow-y: auto;

  label,
  .upload-field {
    display: grid;
    gap: 6px;
    color: $muted;
    font-size: 12px;
    font-weight: 500;
  }

  input:not([type='checkbox']):not([type='file']),
  textarea {
    min-height: 38px;
    padding: 8px 11px;
    border: 1px solid $line;
    border-radius: $radius-sm;
    background: $surface;
    color: $ink;
    font: inherit;
    font-size: 14px;
  }
}

.admin-photo-editor__map-shell {
  position: relative;
  overflow: hidden;
  border: 1px solid rgba($primary, 0.14);
  border-radius: 18px;
  background: $primary-light;
}

.admin-photo-editor__map {
  height: 220px;
}

.admin-photo-editor__map-hint {
  position: absolute;
  left: 12px;
  bottom: 12px;
  z-index: 500;
  padding: 7px 10px;
  border-radius: $radius-pill;
  color: $primary;
  background: #fff;
  font-size: 12px;
  font-weight: 500;
  box-shadow: 0 10px 24px rgba(23, 52, 126, 0.16);
}

.admin-photo-editor__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 4px;
}

.admin-photo-editor__cancel {
  min-height: 40px;
  padding: 0 16px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: transparent;
  color: $muted;
  cursor: pointer;
  font: inherit;
  font-size: 14px;
  font-weight: 600;
}

[data-theme='dark'] {
  .admin-photo-editor__map-hint {
    background: #161b25;
    color: #e7ebf3;
  }
}
</style>

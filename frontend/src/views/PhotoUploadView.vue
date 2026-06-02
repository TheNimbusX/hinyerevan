<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { useRouter } from 'vue-router'
import { api } from '../api'
import { useI18n } from '../i18n'
import { useTheme } from '../composables/useTheme'
import { applyMapTileLayer, getMapTileLayer } from '../utils/mapTiles'
import { getDirectionIcon } from '../utils/mapMarkerIcons'
import { setupLeaflet } from '../utils/leafletSetup'
import { isYoutubeUrl, youtubeId } from '../utils/video'
import CompassNeedle from '../components/CompassNeedle.vue'
import DirectionCompassPicker from '../components/DirectionCompassPicker.vue'

const router = useRouter()
const { t, currentLanguage } = useI18n()
const { theme } = useTheme()
const error = ref('')
const success = ref(false)
const successMessage = ref('')
const submitting = ref(false)
const previewUrl = ref('')
const mediaTab = ref('photo')
const publishToFacebook = ref(false)
const facebookConfigured = ref(true)
const uploadMapElement = ref(null)
let uploadMap
let uploadMapTileLayer
let uploadMarker

const form = ref({
  title: '',
  year: '',
  lat: '',
  lng: '',
  direction: 1,
  file: null,
  video: '',
  needs_location_review: false,
  facebook_comment: '',
})

const MAX_UPLOAD_BYTES = 10 * 1024 * 1024 // must match backend validator (max:10240 KB)

const videoThumbPreview = computed(() => {
  const id = youtubeId(form.value.video)
  return id ? `https://img.youtube.com/vi/${id}/hqdefault.jpg` : ''
})

function selectFile(event) {
  const file = event.target.files[0]
  error.value = ''
  success.value = false

  if (!file) {
    form.value.file = null
    previewUrl.value = ''
    return
  }

  if (file.size > MAX_UPLOAD_BYTES) {
    form.value.file = null
    previewUrl.value = ''
    event.target.value = ''
    error.value = `${t('chooseFile')}: max ${Math.floor(MAX_UPLOAD_BYTES / 1024 / 1024)}MB`
    return
  }

  form.value.file = file
  previewUrl.value = URL.createObjectURL(file)
}

function uploadMarkerIcon() {
  return getDirectionIcon(form.value.direction)
}

function setCoordinates(latlng) {
  form.value.lat = latlng.lat.toFixed(12)
  form.value.lng = latlng.lng.toFixed(12)
  if (!uploadMarker) {
    uploadMarker = L.marker(latlng, { icon: uploadMarkerIcon() }).addTo(uploadMap)
  } else {
    uploadMarker.setLatLng(latlng)
    uploadMarker.setIcon(uploadMarkerIcon())
  }
}

function initUploadMap() {
  if (uploadMap || !uploadMapElement.value) return
  setupLeaflet()
  const layer = getMapTileLayer('google', theme.value, currentLanguage.value)
  uploadMap = L.map(uploadMapElement.value, {
    center: [40.179136, 44.511623],
    zoom: 13,
    attributionControl: false,
    crs: layer.crs,
  })
  uploadMapTileLayer = L.tileLayer(layer.url, layer.options).addTo(uploadMap)
  uploadMap.on('click', (event) => setCoordinates(event.latlng))
  requestAnimationFrame(() => uploadMap?.invalidateSize())
}

watch([theme, currentLanguage], () => {
  if (!uploadMap) return
  uploadMapTileLayer = applyMapTileLayer(uploadMap, uploadMapTileLayer, 'google', theme.value, currentLanguage.value)
})

watch(
  () => form.value.direction,
  () => {
    if (uploadMarker) uploadMarker.setIcon(uploadMarkerIcon())
  },
)

function resetForm() {
  form.value = {
    title: '',
    year: '',
    lat: '',
    lng: '',
    direction: 1,
    file: null,
    video: '',
    needs_location_review: false,
    facebook_comment: '',
  }
  previewUrl.value = ''
  publishToFacebook.value = false
  uploadMarker?.remove()
  uploadMarker = null
}

async function submit() {
  error.value = ''
  success.value = false

  const isVideo = mediaTab.value === 'video'

  if (isVideo) {
    if (!form.value.video || !isYoutubeUrl(form.value.video)) {
      error.value = t('videoLinkHelp')
      return
    }
  } else {
    if (!form.value.file) {
      error.value = t('chooseFile')
      return
    }
    if (form.value.file.size > MAX_UPLOAD_BYTES) {
      error.value = `File is too large (max ${Math.floor(MAX_UPLOAD_BYTES / 1024 / 1024)}MB)`
      return
    }
  }

  submitting.value = true

  const body = new FormData()
  body.append('title', form.value.title)
  body.append('year', form.value.year)
  body.append('lat', form.value.lat)
  body.append('lng', form.value.lng)
  body.append('direction', form.value.direction)
  body.append('needs_location_review', form.value.needs_location_review ? '1' : '0')
  if (isVideo) {
    body.append('video', form.value.video)
  } else {
    body.append('file', form.value.file)
  }
  body.append('publish_to_facebook', publishToFacebook.value ? '1' : '0')
  if (publishToFacebook.value) {
    body.append('facebook_comment', form.value.facebook_comment || '')
  }

  try {
    const result = await api('/photos', { method: 'POST', body })
    success.value = true
    successMessage.value = result?.moderation_pending === false
      ? t('uploadPublishedSuccess')
      : t('uploadPendingSuccess')
    resetForm()
  } catch (event) {
    error.value = event.message
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await nextTick()
  initUploadMap()
  try {
    const page = await api('/facebook/page')
    facebookConfigured.value = page?.configured !== false
  } catch {
    facebookConfigured.value = false
  }
})

onBeforeUnmount(() => {
  uploadMap?.remove()
  uploadMap = null
})
</script>

<template>
  <div class="auth-modal-backdrop upload-backdrop">
    <section class="auth-modal upload-modal">
      <RouterLink class="auth-close" to="/" :aria-label="t('cancel')" />

      <div class="upload-modal-scroll">
        <header class="upload-modal-head">
          <p class="eyebrow">{{ t('upload') }}</p>
          <h1>{{ t('addHistoricPhoto') }}</h1>
          <p class="muted">{{ t('mapCoordinateHint') }}</p>
        </header>

        <form class="stack-form upload-form" @submit.prevent="submit">
          <label>
            <span>{{ t('title') }}</span>
            <input v-model="form.title" :placeholder="t('title')" required />
          </label>
          <div class="form-two">
            <label>
              <span>{{ t('latitude') }}</span>
              <input v-model="form.lat" inputmode="decimal" :placeholder="t('latitude')" required />
            </label>
            <label>
              <span>{{ t('longitude') }}</span>
              <input v-model="form.lng" inputmode="decimal" :placeholder="t('longitude')" required />
            </label>
          </div>
          <div class="upload-map-shell">
            <div ref="uploadMapElement" class="upload-map"></div>
            <span class="upload-map-hint">{{ t('clickMapToSetPoint') }}</span>
          </div>
          <label class="check-line review-check">
            <input v-model="form.needs_location_review" type="checkbox" />
            <span>
              {{ t('needsLocationReview') }}
              <small>{{ t('needsLocationReviewHelp') }}</small>
            </span>
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
              <input type="file" accept="image/*" required @change="selectFile" />
              <span>{{ form.file?.name || t('chooseFile') }}</span>
            </label>
            <div v-if="previewUrl" class="upload-preview-wrap">
              <img class="upload-preview" :src="previewUrl" alt="" />
              <span class="upload-preview-direction" :title="t('direction')">
                <CompassNeedle :direction="form.direction" size="md" />
              </span>
            </div>
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
            <div v-if="videoThumbPreview" class="upload-preview-wrap">
              <img class="upload-preview" :src="videoThumbPreview" alt="" />
              <span class="upload-preview-direction" :title="t('direction')">
                <CompassNeedle :direction="form.direction" size="md" />
              </span>
            </div>
          </template>

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
          <button class="button" type="submit" :disabled="submitting">
            {{ submitting ? t('loading') : t('uploadForModeration') }}
          </button>
          <p v-if="success" class="upload-success">{{ successMessage || t('uploadPendingSuccess') }}</p>
          <p v-if="error" class="error">{{ error }}</p>
        </form>
      </div>
    </section>
  </div>
</template>

<style lang="scss">
.upload-success {
  margin: 0;
  padding: 12px 14px;
  border-radius: $radius-sm;
  color: darken($accent, 14%);
  background: rgba($accent, 0.12);
  font-weight: 600;
  line-height: 1.5;
}

.facebook-config-hint {
  margin: -4px 0 8px;
  padding: 10px 12px;
  border-radius: $radius-sm;
  color: darken($accent, 10%);
  background: rgba($accent, 0.1);
  font-size: 13px;
  line-height: 1.5;
}

.upload-backdrop {
  position: fixed;
}

.upload-modal {
  display: flex;
  flex-direction: column;
  width: min(720px, 100%);
  max-height: calc(100vh - 42px);
  padding: 0;
  overflow: hidden;

  @include mq-down($bp-sm) {
    max-height: calc(100vh - 28px);
  }
}

.upload-modal-scroll {
  flex: 1;
  min-height: 0;
  padding: 26px 22px 28px;
  overflow-x: hidden;
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-gutter: stable;

  &::-webkit-scrollbar {
    width: 6px;
  }

  &::-webkit-scrollbar-track {
    margin: 8px 0;
    background: transparent;
  }

  &::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: rgba($ink, 0.18);
  }

  @include mq-down($bp-sm) {
    padding: 22px 16px 24px;
  }
}

.upload-modal-head {
  padding-right: 44px;

  h1 {
    margin-bottom: 6px;
  }

  .muted {
    margin: 0 0 4px;
    color: $muted;
    font-size: 13px;
    line-height: 1.45;
  }
}

.upload-form label,
.upload-field {
  display: grid;
  gap: 6px;
  color: $muted;
  font-size: 12px;
  font-weight: 500;
}

.upload-field-label {
  color: $muted;
  font-size: 12px;
  font-weight: 500;
}

.upload-map-shell {
  position: relative;
  overflow: hidden;
  border: 1px solid rgba($primary, 0.14);
  border-radius: 18px;
  background: $primary-light;
}

.upload-map {
  height: 240px;
}

.upload-map-hint {
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

.upload-preview-wrap {
  position: relative;
  display: block;
  width: fit-content;
  max-width: 100%;
}

.upload-preview {
  display: block;
  max-width: 100%;
  max-height: 180px;
  border-radius: 18px;
  object-fit: contain;
  background: $primary-light;
}

.upload-preview-direction {
  position: absolute;
  right: 10px;
  bottom: 10px;
  display: grid;
  place-items: center;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.94);
  box-shadow: 0 8px 22px rgba(23, 52, 126, 0.22);
  pointer-events: none;
}

.review-check {
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border: 1px solid rgba($accent, 0.28);
  border-radius: $radius-md;
  background: rgba($accent, 0.06);

  input {
    margin-top: 2px;
  }

  span {
    display: grid;
    gap: 3px;
    color: $ink;
    font-size: 13px;
    font-weight: 600;
  }

  small {
    color: $muted;
    font-size: 11px;
    font-weight: 400;
    line-height: 1.35;
  }
}

.media-tabs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px;
  padding: 4px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface-soft;

  button {
    padding: 9px 12px;
    border: 0;
    border-radius: $radius-pill;
    background: transparent;
    color: $muted;
    cursor: pointer;
    font: inherit;
    font-size: 13px;
    font-weight: 600;
    @include interactive((background, color, box-shadow));

    &:hover {
      color: $ink;
    }

    &.on {
      color: #fff;
      background: linear-gradient(135deg, $primary, $primary-dark);
      box-shadow: 0 6px 14px rgba($primary, 0.26);
    }

    @include focus-ring(rgba($primary, 0.42), 2px);
  }
}

[data-theme='dark'] {
  .upload-modal-scroll {
    &::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.22);
    }
  }

  .upload-map-hint {
    background: #161b25;
    color: #e7ebf3;
  }
}
</style>

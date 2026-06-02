<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { useRoute } from 'vue-router'
import { api, getToken, imageUrl, localizedApi, safeAvatarUrl } from '../api'
import { useI18n } from '../i18n'
import { useTheme } from '../composables/useTheme'
import { useLanguageReload, useLocalizedReady } from '../composables/useLanguageReload'
import { applyMapTileLayer, getMapTileLayer } from '../utils/mapTiles'
import { getDirectionIcon } from '../utils/mapMarkerIcons'
import { setupLeaflet } from '../utils/leafletSetup'
import { directionLabel, formatDateTime } from '../utils/locale'
import { formatCommentBody } from '../utils/commentBody'
import { userDisplayName, userProfilePath } from '../utils/user'
import { setPageMeta } from '../utils/seo'
import DirectionMarker from '../components/DirectionMarker.vue'
import LikeIcon from '../components/LikeIcon.vue'
import YoutubeEmbed from '../components/YoutubeEmbed.vue'

const route = useRoute()
const photo = ref(null)
const detailImageSrc = ref('')
const comment = ref('')
const error = ref('')
const loading = ref(true)
const isFavorite = ref(false)
const favoritePending = ref(false)
const shareNotice = ref('')
const lightboxOpen = ref(false)
const { t, currentLanguage } = useI18n()
const { theme } = useTheme()
const miniMapElement = ref(null)
let miniMap
let miniMapTileLayer

const isAuthenticated = computed(() => Boolean(getToken()))

const photoDirectionLabel = computed(() =>
  photo.value ? directionLabel(photo.value.direction, t) : '',
)

const addedLabel = computed(() =>
  photo.value ? formatDateTime(photo.value.datetime, currentLanguage.value) : '',
)

function openLightbox() {
  lightboxOpen.value = true
  document.body.style.overflow = 'hidden'
}

function closeLightbox() {
  lightboxOpen.value = false
  document.body.style.overflow = ''
}

function handleLightboxKey(event) {
  if (event.key === 'Escape' && lightboxOpen.value) {
    closeLightbox()
  }
}

function authorAvatar(author) {
  return safeAvatarUrl(author?.photo)
}

async function load({ soft = false } = {}) {
  if (!soft) {
    loading.value = true
    error.value = ''
    photo.value = null
    destroyMiniMap()
  }

  try {
    const data = await localizedApi(`/photos/${route.params.id}`)
    photo.value = data
    isFavorite.value = Boolean(photo.value?.is_favorite)
    detailImageSrc.value = imageUrl(photo.value.images.large || photo.value.images.original || photo.value.images.thumb)
    setPageMeta({
      title: photo.value.title,
      description: `${photo.value.year} — ${photo.value.title}`,
      image: imageUrl(photo.value.images.large || photo.value.images.thumb),
      path: route.fullPath,
      type: 'article',
    })
  } catch (event) {
    if (!soft) {
      photo.value = null
      error.value = event?.message || t('loading')
    }
  } finally {
    loading.value = false
  }

  if (photo.value) {
    await nextTick()
    if (!miniMap) {
      initMiniMap()
    }
  }
}

async function applyLocalized({ path }) {
  const photoPath = `/photos/${route.params.id}`
  if (path !== photoPath) return
  photo.value = await localizedApi(photoPath)
  setPageMeta({
    title: photo.value.title,
    description: `${photo.value.year} — ${photo.value.title}`,
    image: imageUrl(photo.value.images.large || photo.value.images.thumb),
    path: route.fullPath,
    type: 'article',
  })
}

function fallbackToThumb() {
  const thumb = imageUrl(photo.value?.images?.thumb)
  if (thumb && detailImageSrc.value !== thumb) {
    detailImageSrc.value = thumb
  }
}

function formatCoords(lat, lng) {
  const la = Number(lat)
  const ln = Number(lng)
  if (!Number.isFinite(la) || !Number.isFinite(ln)) return ''
  return `${la.toFixed(6)}, ${ln.toFixed(6)}`
}

function initMiniMap() {
  if (!photo.value || miniMap || !miniMapElement.value) return

  const lat = Number(photo.value.lat)
  const lng = Number(photo.value.lng)
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return

  const position = [lat, lng]
  const layer = getMapTileLayer('google', theme.value, currentLanguage.value)

  miniMap = L.map(miniMapElement.value, {
    center: position,
    zoom: 15,
    zoomControl: false,
    dragging: false,
    scrollWheelZoom: false,
    doubleClickZoom: false,
    attributionControl: false,
    crs: layer.crs,
  })

  setupLeaflet()
  miniMapTileLayer = L.tileLayer(layer.url, layer.options).addTo(miniMap)
  L.marker(position, { icon: getDirectionIcon(photo.value.direction) }).addTo(miniMap)

  miniMap.whenReady(() => {
    miniMap.invalidateSize()
    setTimeout(() => miniMap?.invalidateSize(), 150)
  })
}

function destroyMiniMap() {
  miniMap?.remove()
  miniMap = null
  miniMapTileLayer = null
}

async function toggleFavorite() {
  if (!isAuthenticated.value) {
    window.dispatchEvent(new CustomEvent('hinyerevan:open-auth'))
    return
  }
  if (favoritePending.value || !photo.value) return

  favoritePending.value = true
  const previous = isFavorite.value
  isFavorite.value = !previous

  if (photo.value.likes_count !== undefined) {
    photo.value.likes_count = Math.max(0, (photo.value.likes_count || 0) + (previous ? -1 : 1))
  }

  try {
    if (previous) {
      await api(`/photos/${photo.value.id}/favorite`, { method: 'DELETE' })
    } else {
      await api(`/photos/${photo.value.id}/favorite`, { method: 'POST' })
    }
  } catch (e) {
    isFavorite.value = previous
    if (photo.value.likes_count !== undefined) {
      photo.value.likes_count = Math.max(0, (photo.value.likes_count || 0) + (previous ? 1 : -1))
    }
    error.value = e.message
  } finally {
    favoritePending.value = false
  }
}

async function sharePhoto() {
  if (!photo.value) return

  const payload = {
    title: photo.value.title,
    text: `${photo.value.title} · ${photo.value.year}`,
    url: window.location.href,
  }

  try {
    if (navigator.share) {
      await navigator.share(payload)
      return
    }
    if (navigator.clipboard) {
      await navigator.clipboard.writeText(payload.url)
      shareNotice.value = t('linkCopied')
      setTimeout(() => (shareNotice.value = ''), 2400)
    }
  } catch {
    // user cancelled
  }
}

onMounted(() => {
  load()
  window.addEventListener('keydown', handleLightboxKey)
})

onBeforeUnmount(() => {
  destroyMiniMap()
  document.body.style.overflow = ''
  window.removeEventListener('keydown', handleLightboxKey)
})

watch(() => route.params.id, () => {
  closeLightbox()
  load()
})

useLanguageReload(() => load({ soft: true }))
useLocalizedReady(applyLocalized)

watch([theme, currentLanguage], () => {
  if (!miniMap) return
  miniMapTileLayer = applyMapTileLayer(miniMap, miniMapTileLayer, 'google', theme.value, currentLanguage.value)
  requestAnimationFrame(() => miniMap?.invalidateSize())
})

function promptLogin() {
  window.dispatchEvent(new CustomEvent('hinyerevan:open-auth', { detail: { mode: 'login' } }))
}

async function submitComment() {
  error.value = ''
  if (!isAuthenticated.value) {
    promptLogin()
    return
  }
  try {
    await api(`/photos/${route.params.id}/comments`, {
      method: 'POST',
      body: { body: comment.value },
    })
    comment.value = ''
    await load()
  } catch (event) {
    if (event.status === 401) {
      promptLogin()
      return
    }
    error.value = event.message
  }
}
</script>

<template>
  <div v-if="loading" class="panel photo-detail-loading">{{ t('loading') }}</div>
  <div v-else-if="error && !photo" class="panel photo-detail-error">
    <p class="error">{{ error }}</p>
  </div>

  <section v-else-if="photo" class="detail-layout">
    <article class="photo-detail panel">
      <div class="photo-detail-frame">
        <button
          type="button"
          class="photo-detail-image"
          :aria-label="t('openFullscreen')"
          @click="openLightbox"
        >
          <img :src="detailImageSrc" :alt="photo.title" @error="fallbackToThumb" />
          <span class="photo-detail-expand" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20">
              <path
                d="M4 9V5a1 1 0 0 1 1-1h4M20 9V5a1 1 0 0 0-1-1h-4M4 15v4a1 1 0 0 0 1 1h4M20 15v4a1 1 0 0 1-1 1h-4"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
              />
            </svg>
          </span>
        </button>
        <div class="photo-detail-actions">
          <button
            type="button"
            class="action-chip"
            :class="{ liked: isFavorite }"
            :disabled="favoritePending"
            :aria-pressed="isFavorite"
            :aria-label="isFavorite ? t('unlike') : t('like')"
            @click="toggleFavorite"
          >
            <LikeIcon :filled="isFavorite" />
            <span class="action-label">{{ isFavorite ? t('unlike') : t('like') }}</span>
            <span v-if="photo.likes_count > 0" class="action-count">{{ photo.likes_count }}</span>
          </button>
          <button
            type="button"
            class="action-chip"
            :aria-label="t('sharePhoto')"
            @click="sharePhoto"
          >
            <span class="action-icon" aria-hidden="true">↗</span>
            <span class="action-label">{{ t('sharePhoto') }}</span>
          </button>
        </div>
        <transition name="fade">
          <span v-if="shareNotice" class="share-notice">{{ shareNotice }}</span>
        </transition>
      </div>

      <div class="photo-detail-meta">
        <p class="eyebrow">{{ photo.year }} / {{ photoDirectionLabel }}</p>
        <h1>{{ photo.title }}</h1>
        <p class="direction-line">
          <DirectionMarker :direction="photo.direction" :label="photoDirectionLabel" />
          {{ photoDirectionLabel }}
        </p>
        <p v-if="addedLabel" class="detail-added">
          <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
            <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.6" />
            <path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <span>{{ t('addedOn') }}: {{ addedLabel }}</span>
        </p>
        <div class="detail-stats">
          <span class="like-pill"><LikeIcon /> {{ photo.likes_count || 0 }} {{ t('likes') }}</span>
          <span>{{ photo.views }} {{ t('views') }}</span>
          <span>{{ photo.comments_count }} {{ t('comments') }}</span>
        </div>
        <div v-if="photo.facebook?.post_url" class="facebook-post-stats">
          <p class="facebook-post-stats__label">{{ t('facebookPostStats') }}</p>
          <p class="facebook-post-stats__counts">
            {{ photo.facebook.likes || 0 }} {{ t('likes') }},
            {{ photo.facebook.comments_count || 0 }} {{ t('comments') }}
          </p>
          <a
            class="facebook-post-stats__link"
            :href="photo.facebook.post_url"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ t('facebookOpenPost') }}
          </a>
        </div>
      </div>
    </article>

    <aside class="detail-side">
      <RouterLink
        v-if="photo.author"
        class="panel author-card"
        :to="`/users/${photo.author.unique || photo.author.uid}`"
      >
        <img class="author-card-avatar" :src="authorAvatar(photo.author)" :alt="photo.author.name || photo.author.uid" />
        <div class="author-card-body">
          <p class="eyebrow">{{ t('photographer') }}</p>
          <strong>{{ photo.author.name || photo.author.uid }}</strong>
          <small v-if="photo.author_stats">
            {{ photo.author_stats.photos_count }} {{ t('photosCount') }} ·
            {{ photo.author_stats.views_total.toLocaleString() }} {{ t('views') }}
          </small>
        </div>
        <span class="author-card-arrow" aria-hidden="true">→</span>
      </RouterLink>

      <article class="panel">
        <h2>{{ t('location') }}</h2>
        <p class="location-coords">{{ formatCoords(photo.lat, photo.lng) }}</p>
        <div ref="miniMapElement" class="mini-map" />
      </article>
    </aside>
  </section>

  <section v-if="photo?.video" class="panel detail-video-block">
    <h2>{{ t('watchVideo') }}</h2>
    <YoutubeEmbed :url="photo.video" :title="photo.title" />
  </section>

  <section v-if="photo?.nearby_photos?.length" class="panel related-block">
    <header class="related-head">
      <h2>{{ t('nearbyPhotos') }}</h2>
    </header>
    <div class="related-grid">
      <RouterLink
        v-for="item in photo.nearby_photos"
        :key="item.id"
        class="related-card"
        :to="`/photos/${item.id}`"
      >
        <img :src="imageUrl(item.images.thumb)" :alt="item.title" />
        <span class="related-year">{{ item.year }}</span>
        <strong>{{ item.title }}</strong>
      </RouterLink>
    </div>
  </section>

  <section v-if="photo?.author_other_photos?.length" class="panel related-block">
    <header class="related-head">
      <h2>{{ t('otherByAuthor') }}</h2>
      <RouterLink
        v-if="photo.author"
        class="link-button"
        :to="`/users/${photo.author.unique || photo.author.uid}`"
      >
        {{ t('viewProfile') }} →
      </RouterLink>
    </header>
    <div class="related-grid">
      <RouterLink
        v-for="item in photo.author_other_photos"
        :key="item.id"
        class="related-card"
        :to="`/photos/${item.id}`"
      >
        <img :src="imageUrl(item.images.thumb)" :alt="item.title" />
        <span class="related-year">{{ item.year }}</span>
        <strong>{{ item.title }}</strong>
        <small>{{ item.views }} {{ t('views') }}</small>
      </RouterLink>
    </div>
  </section>

  <section v-if="photo" class="panel">
    <h2>{{ t('comments') }}</h2>
    <form v-if="isAuthenticated" class="comment-form" @submit.prevent="submitComment">
      <textarea v-model="comment" :placeholder="t('writeComment')" required />
      <button class="button" type="submit">{{ t('postComment') }}</button>
      <p v-if="error" class="error">{{ error }}</p>
    </form>
    <button v-else class="button comment-login-prompt" type="button" @click="promptLogin">
      {{ t('loginToComment') }}
    </button>
    <div v-for="item in photo.comments || []" :key="item.id" class="comment">
      <img class="comment-avatar" :src="authorAvatar(item.author)" :alt="userDisplayName(item.author, t)" />
      <span>
        <RouterLink class="comment-author" :to="userProfilePath(item.author)">
          {{ userDisplayName(item.author, t) }}
        </RouterLink>
        <p class="comment-body">{{ formatCommentBody(item.body) }}</p>
      </span>
    </div>
  </section>

  <Teleport to="body">
    <transition name="lightbox">
      <div
        v-if="lightboxOpen && photo"
        class="photo-lightbox"
        @click.self="closeLightbox"
      >
        <button class="lightbox-close" type="button" :aria-label="t('cancel')" @click="closeLightbox">×</button>
        <figure class="lightbox-figure">
          <img :src="detailImageSrc" :alt="photo.title" />
          <figcaption>
            <strong>{{ photo.title }}</strong>
            <span>{{ photo.year }}<template v-if="photo.author"> · {{ photo.author.name || photo.author.uid }}</template></span>
          </figcaption>
        </figure>
      </div>
    </transition>
  </Teleport>
</template>

<style lang="scss">
.photo-detail {
  display: grid;
  gap: 18px;
}

.photo-detail-frame {
  position: relative;
}

.photo-detail-image {
  position: relative;
  display: block;
  width: 100%;
  padding: 0;
  border: 0;
  border-radius: $radius-lg;
  overflow: hidden;
  background: $surface-soft;
  cursor: zoom-in;
  @include interactive((transform, box-shadow));

  @include mq-down($bp-sm) {
    border-radius: $radius-md;
  }

  img {
    width: 100%;
    max-height: 640px;
    object-fit: contain;
    background: $surface-soft;
    @include interactive((transform));

    @include mq-down($bp-sm) {
      max-height: min(56vh, 420px);
    }
  }

  &:hover img {
    transform: scale(1.02);
  }

  &:hover .photo-detail-expand {
    opacity: 1;
    transform: translateY(0);
  }

  @include focus-ring(rgba($primary, 0.45), 3px);
}

.photo-detail-expand {
  position: absolute;
  left: 14px;
  bottom: 14px;
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: rgba(20, 24, 34, 0.78);
  color: #fff;
  backdrop-filter: blur(8px);
  opacity: 0;
  transform: translateY(4px);
  pointer-events: none;
  transition:
    opacity $duration ease,
    transform $duration ease;
}

.photo-detail-actions {
  position: absolute;
  top: 14px;
  right: 14px;
  display: flex;
  gap: 8px;

  @include mq-down($bp-sm) {
    top: 10px;
    right: 10px;
    gap: 6px;
  }
}

.action-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  border: 0;
  border-radius: $radius-pill;
  background: rgba(255, 255, 255, 0.94);
  color: $primary-dark;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  box-shadow: 0 8px 22px rgba(7, 21, 60, 0.16);
  backdrop-filter: blur(8px);
  @include interactive((background, color, transform, box-shadow));

  &:hover {
    color: #fff;
    background: $primary;
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba($primary, 0.34);
  }

  &.liked {
    color: #fff;
    background: linear-gradient(135deg, #2374e1, #1457b3);
    box-shadow: 0 12px 24px rgba(35, 116, 225, 0.36);

    &:hover {
      background: linear-gradient(135deg, #1c64c8, #11489a);
    }

    .like-icon {
      color: #fff;
    }
  }

  @include focus-ring-inset(rgba($primary, 0.45));
  @include disabled;
}

.action-icon {
  font-size: 16px;
  line-height: 1;
}

.action-label {
  @include mq-down($bp-sm) {
    display: none;
  }
}

.share-notice {
  position: absolute;
  left: 14px;
  bottom: 14px;
  padding: 6px 12px;
  border-radius: $radius-pill;
  background: rgba(8, 22, 60, 0.78);
  color: #fff;
  font-size: 12px;
  font-weight: 500;
}

.photo-detail-meta {
  display: grid;
  gap: 8px;

  h1 {
    margin-bottom: 4px;
    font-size: clamp(24px, 3.4vw, 36px);
  }
}

.direction-line {
  display: flex;
  align-items: center;
  gap: 10px;
  color: $primary;
  font-weight: 500;
}

.detail-added {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: 0;
  color: $muted;
  font-size: 13px;
  font-weight: 500;

  svg {
    flex-shrink: 0;
    opacity: 0.8;
  }
}

.detail-video-block {
  display: grid;
  gap: 14px;

  h2 {
    margin: 0;
    font-size: 18px;
  }
}

.detail-stats {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 14px;
  color: $muted;
  font-size: 13px;
  font-weight: 500;

  @include mq-down($bp-sm) {
    gap: 8px 12px;
    font-size: 12px;
  }

  .like-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #2374e1;
    font-size: 14px;
    font-weight: 600;

    .like-icon {
      font-size: 16px;
    }
  }
}

.facebook-post-stats {
  margin-top: 12px;
  padding: 12px 14px;
  border-radius: $radius-sm;
  background: rgba(24, 119, 242, 0.08);
  border: 1px solid rgba(24, 119, 242, 0.18);

  &__label {
    margin: 0 0 4px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #1877f2;
  }

  &__counts {
    margin: 0;
    color: $ink;
    font-size: 14px;
    font-weight: 500;
  }

  &__link {
    display: inline-block;
    margin-top: 8px;
    color: #1877f2;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;

    &:hover {
      text-decoration: underline;
    }
  }
}

// ---------- Side column ----------------------------------------
.detail-side {
  display: grid;
  gap: 18px;
  align-content: start;
}

.author-card {
  display: grid;
  grid-template-columns: 56px 1fr auto;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  text-decoration: none;
  color: inherit;
  @include hover-lift(-2px, $shadow-lg);

  @include mq-down($bp-sm) {
    grid-template-columns: 48px 1fr auto;
    gap: 10px;
    padding: 12px 14px;

    &-avatar {
      width: 48px;
      height: 48px;
    }
  }

  &-avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    object-fit: cover;
    background: $primary-light;
    box-shadow: 0 8px 18px rgba($primary, 0.16);
  }

  &-body {
    display: grid;
    gap: 2px;
    min-width: 0;

    .eyebrow {
      margin: 0;
      font-size: 10px;
    }

    strong {
      font-size: 15px;
      font-weight: 600;
      color: $ink;
      @include truncate;
    }

    small {
      color: $muted;
      font-size: 11px;
    }
  }

  &-arrow {
    color: $primary;
    font-size: 22px;
    transition: transform $duration $ease;
  }

  &:hover &-arrow {
    transform: translateX(4px);
  }

  @include focus-ring(rgba($primary, 0.45), 3px);
}

.location-coords {
  margin: 0 0 10px;
  color: $muted;
  font-family: ui-monospace, SFMono-Regular, monospace;
  font-size: 12px;
}

.mini-map {
  width: 100%;
  min-height: 220px;
  border-radius: $radius-xl - 4;
  overflow: hidden;
  background: #e8eef5;

  :deep(.leaflet-container) {
    width: 100%;
    height: 100%;
    min-height: 220px;
    font-family: inherit;
  }
}

// ---------- Related grids --------------------------------------
.related-block {
  display: grid;
  gap: 14px;
}

.related-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  h2 {
    margin: 0;
    font-size: 18px;
  }
}

.related-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 12px;

  @include mq-down($bp-sm) {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
  }

  @include mq-down(360px) {
    grid-template-columns: 1fr;
  }
}

.related-card {
  position: relative;
  display: grid;
  padding: 0;
  border-radius: $radius-sm + 2;
  overflow: hidden;
  background: $surface-soft;
  color: inherit;
  text-decoration: none;
  box-shadow: $shadow-sm;
  @include hover-lift(-3px, 0 16px 30px rgba(20, 45, 110, 0.2));

  &:focus-visible {
    outline: 2px solid rgba($primary, 0.45);
    outline-offset: 2px;
  }

  img {
    display: block;
    width: 100%;
    height: 140px;
    object-fit: cover;
    @include interactive((transform));
  }

  &:hover img {
    transform: scale(1.05);
  }

  strong {
    display: block;
    padding: 8px 12px 0;
    font-size: 13px;
    font-weight: 500;
    line-height: 1.3;
    @include truncate;
  }

  small {
    display: block;
    padding: 2px 12px 12px;
    color: $muted;
    font-size: 11px;
  }
}

.related-year {
  position: absolute;
  top: 8px;
  left: 8px;
  padding: 3px 9px;
  border-radius: $radius-pill;
  background: rgba(8, 22, 60, 0.62);
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  backdrop-filter: blur(4px);
}

// ---------- Comments --------------------------------------------
.comment-login-prompt {
  margin-bottom: 1em;
}

.comment {
  display: grid;
  grid-template-columns: 42px minmax(0, 1fr);
  gap: 12px;
  padding: 14px 0;
  border-top: 1px solid $line;
}

.comment-avatar {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  object-fit: cover;
  background: $primary-light;
}

.comment-author {
  display: inline-block;
  color: $primary;
  font-weight: 600;
  font-size: 13px;
  text-decoration: none;
  @include interactive((color));

  &:hover {
    color: $primary-dark;
    text-decoration: underline;
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.comment p,
.comment-body {
  margin: 4px 0 0;
  font-size: 14px;
  line-height: 1.55;
  white-space: pre-line;
}

.action-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  padding: 0 6px;
  border-radius: $radius-pill;
  background: rgba(255, 255, 255, 0.35);
  color: inherit;
  font-size: 11px;
  font-variant-numeric: tabular-nums;
}

.action-chip.liked .action-count {
  background: rgba(255, 255, 255, 0.18);
}

// ---------- Transitions -----------------------------------------
.fade-enter-active,
.fade-leave-active {
  transition: opacity $duration ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

// ---------- Lightbox --------------------------------------------
.photo-lightbox {
  position: fixed;
  inset: 0;
  z-index: 1100;
  display: grid;
  place-items: center;
  padding: 32px;
  background: rgba(8, 11, 18, 0.92);
  backdrop-filter: blur(8px);
  cursor: zoom-out;

  @include mq-down($bp-sm) {
    padding: 14px;
  }
}

.lightbox-close {
  position: absolute;
  top: 18px;
  right: 18px;
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border: 0;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  cursor: pointer;
  font-size: 28px;
  line-height: 0;
  @include interactive((background, transform));

  &:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: rotate(90deg);
  }

  @include focus-ring(rgba(255, 255, 255, 0.55), 2px);
}

.lightbox-figure {
  display: grid;
  gap: 14px;
  max-width: min(1400px, 100%);
  max-height: 100%;
  margin: 0;
  cursor: default;

  @include mq-down($bp-sm) {
    gap: 10px;
  }

  img {
    max-width: 100%;
    max-height: calc(100vh - 140px);
    object-fit: contain;
    border-radius: $radius-md;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.6);

    @include mq-down($bp-sm) {
      max-height: calc(100vh - 110px);
      border-radius: $radius-sm;
    }
  }

  figcaption {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: baseline;
    justify-content: center;
    color: rgba(255, 255, 255, 0.92);

    strong {
      font-family: $font-serif;
      font-weight: 500;
      font-size: 18px;
    }

    span {
      color: rgba(255, 255, 255, 0.6);
      font-size: 13px;
    }
  }
}

.lightbox-enter-active,
.lightbox-leave-active {
  transition:
    opacity $duration ease,
    transform $duration ease;
}

.lightbox-enter-from,
.lightbox-leave-to {
  opacity: 0;
}

.lightbox-enter-from .lightbox-figure,
.lightbox-leave-to .lightbox-figure {
  transform: scale(0.96);
}

.lightbox-enter-active .lightbox-figure,
.lightbox-leave-active .lightbox-figure {
  transition: transform $duration $ease;
}
</style>

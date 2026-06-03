<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { api, getToken, imageUrl, localizedApi, safeAvatarUrl } from '../api'
import { useI18n } from '../i18n'
import { useLanguageReload, useLocalizedReady } from '../composables/useLanguageReload'
import { directionLabel, formatDateTime } from '../utils/locale'
import { formatCommentBody } from '../utils/commentBody'
import { userDisplayName, userProfilePath } from '../utils/user'
import DirectionMarker from './DirectionMarker.vue'
import LikeIcon from './LikeIcon.vue'
import YoutubeEmbed from './YoutubeEmbed.vue'

const props = defineProps({
  photoId: { type: [Number, String, null], default: null },
})
const emit = defineEmits(['close', 'navigate'])

const { t, currentLanguage } = useI18n()
const photo = ref(null)
const loading = ref(false)
const error = ref('')
const comment = ref('')
const commentError = ref('')
const isFavorite = ref(false)
const favoritePending = ref(false)
const shareNotice = ref('')
const lightboxOpen = ref(false)
const detailImageSrc = ref('')

const open = computed(() => props.photoId != null)
const isAuthenticated = computed(() => Boolean(getToken()))
const photoDirectionLabel = computed(() => (photo.value ? directionLabel(photo.value.direction, t) : ''))
const addedLabel = computed(() => (photo.value ? formatDateTime(photo.value.datetime, currentLanguage.value) : ''))

async function loadTranslatedComments(id) {
  if (currentLanguage.value === 'hy' || !photo.value) return
  const commentsPath = `/photos/${id}/comments`
  try {
    const comments = await localizedApi(commentsPath, { ttl: 30 * 60 * 1000 })
    if (photo.value) {
      photo.value = { ...photo.value, comments }
    }
  } catch {
    // keep Armenian comments on failure
  }
}

async function load(id, { soft = false } = {}) {
  if (!soft) {
    loading.value = true
    error.value = ''
    photo.value = null
    lightboxOpen.value = false
    comment.value = ''
    commentError.value = ''
  }
  try {
    const data = await localizedApi(`/photos/${id}`, { ttl: 30 * 60 * 1000 })
    photo.value = data
    isFavorite.value = Boolean(data?.is_favorite)
    detailImageSrc.value = imageUrl(data.images.large || data.images.original || data.images.thumb)
    void loadTranslatedComments(id)
  } catch (e) {
    if (!soft) {
      error.value = e?.message || t('loading')
    }
  } finally {
    loading.value = false
  }
}

async function applyLocalized({ path }) {
  const id = props.photoId
  if (id == null) return
  const photoPath = `/photos/${id}`
  const commentsPath = `/photos/${id}/comments`
  if (path === photoPath) {
    photo.value = await localizedApi(photoPath, { ttl: 30 * 60 * 1000 })
    void loadTranslatedComments(id)
    return
  }
  if (path === commentsPath && photo.value) {
    photo.value = {
      ...photo.value,
      comments: await localizedApi(commentsPath, { ttl: 30 * 60 * 1000 }),
    }
  }
}

useLanguageReload(() => {
  if (props.photoId != null) load(props.photoId, { soft: true })
})

useLocalizedReady(applyLocalized)

watch(
  () => props.photoId,
  (id) => {
    if (id != null) load(id)
  },
  { immediate: true },
)

function close() {
  lightboxOpen.value = false
  emit('close')
}

function navigate(id) {
  emit('navigate', id)
}

function authorAvatar(author) {
  return safeAvatarUrl(author?.photo)
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

function openLightbox() {
  lightboxOpen.value = true
}

function closeLightbox() {
  lightboxOpen.value = false
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
    await api(`/photos/${photo.value.id}/favorite`, { method: previous ? 'DELETE' : 'POST' })
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
  const url = `${window.location.origin}/photos/${photo.value.id}`
  const payload = {
    title: photo.value.title,
    text: `${photo.value.title} · ${photo.value.year}`,
    url,
  }
  try {
    if (navigator.share) {
      await navigator.share(payload)
      return
    }
    if (navigator.clipboard) {
      await navigator.clipboard.writeText(url)
      shareNotice.value = t('linkCopied')
      setTimeout(() => (shareNotice.value = ''), 2400)
    }
  } catch {
    // user cancelled
  }
}

async function submitComment() {
  commentError.value = ''
  if (!photo.value) return
  try {
    await api(`/photos/${photo.value.id}/comments`, {
      method: 'POST',
      body: { body: comment.value },
    })
    comment.value = ''
    await load(photo.value.id)
  } catch (e) {
    commentError.value = e.message
  }
}

function handleKey(event) {
  if (event.key !== 'Escape') return
  if (lightboxOpen.value) {
    closeLightbox()
  } else if (open.value) {
    close()
  }
}

watch(open, (value) => {
  if (value) {
    window.addEventListener('keydown', handleKey)
  } else {
    window.removeEventListener('keydown', handleKey)
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleKey)
})
</script>

<template>
  <Teleport to="body">
    <transition name="sheet-slide">
      <section v-if="open" class="photo-sheet" role="dialog" aria-modal="false" :aria-label="photo?.title || t('loading')">
        <header class="photo-sheet-bar">
          <span class="photo-sheet-grip" aria-hidden="true"></span>
          <div class="photo-sheet-bar-actions">
            <RouterLink v-if="photo" class="photo-sheet-openfull" :to="`/photos/${photo.id}`">
              {{ t('openFullPage') }}
            </RouterLink>
            <button type="button" class="photo-sheet-hide" :aria-label="t('hidePanel')" @click="close">
              <span>{{ t('hidePanel') }}</span>
              <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                <path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </button>
          </div>
        </header>

        <div class="photo-sheet-scroll">
          <div v-if="loading" class="photo-sheet-state">
            <span class="photo-sheet-spinner" aria-hidden="true"></span>
            {{ t('loading') }}
          </div>
          <div v-else-if="error" class="photo-sheet-state error">{{ error }}</div>

          <template v-else-if="photo">
            <div class="sheet-grid">
              <div class="sheet-main">
                <div class="photo-detail-frame">
                  <button type="button" class="photo-detail-image" :aria-label="t('openFullscreen')" @click="openLightbox">
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
                    <button type="button" class="action-chip" :aria-label="t('sharePhoto')" @click="sharePhoto">
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
                  <h2 class="sheet-title">{{ photo.title }}</h2>
                  <p class="direction-line">
                    <DirectionMarker :direction="photo.direction" :label="photoDirectionLabel" />
                    {{ photoDirectionLabel }}
                  </p>
                  <p v-if="addedLabel" class="sheet-added">
                    <svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true">
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
                </div>

                <YoutubeEmbed v-if="photo.video" :url="photo.video" :title="photo.title" />
              </div>

              <div class="sheet-side">
                <RouterLink
                  v-if="photo.author"
                  class="panel author-card"
                  :to="`/users/${photo.author.unique || photo.author.uid}`"
                >
                  <img class="author-card-avatar" :src="authorAvatar(photo.author)" :alt="userDisplayName(photo.author, t)" />
                  <div class="author-card-body">
                    <p class="eyebrow">{{ t('photographer') }}</p>
                    <strong>{{ userDisplayName(photo.author, t) }}</strong>
                    <small v-if="photo.author_stats">
                      {{ photo.author_stats.photos_count }} {{ t('photosCount') }} ·
                      {{ photo.author_stats.views_total.toLocaleString() }} {{ t('views') }}
                    </small>
                  </div>
                  <span class="author-card-arrow" aria-hidden="true">→</span>
                </RouterLink>

                <div class="sheet-coords">
                  <p class="eyebrow">{{ t('location') }}</p>
                  <p class="location-coords">{{ formatCoords(photo.lat, photo.lng) }}</p>
                </div>
              </div>
            </div>

            <section v-if="photo.nearby_photos?.length" class="sheet-related">
              <h3>{{ t('nearbyPhotos') }}</h3>
              <div class="related-grid">
                <button
                  v-for="item in photo.nearby_photos"
                  :key="item.id"
                  type="button"
                  class="related-card sheet-related-card"
                  @click="navigate(item.id)"
                >
                  <img :src="imageUrl(item.images.thumb)" :alt="item.title" />
                  <span class="related-year">{{ item.year }}</span>
                  <strong>{{ item.title }}</strong>
                </button>
              </div>
            </section>

            <section class="sheet-comments">
              <h3>{{ t('comments') }}</h3>
              <form class="comment-form" @submit.prevent="submitComment">
                <textarea v-model="comment" :placeholder="t('writeComment')" required />
                <button class="button" type="submit">{{ t('postComment') }}</button>
                <p v-if="commentError" class="error">{{ commentError }}</p>
              </form>
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
          </template>
        </div>
      </section>
    </transition>

    <transition name="lightbox">
      <div v-if="lightboxOpen && photo" class="photo-lightbox" @click.self="closeLightbox">
        <button class="lightbox-close" type="button" :aria-label="t('cancel')" @click="closeLightbox">×</button>
        <figure class="lightbox-figure">
          <img :src="detailImageSrc" :alt="photo.title" />
          <figcaption>
            <strong>{{ photo.title }}</strong>
            <span>{{ photo.year }}<template v-if="photo.author"> · {{ userDisplayName(photo.author, t) }}</template></span>
          </figcaption>
        </figure>
      </div>
    </transition>
  </Teleport>
</template>

<style lang="scss">
.photo-sheet {
  position: fixed;
  left: 50%;
  right: auto;
  bottom: 0;
  z-index: 950;
  display: flex;
  flex-direction: column;
  width: min(960px, 100%);
  // Fixed height keeps the slide-up smooth: the panel animates at full size and
  // content loads inside it instead of resizing the panel (which caused the jump).
  height: min(86vh, 880px);
  transform: translateX(-50%);
  border: 1px solid $line;
  border-bottom: 0;
  border-radius: $radius-xl $radius-xl 0 0;
  background: $surface;
  box-shadow: 0 -24px 60px rgba(8, 18, 45, 0.28);
  overflow: hidden;

  @include mq-down($bp-md) {
    width: 100%;
    height: 84vh;
    border-radius: $radius-lg $radius-lg 0 0;
  }
}

.photo-sheet-bar {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-shrink: 0;
  padding: 10px 14px 8px;
  border-bottom: 1px solid $line;
  background: $surface;
}

.photo-sheet-grip {
  position: absolute;
  top: 7px;
  left: 50%;
  width: 42px;
  height: 4px;
  border-radius: 999px;
  background: rgba($ink, 0.18);
  transform: translateX(-50%);
}

.photo-sheet-bar-actions {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.photo-sheet-openfull {
  padding: 6px 12px;
  border-radius: $radius-pill;
  color: $primary;
  background: $primary-light;
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
  @include interactive((background, color));

  &:hover {
    color: #fff;
    background: $primary;
  }
}

.photo-sheet-hide {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 12px;
  border: 0;
  border-radius: $radius-pill;
  color: #fff;
  background: linear-gradient(135deg, $primary, $primary-dark);
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  @include interactive((box-shadow, transform));

  &:hover {
    box-shadow: 0 8px 18px rgba($primary, 0.32);
    transform: translateY(-1px);
  }

  @include focus-ring(rgba($primary, 0.42), 2px);
}

.photo-sheet-scroll {
  flex: 1;
  min-height: 0;
  padding: 18px 20px 26px;
  overflow-x: hidden;
  overflow-y: auto;
  overscroll-behavior: contain;

  @include mq-down($bp-sm) {
    padding: 14px 14px 22px;
  }
}

.photo-sheet-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  min-height: 55vh;
  color: $muted;
  font-size: 14px;
  font-weight: 500;
  text-align: center;

  &.error {
    color: #b42318;
  }
}

.photo-sheet-spinner {
  width: 26px;
  height: 26px;
  border: 3px solid rgba($primary, 0.22);
  border-top-color: $primary;
  border-radius: 50%;
  animation: sheet-spin 0.7s linear infinite;
}

@keyframes sheet-spin {
  to {
    transform: rotate(360deg);
  }
}

.sheet-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr);
  gap: 18px;
  align-items: start;

  @include mq-down($bp-md) {
    grid-template-columns: 1fr;
    gap: 14px;
  }
}

.sheet-main {
  display: grid;
  gap: 14px;
}

.sheet-title {
  margin: 2px 0 4px;
  font-size: clamp(19px, 2.4vw, 26px);
  line-height: 1.15;
}

.sheet-added {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: 0;
  color: $muted;
  font-size: 12px;
  font-weight: 500;

  svg {
    flex-shrink: 0;
    opacity: 0.8;
  }
}

.sheet-side {
  display: grid;
  gap: 12px;
  align-content: start;
}

.sheet-coords {
  padding: 12px 14px;
  border: 1px solid $line;
  border-radius: $radius-md;
  background: $surface-soft;

  .eyebrow {
    margin: 0 0 4px;
  }

  .location-coords {
    margin: 0;
  }
}

.sheet-related,
.sheet-comments {
  margin-top: 18px;

  h3 {
    margin: 0 0 12px;
    font-size: 16px;
  }
}

.sheet-related-card {
  appearance: none;
  border: 0;
  width: 100%;
  text-align: left;
  font: inherit;
  cursor: pointer;
}

// ---------- Slide-up / slide-down animation ----------------------
.sheet-slide-enter-active,
.sheet-slide-leave-active {
  transition: transform 0.42s cubic-bezier(0.22, 1, 0.36, 1);
}

.sheet-slide-enter-from,
.sheet-slide-leave-to {
  transform: translateX(-50%) translateY(100%);
}
</style>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { imageUrl, localizedApi, api } from '../api'
import { useI18n } from '../i18n'
import { useLanguageReload } from '../composables/useLanguageReload'
import { directionLabel } from '../utils/locale'
import { photoDisplayLikes } from '../utils/photoStats'
import DirectionMarker from '../components/DirectionMarker.vue'
import DirectionCompassPicker from '../components/DirectionCompassPicker.vue'
import LikeIcon from '../components/LikeIcon.vue'
import WinterBadgeIcon from '../components/WinterBadgeIcon.vue'

const photos = ref([])
const meta = ref(null)
const filters = ref({
  search: '',
  year_from: '',
  year_to: '',
  media: '',
  direction: '',
  user: '',
  winter: false,
})
const authorQuery = ref('')
const authorSuggestions = ref([])
const selectedAuthor = ref(null)
const authorLoading = ref(false)
const filtersOpen = ref(false)
const compassOpen = ref(false)
const mediaOptions = [
  { value: '', label: 'allPhotos' },
  { value: 'photo', label: 'mediaTabPhoto' },
  { value: 'video', label: 'mediaTabVideo' },
]
const loading = ref(false)
const loadingMore = ref(false)
const sentinel = ref(null)
const imageReady = ref({})
let observer
let authorTimer
const { t, currentLanguage } = useI18n()
const photosPublishedCount = ref(null)
const skeletonItems = Array.from({ length: 12 }, (_, index) => index)
const moreSkeletonItems = Array.from({ length: 8 }, (_, index) => index)

const initialLoading = computed(() => loading.value && photos.value.length === 0)

const pageTitle = computed(() => {
  if (photosPublishedCount.value == null) return t('photos')
  const locale = { hy: 'hy-AM', ru: 'ru-RU', en: 'en-US' }[currentLanguage.value] || 'en-US'
  const count = Number(photosPublishedCount.value).toLocaleString(locale)
  return t('photosMenu', { count })
})

const compassDirection = computed(() =>
  filters.value.direction === '' ? 1 : Number(filters.value.direction),
)

const directionFilterLabel = computed(() => {
  if (filters.value.direction === '') return t('filterAllDirections')
  return directionLabel(Number(filters.value.direction), t)
})

const hasExtraFilters = computed(() =>
  Boolean(filters.value.direction || filters.value.user || filters.value.winter || selectedAuthor.value),
)

const activeFilterCount = computed(() => {
  let count = 0
  if (filters.value.search) count += 1
  if (filters.value.year_from) count += 1
  if (filters.value.year_to) count += 1
  if (filters.value.media) count += 1
  if (filters.value.direction) count += 1
  if (filters.value.user) count += 1
  if (filters.value.winter) count += 1
  return count
})

function resetImageReady() {
  imageReady.value = {}
}

function markImageReady(id) {
  if (!id || imageReady.value[id]) return
  imageReady.value = { ...imageReady.value, [id]: true }
}

function bindPhotoImage(el, id) {
  if (!el || !id) return
  const mark = () => markImageReady(id)
  el.addEventListener('load', mark, { once: true })
  el.addEventListener('error', mark, { once: true })
  if (el.complete) {
    mark()
  }
}

async function load(page = 1, append = false, { soft = false } = {}) {
  if (loading.value || loadingMore.value) return

  if (append) {
    loadingMore.value = true
  } else if (!soft) {
    loading.value = true
  }
  try {
    const params = new URLSearchParams({ page })
    Object.entries(filters.value).forEach(([key, value]) => {
      if (key === 'winter') {
        if (value) params.set('winter', '1')
        return
      }
      if (value !== '' && value !== false && value !== null && value !== undefined) {
        params.set(key, value)
      }
    })
    const payload = await localizedApi(`/photos?${params}`)
    photos.value = append ? [...photos.value, ...payload.data] : payload.data
    meta.value = payload
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function applyFilters() {
  photos.value = []
  meta.value = null
  resetImageReady()
  load()
}

function clearExtraFilters() {
  filters.value.direction = ''
  filters.value.user = ''
  filters.value.winter = false
  selectedAuthor.value = null
  authorQuery.value = ''
  authorSuggestions.value = []
  compassOpen.value = false
  applyFilters()
}

function setMedia(value) {
  if (filters.value.media === value) return
  filters.value.media = value
  applyFilters()
}

function setDirection(value) {
  filters.value.direction = value === '' || value === null ? '' : String(value)
  applyFilters()
}

function toggleWinter() {
  filters.value.winter = !filters.value.winter
  applyFilters()
}

async function searchAuthors(query) {
  const q = query.trim()
  if (q.length < 2) {
    authorSuggestions.value = []
    return
  }

  authorLoading.value = true
  try {
    authorSuggestions.value = await localizedApi(`/users/search?q=${encodeURIComponent(q)}`, { ttl: 60 * 1000 })
  } catch {
    authorSuggestions.value = []
  } finally {
    authorLoading.value = false
  }
}

function selectAuthor(author) {
  selectedAuthor.value = author
  authorQuery.value = author.name || author.uid
  filters.value.user = author.unique
  authorSuggestions.value = []
  applyFilters()
}

function clearAuthor() {
  selectedAuthor.value = null
  authorQuery.value = ''
  filters.value.user = ''
  authorSuggestions.value = []
  applyFilters()
}

function loadMore() {
  if (!meta.value || meta.value.current_page >= meta.value.last_page) return
  load(meta.value.current_page + 1, true)
}

watch(authorQuery, (value) => {
  if (selectedAuthor.value && value === (selectedAuthor.value.name || selectedAuthor.value.uid)) return
  selectedAuthor.value = null
  filters.value.user = ''
  clearTimeout(authorTimer)
  authorTimer = setTimeout(() => searchAuthors(value), 280)
})

onMounted(() => {
  api('/photos/site-stats', { ttl: 10 * 60 * 1000 })
    .then((payload) => {
      const count = Number(payload?.photos_published)
      if (Number.isFinite(count) && count >= 0) {
        photosPublishedCount.value = count
      }
    })
    .catch(() => {})
  load()
  observer = new IntersectionObserver((entries) => {
    if (entries.some((entry) => entry.isIntersecting)) {
      loadMore()
    }
  }, { rootMargin: '280px 0px' })
  if (sentinel.value) {
    observer.observe(sentinel.value)
  }
})

useLanguageReload(() => load(1, false, { soft: true }))

onBeforeUnmount(() => {
  observer?.disconnect()
  clearTimeout(authorTimer)
})
</script>

<template>
  <section class="page-head">
    <p class="eyebrow">{{ t('gallery') }}</p>
    <h1>{{ pageTitle }}</h1>
  </section>

  <section class="gallery-toolbar panel">
    <div class="gallery-toolbar__bar">
      <div class="gallery-toolbar__media" role="tablist" :aria-label="t('photos')">
        <button
          v-for="option in mediaOptions"
          :key="option.value"
          type="button"
          role="tab"
          class="gallery-chip"
          :class="{ on: filters.media === option.value }"
          :aria-selected="filters.media === option.value"
          @click="setMedia(option.value)"
        >
          {{ t(option.label) }}
        </button>
      </div>

      <button
        type="button"
        class="gallery-filters-toggle"
        :class="{ open: filtersOpen, active: activeFilterCount > 0 }"
        :aria-expanded="filtersOpen"
        @click="filtersOpen = !filtersOpen"
      >
        <span>{{ t('galleryFilters') }}</span>
        <span v-if="activeFilterCount" class="gallery-filters-toggle__count">{{ activeFilterCount }}</span>
        <svg class="gallery-filters-toggle__caret" viewBox="0 0 12 12" aria-hidden="true">
          <path d="M3 4.5 6 7.5l3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
    </div>

    <div v-show="filtersOpen" class="gallery-toolbar__panel">
      <form class="gallery-toolbar__search" @submit.prevent="applyFilters">
        <input v-model="filters.search" :placeholder="t('search')" />
        <input v-model="filters.year_from" class="gallery-toolbar__year" :placeholder="t('fromYear')" inputmode="numeric" />
        <input v-model="filters.year_to" class="gallery-toolbar__year" :placeholder="t('toYear')" inputmode="numeric" />
        <button class="button" type="submit">{{ t('filter') }}</button>
      </form>

      <div class="gallery-toolbar__row">
        <button
          type="button"
          class="gallery-chip gallery-chip--direction"
          :class="{ on: filters.direction !== '' }"
          @click="compassOpen = !compassOpen"
        >
          <DirectionMarker
            v-if="filters.direction !== ''"
            :direction="Number(filters.direction)"
            :label="directionFilterLabel"
            size="small"
          />
          <span>{{ directionFilterLabel }}</span>
          <svg class="gallery-chip__caret" viewBox="0 0 12 12" aria-hidden="true">
            <path d="M3 4.5 6 7.5l3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
        <button
          v-if="filters.direction !== ''"
          type="button"
          class="gallery-chip gallery-chip--ghost"
          @click="setDirection('')"
        >
          ×
        </button>
      </div>

      <div v-if="compassOpen" class="gallery-toolbar__compass">
        <DirectionCompassPicker :model-value="compassDirection" @update:model-value="setDirection" />
      </div>

      <div class="gallery-toolbar__row gallery-toolbar__row--meta">
        <div class="gallery-toolbar__author">
          <label class="gallery-toolbar__title" for="author-filter">{{ t('filterByAuthor') }}</label>
          <div class="gallery-toolbar__input-wrap" :class="{ filled: Boolean(selectedAuthor || filters.user) }">
            <svg class="gallery-toolbar__input-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
              <circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8" />
              <path d="M16.5 16.5 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            </svg>
            <input
              id="author-filter"
              v-model="authorQuery"
              type="search"
              class="gallery-toolbar__input"
              :placeholder="t('authorSearchPlaceholder')"
              autocomplete="off"
            />
            <button
              v-if="selectedAuthor || filters.user"
              type="button"
              class="gallery-toolbar__input-clear"
              :aria-label="t('clearAuthorFilter')"
              @click="clearAuthor"
            >
              ×
            </button>
            <ul v-if="authorSuggestions.length" class="gallery-toolbar__suggestions">
              <li v-for="author in authorSuggestions" :key="author.unique">
                <button type="button" @click="selectAuthor(author)">
                  {{ author.name || author.uid }}
                </button>
              </li>
            </ul>
            <span v-else-if="authorLoading" class="gallery-toolbar__hint">{{ t('loading') }}</span>
          </div>
        </div>

        <div class="gallery-toolbar__actions">
          <button
            type="button"
            class="gallery-chip gallery-chip--winter"
            :class="{ on: filters.winter }"
            :aria-pressed="filters.winter"
            @click="toggleWinter"
          >
            <WinterBadgeIcon size="sm" />
            <span>{{ t('filterWinterPhotos') }}</span>
          </button>

          <button
            type="button"
            class="gallery-chip gallery-chip--ghost gallery-chip--reset"
            :disabled="!hasExtraFilters"
            @click="clearExtraFilters"
          >
            {{ t('clearFilters') }}
          </button>
        </div>
      </div>
    </div>
  </section>

  <section class="photo-grid">
    <template v-if="initialLoading">
      <article v-for="item in skeletonItems" :key="`skeleton-${item}`" class="photo-card photo-skeleton" aria-hidden="true">
        <span></span>
        <strong></strong>
        <small></small>
      </article>
    </template>

    <template v-else>
      <RouterLink v-for="photo in photos" :key="photo.id" class="photo-card" :to="`/photos/${photo.id}`">
        <div class="photo-card__media" :class="{ 'is-loading': !imageReady[photo.id] }">
          <span class="photo-card__media-shimmer" aria-hidden="true"></span>
          <img
            :ref="(el) => bindPhotoImage(el, photo.id)"
            :src="imageUrl(photo.images.thumb || photo.images.large)"
            :alt="photo.title"
            loading="lazy"
            decoding="async"
          />
          <span class="photo-year">{{ photo.year }}</span>
          <span v-if="photo.video" class="photo-video-badge" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="14" height="14"><path fill="currentColor" d="M8 5v14l11-7z" /></svg>
          </span>
          <span v-if="photo.is_winter" class="photo-winter-badge" :title="t('winterPhoto')">
            <WinterBadgeIcon size="sm" />
          </span>
          <DirectionMarker :direction="photo.direction" :label="directionLabel(photo.direction, t)" size="small" />
        </div>
        <h3>{{ photo.title }}</h3>
        <small>{{ directionLabel(photo.direction, t) }}</small>
        <div class="photo-card-meta">
          <span class="like-pill">
            <LikeIcon />{{ photoDisplayLikes(photo) }}
          </span>
          <span>{{ photo.views }} {{ t('views') }}</span>
          <span>{{ photo.comments_count }} {{ t('comments') }}</span>
        </div>
      </RouterLink>

      <article
        v-if="loadingMore"
        v-for="item in moreSkeletonItems"
        :key="`more-${item}`"
        class="photo-card photo-skeleton"
        aria-hidden="true"
      >
        <span></span>
        <strong></strong>
        <small></small>
      </article>
    </template>
  </section>

  <div ref="sentinel" class="load-sentinel">
    <span v-if="loadingMore" class="load-sentinel__label">{{ t('loading') }}</span>
    <span v-else-if="meta && meta.current_page >= meta.last_page && photos.length">{{ t('allPhotosLoaded') }}</span>
  </div>
</template>

<style lang="scss">
.gallery-toolbar {
  display: grid;
  gap: 0;
  margin-bottom: 18px;
  padding: 12px 14px;
}

.gallery-toolbar__bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.gallery-toolbar__media {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.gallery-filters-toggle {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 34px;
  padding: 6px 12px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface-soft;
  color: $ink;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  transition: border-color 0.15s ease, background 0.15s ease;

  &:hover {
    border-color: rgba($primary, 0.35);
    background: $surface;
  }

  &.active {
    border-color: rgba($primary, 0.4);
    color: $primary;
  }

  &.open .gallery-filters-toggle__caret {
    transform: rotate(180deg);
  }
}

.gallery-filters-toggle__count {
  display: inline-grid;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  place-items: center;
  border-radius: $radius-pill;
  background: $accent;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
}

.gallery-filters-toggle__caret {
  width: 12px;
  height: 12px;
  color: $muted;
  transition: transform 0.2s ease;
}

.gallery-toolbar__panel {
  display: grid;
  gap: 10px;
  padding-top: 12px;
  margin-top: 12px;
  border-top: 1px solid $line;
}

.gallery-toolbar__search {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;

  input {
    flex: 1 1 140px;
    min-height: 36px;
    padding: 7px 11px;
    border: 1px solid $line;
    border-radius: $radius-sm;
    background: $surface;
    color: $ink;
    font-size: 13px;

    &:focus {
      outline: none;
      border-color: $primary;
      box-shadow: 0 0 0 3px rgba($primary, 0.16);
    }
  }

  .gallery-toolbar__year {
    flex: 0 1 92px;
    min-width: 80px;
  }

  .button {
    min-height: 36px;
    padding-inline: 14px;
    font-size: 13px;
  }
}

.gallery-toolbar__row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.gallery-toolbar__row--meta {
  align-items: end;
}

.gallery-toolbar__title {
  display: block;
  margin-bottom: 6px;
  color: $muted;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.gallery-toolbar__compass {
  max-width: 320px;
}

.gallery-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface-soft;
  color: $muted;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
  transition:
    background 0.15s ease,
    border-color 0.15s ease,
    color 0.15s ease,
    box-shadow 0.15s ease;

  &:hover {
    border-color: rgba($primary, 0.35);
    color: $ink;
    background: $surface;
  }

  &.on {
    border-color: transparent;
    color: #fff;
    background: linear-gradient(135deg, $primary, $primary-dark);
    box-shadow: 0 4px 12px rgba($primary, 0.22);
  }

  &--ghost {
    background: transparent;
    border-color: rgba($primary, 0.18);
    color: $primary;

    &:hover {
      background: rgba($primary, 0.06);
    }

    &.on {
      color: #fff;
      background: linear-gradient(135deg, $primary, $primary-dark);
    }
  }

  &--winter.on {
    background: linear-gradient(135deg, #4f9fd8, #2f74b8);
    box-shadow: 0 4px 12px rgba(47, 116, 184, 0.28);
  }

  &--direction.on {
    color: #fff;
    background: linear-gradient(135deg, $primary, $primary-dark);
  }

  &--reset {
    min-width: 118px;

    &:disabled {
      opacity: 0.38;
      cursor: default;
      pointer-events: none;
    }
  }

  &__caret {
    width: 11px;
    height: 11px;
    color: $muted;
    transition: transform 0.2s ease;
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.gallery-toolbar__author {
  flex: 0 1 200px;
  width: 200px;
  max-width: 100%;
  display: grid;
  gap: 0;
  min-width: 0;
}

.gallery-toolbar__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.gallery-toolbar__input-wrap {
  position: relative;
  display: flex;
  align-items: center;
  min-height: 38px;
  border: 1px solid $line;
  border-radius: $radius-sm;
  background: $surface;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;

  &:focus-within,
  &.filled {
    border-color: rgba($primary, 0.45);
    box-shadow: 0 0 0 3px rgba($primary, 0.12);
  }
}

.gallery-toolbar__input-icon {
  margin-left: 11px;
  color: $muted;
  flex-shrink: 0;
}

.gallery-toolbar__input {
  width: 100%;
  min-width: 0;
  padding: 8px 34px 8px 8px;
  border: 0;
  background: transparent;
  color: $ink;
  font-size: 13px;

  &:focus {
    outline: none;
  }

  &::placeholder {
    color: rgba($muted, 0.9);
  }
}

.gallery-toolbar__input-clear {
  position: absolute;
  top: 50%;
  right: 6px;
  display: grid;
  width: 24px;
  height: 24px;
  place-items: center;
  border: 0;
  border-radius: 50%;
  background: rgba($primary, 0.1);
  color: $primary;
  cursor: pointer;
  font-size: 16px;
  line-height: 1;
  transform: translateY(-50%);
}

.gallery-toolbar__suggestions {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  z-index: 30;
  margin: 0;
  padding: 4px;
  list-style: none;
  border: 1px solid $line;
  border-radius: $radius-md;
  background: $surface;
  box-shadow: $shadow-md;

  button {
    display: block;
    width: 100%;
    padding: 8px 10px;
    border: 0;
    border-radius: $radius-sm;
    background: transparent;
    color: $ink;
    cursor: pointer;
    text-align: left;
    font-size: 13px;

    &:hover {
      background: $surface-soft;
    }
  }
}

.gallery-toolbar__hint {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  color: $muted;
  font-size: 11px;
}

.photo-card {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;
  min-width: 0;
  padding: 8px;
  border-radius: $radius-lg;
  background: $surface;
  color: inherit;
  text-decoration: none;
  box-shadow: $shadow-lg;

  &:focus-visible {
    outline: 2px solid rgba($primary, 0.45);
    outline-offset: 3px;
  }

  &__media {
    position: relative;
    flex: 0 0 auto;
    overflow: hidden;
    aspect-ratio: 3 / 2;
    border-radius: $radius-md - 1;
    background: $surface-soft;

    img {
      position: absolute;
      inset: 0;
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
      object-position: center;
    }

    &.is-loading .photo-card__media-shimmer {
      opacity: 1;
    }

    .direction-marker {
      position: absolute;
      top: 10px;
      right: 10px;
      z-index: 2;
    }
  }

  &__media-shimmer {
    position: absolute;
    inset: 0;
    z-index: 1;
    border-radius: inherit;
    background:
      linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.7), transparent),
      #e9effb;
    background-size: 220px 100%, 100% 100%;
    animation: skeleton-shimmer 1.1s infinite linear;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.2s ease;
  }

  h3 {
    margin: 8px 0 2px;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: calc(1.3em * 2);
  }

  small {
    color: $muted;
    font-size: 11px;
    font-weight: 400;
    @include truncate;
  }
}

.photo-year {
  position: absolute;
  top: 10px;
  left: 10px;
  z-index: 2;
  display: inline-flex;
  padding: 3px 8px;
  border-radius: $radius-pill;
  color: #fff;
  background: rgba($accent, 0.92);
  font-size: 11px;
  font-weight: 600;
  line-height: 1.2;
  backdrop-filter: blur(4px);
}

.photo-video-badge {
  position: absolute;
  top: auto;
  bottom: 10px;
  left: 10px;
  z-index: 2;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  color: #fff;
  background: rgba(0, 0, 0, 0.58);
  backdrop-filter: blur(2px);

  svg {
    margin-left: 1px;
  }
}

.photo-winter-badge {
  position: absolute;
  top: auto;
  bottom: 10px;
  right: 10px;
  left: auto;
  z-index: 2;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  color: #2f74b8;
  background: rgba(255, 255, 255, 0.94);
  box-shadow: 0 3px 10px rgba(20, 24, 34, 0.14);
}

.photo-card-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: auto;
  padding-top: 8px;
  color: $muted;
  font-size: 11px;
  font-weight: 500;
}

.like-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #2374e1;
  font-weight: 600;
}

.photo-skeleton {
  min-height: 0;
  pointer-events: none;
  background:
    linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.7), transparent),
    #e9effb;
  background-size: 220px 100%, 100% 100%;
  animation: skeleton-shimmer 1.1s infinite linear;

  span,
  strong,
  small {
    display: block;
    border-radius: $radius-pill;
    background: rgba(255, 255, 255, 0.75);
  }

  span {
    aspect-ratio: 3 / 2;
    width: 100%;
    height: auto;
    border-radius: $radius-md - 1;
  }

  strong {
    height: 16px;
    margin-top: 10px;
  }

  small {
    width: 55%;
    height: 12px;
    margin-top: 8px;
  }
}

.load-sentinel__label {
  display: inline-block;
  min-width: 6rem;
}

[data-theme='dark'] {
  .gallery-filters-toggle {
    background: #1c212c;
    border-color: #2a313d;
    color: #e7ebf3;

    &:hover {
      background: #232a36;
    }

    &.active {
      color: #9eb4ff;
      border-color: #354052;
    }
  }

  .gallery-toolbar__panel {
    border-top-color: #2a313d;
  }

  .gallery-toolbar__search input,
  .gallery-toolbar__input-wrap {
    background: #161b25;
    border-color: #2a313d;
    color: #e7ebf3;
  }

  .gallery-toolbar__search input:focus,
  .gallery-toolbar__input-wrap:focus-within,
  .gallery-toolbar__input-wrap.filled {
    border-color: rgba(120, 156, 255, 0.45);
    box-shadow: 0 0 0 3px rgba(120, 156, 255, 0.12);
  }

  .gallery-toolbar__input {
    color: #e7ebf3;

    &::placeholder {
      color: #7f8796;
    }
  }

  .gallery-toolbar__input-icon {
    color: #98a0ae;
  }

  .gallery-chip {
    background: #1c212c;
    border-color: #2a313d;
    color: #c5cad6;

    &:hover {
      background: #232a36;
      color: #f4f7ff;
    }

    &--ghost {
      background: transparent;
      border-color: #354052;
      color: #9eb4ff;

      &:hover {
        background: rgba(120, 156, 255, 0.08);
      }
    }
  }

  .gallery-toolbar__suggestions {
    background: #161b25;
    border-color: #2a313d;

    button {
      color: #e7ebf3;

      &:hover {
        background: #232a36;
      }
    }
  }

  .photo-winter-badge {
    color: #8ec8ff;
    background: rgba(22, 27, 37, 0.92);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.35);
  }

  .photo-skeleton,
  .photo-card__media-shimmer {
    background:
      linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.05), transparent),
      #1c212c;
  }
}
</style>

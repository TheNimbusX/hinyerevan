<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { imageUrl, localizedApi } from '../api'
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
const directionOpen = ref(false)
const directionOptions = [
  { value: 1, label: 'north', short: 'N' },
  { value: 2, label: 'northEast', short: 'NE' },
  { value: 3, label: 'east', short: 'E' },
  { value: 4, label: 'southEast', short: 'SE' },
  { value: 5, label: 'south', short: 'S' },
  { value: 6, label: 'southWest', short: 'SW' },
  { value: 7, label: 'west', short: 'W' },
  { value: 8, label: 'northWest', short: 'NW' },
  { value: 0, label: 'topShot', short: '↑' },
]
const mediaOptions = [
  { value: '', label: 'allPhotos' },
  { value: 'photo', label: 'mediaTabPhoto' },
  { value: 'video', label: 'mediaTabVideo' },
]
const loading = ref(false)
const loadingMore = ref(false)
const sentinel = ref(null)
let observer
let authorTimer
const { t } = useI18n()
const skeletonItems = Array.from({ length: 12 }, (_, index) => index)

const compassDirection = computed(() =>
  filters.value.direction === '' ? 1 : Number(filters.value.direction),
)

const hasExtraFilters = computed(() =>
  Boolean(filters.value.direction || filters.value.user || filters.value.winter || selectedAuthor.value),
)

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
  load()
}

function clearExtraFilters() {
  filters.value.direction = ''
  filters.value.user = ''
  filters.value.winter = false
  selectedAuthor.value = null
  authorQuery.value = ''
  authorSuggestions.value = []
  directionOpen.value = false
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
  load()
  observer = new IntersectionObserver((entries) => {
    if (entries.some((entry) => entry.isIntersecting)) {
      loadMore()
    }
  })
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
    <h1>{{ t('photos') }}</h1>
  </section>

  <section class="gallery-toolbar panel">
    <div class="gallery-toolbar__top">
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

      <form class="gallery-toolbar__search" @submit.prevent="applyFilters">
        <input v-model="filters.search" :placeholder="t('search')" />
        <input v-model="filters.year_from" :placeholder="t('fromYear')" inputmode="numeric" />
        <input v-model="filters.year_to" :placeholder="t('toYear')" inputmode="numeric" />
        <button class="button" type="submit">{{ t('filter') }}</button>
      </form>
    </div>

    <div class="gallery-toolbar__section">
      <div class="gallery-toolbar__section-head">
        <span class="gallery-toolbar__title">{{ t('filterByDirection') }}</span>
        <button type="button" class="gallery-chip gallery-chip--ghost" @click="directionOpen = !directionOpen">
          {{ directionOpen ? t('hideCompass') : t('showCompass') }}
        </button>
      </div>
      <div class="gallery-toolbar__chips gallery-toolbar__chips--scroll" role="listbox" :aria-label="t('filterByDirection')">
        <button
          type="button"
          role="option"
          class="gallery-chip"
          :class="{ on: filters.direction === '' }"
          :aria-selected="filters.direction === ''"
          @click="setDirection('')"
        >
          {{ t('filterAllDirections') }}
        </button>
        <button
          v-for="item in directionOptions"
          :key="item.value"
          type="button"
          role="option"
          class="gallery-chip gallery-chip--dir"
          :class="{ on: filters.direction === String(item.value) }"
          :aria-selected="filters.direction === String(item.value)"
          :title="t(item.label)"
          @click="setDirection(item.value)"
        >
          <span class="gallery-chip__short">{{ item.short }}</span>
          <span class="gallery-chip__full">{{ t(item.label) }}</span>
        </button>
      </div>
      <div v-if="directionOpen" class="gallery-toolbar__compass">
        <DirectionCompassPicker :model-value="compassDirection" @update:model-value="setDirection" />
      </div>
    </div>

    <div class="gallery-toolbar__section gallery-toolbar__section--footer">
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
          v-if="hasExtraFilters"
          type="button"
          class="gallery-chip gallery-chip--ghost"
          @click="clearExtraFilters"
        >
          {{ t('clearFilters') }}
        </button>
      </div>
    </div>
  </section>

  <section v-if="loading" class="photo-grid masonry-grid">
    <article v-for="item in skeletonItems" :key="item" class="photo-card photo-skeleton">
      <span></span>
      <strong></strong>
      <small></small>
    </article>
  </section>
  <section v-else class="photo-grid masonry-grid">
    <RouterLink v-for="photo in photos" :key="photo.id" class="photo-card" :to="`/photos/${photo.id}`">
      <img :src="imageUrl(photo.images.large || photo.images.thumb)" :alt="photo.title" loading="lazy" />
      <span v-if="photo.video" class="photo-video-badge" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="14" height="14"><path fill="currentColor" d="M8 5v14l11-7z" /></svg>
      </span>
      <span v-if="photo.is_winter" class="photo-winter-badge" :title="t('winterPhoto')">
        <WinterBadgeIcon size="sm" />
      </span>
      <span class="photo-year">{{ photo.year }}</span>
      <DirectionMarker :direction="photo.direction" :label="directionLabel(photo.direction, t)" size="small" />
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
  </section>

  <div ref="sentinel" class="load-sentinel">
    <span v-if="loadingMore">{{ t('loading') }}</span>
    <span v-else-if="meta && meta.current_page >= meta.last_page">{{ t('allPhotosLoaded') }}</span>
  </div>
</template>

<style lang="scss">
.gallery-toolbar {
  display: grid;
  gap: 12px;
  margin-bottom: 18px;
  padding: 14px 16px;
}

.gallery-toolbar__top {
  display: grid;
  gap: 12px;

  @include mq-up($bp-md) {
    grid-template-columns: auto 1fr;
    align-items: center;
  }
}

.gallery-toolbar__media,
.gallery-toolbar__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.gallery-toolbar__chips--scroll {
  flex-wrap: nowrap;
  overflow-x: auto;
  padding-bottom: 2px;
  scrollbar-width: thin;

  &::-webkit-scrollbar {
    height: 4px;
  }

  &::-webkit-scrollbar-thumb {
    border-radius: $radius-pill;
    background: rgba($primary, 0.22);
  }
}

.gallery-toolbar__search {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;

  input {
    flex: 1 1 120px;
    min-height: 38px;
    padding: 8px 12px;
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

  .button {
    min-height: 38px;
    padding-inline: 16px;
    font-size: 13px;
  }
}

.gallery-toolbar__section {
  display: grid;
  gap: 8px;
  padding-top: 10px;
  border-top: 1px solid $line;
}

.gallery-toolbar__section--footer {
  grid-template-columns: 1fr;
  gap: 10px;

  @include mq-up($bp-md) {
    grid-template-columns: minmax(220px, 1fr) auto;
    align-items: end;
  }
}

.gallery-toolbar__section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.gallery-toolbar__title {
  color: $muted;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.gallery-toolbar__compass {
  padding-top: 2px;
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

  &--dir {
    padding-inline: 10px;
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.gallery-chip__short {
  display: none;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.02em;
}

.gallery-chip__full {
  font-size: 12px;
}

@include mq-down($bp-lg) {
  .gallery-chip--dir {
    .gallery-chip__short {
      display: inline;
    }

    .gallery-chip__full {
      display: none;
    }
  }
}

.gallery-toolbar__author {
  display: grid;
  gap: 6px;
  min-width: 0;
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

.gallery-toolbar__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.photo-video-badge {
  position: absolute;
  top: 16px;
  left: 16px;
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
  top: 16px;
  left: 50px;
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

.photo-card {
  position: relative;
  display: inline-block;
  width: 100%;
  break-inside: avoid;
  margin: 0 0 16px;
  padding: 10px;
  border-radius: $radius-lg;
  background: $surface;
  color: inherit;
  text-decoration: none;
  box-shadow: $shadow-lg;
  @include hover-lift(-3px, 0 18px 36px rgba($primary, 0.18));

  &:focus-visible {
    outline: 2px solid rgba($primary, 0.45);
    outline-offset: 3px;
  }

  img {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    border-radius: $radius-md - 1;
    background: $surface-soft;
  }

  h3 {
    margin: 10px 0 4px;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.25;
  }

  small {
    color: $muted;
    font-size: 11px;
    font-weight: 400;
  }

  .direction-marker {
    position: absolute;
    top: 16px;
    right: 16px;
  }
}

.photo-year {
  display: inline-flex;
  margin-top: 10px;
  padding: 4px 10px;
  border-radius: $radius-pill;
  color: #fff;
  background: $accent;
  font-size: 12px;
  font-weight: 600;
}

.photo-card-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
  color: $muted;
  font-size: 12px;
  font-weight: 500;
}

.like-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #2374e1;
  font-weight: 600;
}

.masonry-grid {
  .photo-card:nth-child(8n + 2) img,
  .photo-card:nth-child(8n + 7) img {
    aspect-ratio: 4 / 5;
  }

  .photo-card:nth-child(9n + 4) img {
    aspect-ratio: 16 / 10;
  }

  .photo-card:nth-child(10n + 6) img {
    aspect-ratio: 3 / 4;
  }
}

.photo-skeleton {
  min-height: 260px;
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
    height: 180px;
    border-radius: $radius-md - 1;
  }

  strong {
    height: 18px;
    margin-top: 14px;
  }

  small {
    width: 60%;
    height: 14px;
    margin-top: 10px;
  }
}

[data-theme='dark'] {
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

  .gallery-toolbar__section {
    border-top-color: #2a313d;
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

  .photo-skeleton {
    background:
      linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.05), transparent),
      #1c212c;
  }
}
</style>

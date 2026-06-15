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
  { value: 1, label: 'north' },
  { value: 2, label: 'northEast' },
  { value: 3, label: 'east' },
  { value: 4, label: 'southEast' },
  { value: 5, label: 'south' },
  { value: 6, label: 'southWest' },
  { value: 7, label: 'west' },
  { value: 8, label: 'northWest' },
  { value: 0, label: 'topShot' },
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
const { t, currentLanguage } = useI18n()
const skeletonItems = Array.from({ length: 12 }, (_, index) => index)

const compassDirection = computed(() =>
  filters.value.direction === '' ? 1 : Number(filters.value.direction),
)

const directionFilterLabel = computed(() => {
  if (filters.value.direction === '') return t('filterAllDirections')
  return directionLabel(Number(filters.value.direction), t)
})

const hasExtraFilters = computed(() =>
  Boolean(
    filters.value.direction
    || filters.value.user
    || filters.value.winter
    || selectedAuthor.value,
  ),
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

  <div class="media-filter" role="tablist" :aria-label="t('photos')">
    <button
      v-for="option in mediaOptions"
      :key="option.value"
      type="button"
      role="tab"
      class="media-filter__chip"
      :class="{ on: filters.media === option.value }"
      :aria-selected="filters.media === option.value"
      @click="setMedia(option.value)"
    >
      {{ t(option.label) }}
    </button>
  </div>

  <form class="filter-bar" @submit.prevent="applyFilters">
    <input v-model="filters.search" :placeholder="t('search')" />
    <input v-model="filters.year_from" :placeholder="t('fromYear')" inputmode="numeric" />
    <input v-model="filters.year_to" :placeholder="t('toYear')" inputmode="numeric" />
    <button class="button" type="submit">{{ t('filter') }}</button>
  </form>

  <section class="gallery-filters panel">
    <div class="gallery-filters__direction-block">
      <div class="gallery-filters__direction-head">
        <span class="gallery-filters__label">{{ t('filterByDirection') }}</span>
        <button type="button" class="direction-filter__compass-btn" @click="directionOpen = !directionOpen">
          <DirectionMarker
            v-if="filters.direction !== ''"
            :direction="Number(filters.direction)"
            :label="directionFilterLabel"
            size="small"
          />
          <span>{{ directionOpen ? t('hideCompass') : t('showCompass') }}</span>
        </button>
      </div>

      <div class="gallery-direction-chips" role="listbox" :aria-label="t('filterByDirection')">
        <button
          type="button"
          role="option"
          class="gallery-direction-chip"
          :class="{ active: filters.direction === '' }"
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
          class="gallery-direction-chip"
          :class="{ active: filters.direction === String(item.value) }"
          :aria-selected="filters.direction === String(item.value)"
          @click="setDirection(item.value)"
        >
          {{ t(item.label) }}
        </button>
      </div>

      <div v-if="directionOpen" class="gallery-filters__compass">
        <DirectionCompassPicker
          :model-value="compassDirection"
          @update:model-value="setDirection"
        />
      </div>
    </div>

    <div class="gallery-filters__extras">
      <div class="gallery-filters__group gallery-filters__group--author">
        <label class="gallery-filters__label" for="author-filter">{{ t('filterByAuthor') }}</label>
        <div class="author-filter">
          <input
            id="author-filter"
            v-model="authorQuery"
            type="search"
            :placeholder="t('authorSearchPlaceholder')"
            autocomplete="off"
          />
          <button
            v-if="selectedAuthor || filters.user"
            type="button"
            class="author-filter__clear"
            :aria-label="t('clearAuthorFilter')"
            @click="clearAuthor"
          >
            ×
          </button>
          <ul v-if="authorSuggestions.length" class="author-filter__suggestions">
            <li v-for="author in authorSuggestions" :key="author.unique">
              <button type="button" @click="selectAuthor(author)">
                {{ author.name || author.uid }}
              </button>
            </li>
          </ul>
          <span v-else-if="authorLoading" class="author-filter__hint">{{ t('loading') }}</span>
        </div>
      </div>

      <label class="gallery-filters__winter check-line">
        <input :checked="filters.winter" type="checkbox" @change="toggleWinter" />
        <span>{{ t('filterWinterPhotos') }}</span>
      </label>

      <button
        v-if="hasExtraFilters"
        type="button"
        class="link-button gallery-filters__reset"
        @click="clearExtraFilters"
      >
        {{ t('clearFilters') }}
      </button>
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
      <span v-if="photo.is_winter" class="photo-winter-badge" :title="t('winterPhoto')">❄</span>
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
.media-filter {
  display: inline-flex;
  gap: 4px;
  margin-bottom: 12px;
  padding: 4px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface-soft;
}

.media-filter__chip {
  padding: 7px 16px;
  border: 0;
  border-radius: $radius-pill;
  background: transparent;
  color: $muted;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
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

.gallery-filters {
  margin-bottom: 18px;
  padding: 14px 16px;
  display: grid;
  gap: 16px;
}

.gallery-filters__direction-block {
  display: grid;
  gap: 10px;
}

.gallery-filters__direction-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.gallery-filters__compass {
  padding-top: 4px;
}

.gallery-direction-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.gallery-direction-chip {
  border: 1px solid rgba($primary, 0.14);
  border-radius: $radius-pill;
  padding: 8px 14px;
  background: $surface-soft;
  color: $primary;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  transition:
    background 0.15s ease,
    border-color 0.15s ease,
    color 0.15s ease,
    box-shadow 0.15s ease;

  &:hover {
    border-color: $primary;
    background: #fff;
  }

  &.active {
    border-color: $accent;
    background: $accent;
    color: #fff;
    box-shadow: 0 6px 14px rgba($accent, 0.24);
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.direction-filter__compass-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 36px;
  padding: 6px 12px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface-soft;
  color: $ink;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
}

.gallery-filters__extras {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  align-items: end;
  padding-top: 4px;
  border-top: 1px solid $line;
}
.gallery-filters__group--author {
  flex: 1 1 240px;
  min-width: 200px;
}

.gallery-filters__label {
  display: block;
  margin-bottom: 8px;
  color: $muted;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.gallery-filters__group {
  display: grid;
  gap: 8px;
  min-width: 0;
}

.author-filter {
  position: relative;
}

.author-filter__clear {
  display: grid;
  width: 32px;
  height: 32px;
  place-items: center;
  border: 0;
  border-radius: 50%;
  background: rgba($primary, 0.08);
  color: $primary;
  cursor: pointer;
  font-size: 18px;
  line-height: 1;
  position: absolute;
  top: 5px;
  right: 5px;
}

.author-filter input {
  width: 100%;
  min-height: 42px;
  padding-right: 36px;
}

.author-filter__suggestions {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  z-index: 20;
  margin: 0;
  padding: 6px;
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

.author-filter__hint {
  display: block;
  margin-top: 6px;
  color: $muted;
  font-size: 12px;
}

.gallery-filters__winter {
  align-self: end;
  margin: 0;
  white-space: nowrap;
}

.gallery-filters__reset {
  align-self: end;
  justify-self: start;
}

.photo-video-badge {
  position: absolute;
  top: 16px;
  left: 16px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  color: #fff;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(2px);

  svg {
    margin-left: 1px;
  }
}

.photo-winter-badge {
  position: absolute;
  top: 16px;
  left: 52px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  font-size: 14px;
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12);
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
</style>

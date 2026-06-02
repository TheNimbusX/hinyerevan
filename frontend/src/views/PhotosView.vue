<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { imageUrl, localizedApi } from '../api'
import { useI18n } from '../i18n'
import { useLanguageReload, useLocalizedReady } from '../composables/useLanguageReload'
import { directionLabel } from '../utils/locale'
import DirectionMarker from '../components/DirectionMarker.vue'
import LikeIcon from '../components/LikeIcon.vue'

const photos = ref([])
const meta = ref(null)
const filters = ref({ search: '', year_from: '', year_to: '' })
const loading = ref(false)
const loadingMore = ref(false)
const sentinel = ref(null)
let observer
const { t, currentLanguage } = useI18n()
const skeletonItems = Array.from({ length: 12 }, (_, index) => index)

async function load(page = 1, append = false, { soft = false } = {}) {
  if (loading.value || loadingMore.value) return

  if (append) {
    loadingMore.value = true
  } else if (!soft) {
    loading.value = true
  }
  try {
    const params = new URLSearchParams({ page })
    Object.entries(filters.value).forEach(([key, value]) => value && params.set(key, value))
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

function loadMore() {
  if (!meta.value || meta.value.current_page >= meta.value.last_page) return
  load(meta.value.current_page + 1, true)
}

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

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <section class="page-head">
    <p class="eyebrow">{{ t('gallery') }}</p>
    <h1>{{ t('photos') }}</h1>
  </section>

  <form class="filter-bar" @submit.prevent="applyFilters">
    <input v-model="filters.search" :placeholder="t('search')" />
    <input v-model="filters.year_from" :placeholder="t('fromYear')" inputmode="numeric" />
    <input v-model="filters.year_to" :placeholder="t('toYear')" inputmode="numeric" />
    <button class="button" type="submit">{{ t('filter') }}</button>
  </form>

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
      <span class="photo-year">{{ photo.year }}</span>
      <DirectionMarker :direction="photo.direction" :label="directionLabel(photo.direction, t)" size="small" />
      <h3>{{ photo.title }}</h3>
      <small>{{ directionLabel(photo.direction, t) }}</small>
      <div class="photo-card-meta">
        <span class="like-pill">
          <LikeIcon />{{ photo.likes_count || 0 }}
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

// Variable aspect ratios for masonry-like rhythm
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

// ---------- Skeleton --------------------------------------------
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

<script setup>
import { RouterLink } from 'vue-router'
import { imageUrl, previewPhotoPath } from '../api'
import { useI18n } from '../i18n'
import { playVideo } from '../utils/video'

defineProps({
  photos: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  skeletonCount: { type: Number, default: 8 },
  compact: { type: Boolean, default: false },
})

const { t } = useI18n()
</script>

<template>
  <template v-if="loading">
    <div
      v-for="item in skeletonCount"
      :key="item"
      class="latest-photo rating-skeleton-latest"
      :class="{ 'latest-photo--compact': compact }"
      aria-hidden="true"
    >
      <span class="rating-skeleton-block rating-skeleton-block--hero" />
      <span class="rating-skeleton-block rating-skeleton-block--title" />
    </div>
    <p class="rating-panel-loading">{{ t('loading') }}</p>
  </template>
  <template v-else>
    <RouterLink
      v-for="photo in photos"
      :key="photo.id"
      class="latest-photo"
      :class="{ 'latest-photo--compact': compact }"
      :to="`/photos/${photo.id}`"
    >
      <span class="latest-photo-media">
        <img :src="imageUrl(previewPhotoPath(photo.images))" :alt="photo.title" loading="lazy" />
        <button
          v-if="photo.video"
          type="button"
          class="latest-photo-play"
          :aria-label="t('watchVideo')"
          @click.prevent.stop="playVideo(photo.video, photo.title)"
        >
          <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M8 5v14l11-7z" /></svg>
        </button>
      </span>
      <div class="latest-photo-meta">
        <strong>{{ photo.title }}</strong>
        <small v-if="compact && photo.year">{{ photo.year }}</small>
      </div>
    </RouterLink>
  </template>
</template>

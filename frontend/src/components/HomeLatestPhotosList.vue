<script setup>
import { RouterLink } from 'vue-router'
import { imageUrl, previewPhotoPath } from '../api'
import { useI18n } from '../i18n'

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
      <img :src="imageUrl(previewPhotoPath(photo.images))" :alt="photo.title" loading="lazy" />
      <div class="latest-photo-meta">
        <strong>{{ photo.title }}</strong>
        <small v-if="compact && photo.year">{{ photo.year }}</small>
      </div>
    </RouterLink>
  </template>
</template>

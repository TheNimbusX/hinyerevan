<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, imageUrl, safeAvatarUrl } from '../api'
import { useI18n } from '../i18n'
import { setPageMeta } from '../utils/seo'

const route = useRoute()
const router = useRouter()
const { t, currentLanguage } = useI18n()
const user = ref(null)
const photos = ref([])
const meta = ref(null)
const loading = ref(true)
const loadingMore = ref(false)

const stats = computed(() => ({
  photos_count: user.value?.photos_count ?? 0,
  views_total: user.value?.views_total ?? 0,
  comments_total: user.value?.comments_total ?? 0,
}))

function avatar() {
  return safeAvatarUrl(user.value?.photo)
}

async function load() {
  loading.value = true
  photos.value = []
  try {
    user.value = await api(`/users/${route.params.unique}`)
    const payload = await api(`/photos?user=${user.value.unique}&per_page=18`)
    photos.value = payload.data || []
    meta.value = payload
    setPageMeta({
      title: user.value.name || user.value.uid,
      description: `${user.value.name || user.value.uid} — ${t('photographer')}`,
      image: avatar(),
      path: route.fullPath,
    })
  } catch {
    user.value = null
    setPageMeta({ title: t('userNotFound'), path: route.fullPath, noindex: true })
  } finally {
    loading.value = false
  }
}

async function loadMore() {
  if (!meta.value || meta.value.current_page >= meta.value.last_page || loadingMore.value) return
  loadingMore.value = true
  try {
    const payload = await api(
      `/photos?user=${user.value.unique}&per_page=18&page=${meta.value.current_page + 1}`,
    )
    photos.value = [...photos.value, ...(payload.data || [])]
    meta.value = payload
  } finally {
    loadingMore.value = false
  }
}

function openOnMap() {
  router.push({ path: '/', query: { user: user.value.unique } })
}

onMounted(load)
watch(() => route.params.unique, load)
watch(currentLanguage, load)
</script>

<template>
  <div v-if="loading" class="panel user-loading">{{ t('loading') }}</div>

  <div v-else-if="user" class="user-profile-page">
    <header class="user-hero">
      <img class="user-hero-avatar" :src="avatar()" :alt="user.name || user.uid" />
      <div class="user-hero-body">
        <p class="eyebrow">{{ t('photographer') }}</p>
        <h1>{{ user.name || user.uid }}</h1>
        <p class="user-handle">@{{ user.uid }}<span v-if="user.network"> · {{ user.network }}</span></p>
        <a
          v-if="user.identity && /^https?:/.test(user.identity)"
          class="user-link"
          :href="user.identity"
          target="_blank"
          rel="noopener noreferrer"
        >{{ user.identity }}</a>
      </div>
      <div class="user-hero-stats">
        <div>
          <strong>{{ stats.photos_count }}</strong>
          <span>{{ t('totalPhotos') }}</span>
        </div>
        <div>
          <strong>{{ stats.views_total.toLocaleString() }}</strong>
          <span>{{ t('totalViews') }}</span>
        </div>
        <div>
          <strong>{{ stats.comments_total }}</strong>
          <span>{{ t('totalComments') }}</span>
        </div>
      </div>
    </header>

    <section class="user-section">
      <header class="user-section-head">
        <h2>{{ t('userPhotos') }}</h2>
        <button v-if="stats.photos_count > 0" class="link-button" type="button" @click="openOnMap">
          {{ t('seeMoreOnMap') }} →
        </button>
      </header>
      <p v-if="!photos.length" class="empty">{{ t('noPhotosYet') }}</p>
      <div v-else class="user-photo-grid">
        <RouterLink
          v-for="photo in photos"
          :key="photo.id"
          class="user-photo-tile"
          :to="`/photos/${photo.id}`"
        >
          <img :src="imageUrl(photo.images.large || photo.images.thumb)" :alt="photo.title" loading="lazy" />
          <span class="user-photo-year">{{ photo.year }}</span>
          <span class="user-photo-overlay">
            <strong>{{ photo.title }}</strong>
            <small>{{ photo.views }} {{ t('views') }} · {{ photo.comments_count }} {{ t('comments') }}</small>
          </span>
        </RouterLink>
      </div>
      <div v-if="meta && meta.current_page < meta.last_page" class="user-loadmore">
        <button class="button button-ghost" type="button" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? t('loading') : t('showMore') }}
        </button>
      </div>
    </section>
  </div>

  <div v-else class="panel user-not-found">{{ t('userNotFound') }}</div>
</template>

<style lang="scss">
.user-loading,
.user-not-found {
  margin: 24px auto;
  max-width: 720px;
  text-align: center;
}

.user-profile-page {
  display: grid;
  gap: 22px;
  padding: 0 22px 40px;
  max-width: 1180px;
  margin: 10px auto;

  @include mq-down($bp-md) {
    padding: 0 14px 32px;
  }
}

.user-hero {
  display: grid;
  grid-template-columns: 120px 1fr auto;
  align-items: center;
  gap: 22px;
  padding: 22px 26px;
  border-radius: $radius-xl;
  color: #fff;
  background:
    radial-gradient(circle at 10% 18%, rgba($accent, 0.32), transparent 55%),
    linear-gradient(135deg, #1c3a8a, $primary 60%, #4f74d6);
  box-shadow: $shadow-xl;

  @include mq-down($bp-md) {
    grid-template-columns: auto 1fr;
    padding: 18px;
  }

  @include mq-down($bp-sm) {
    grid-template-columns: 1fr;
    text-align: center;
    justify-items: center;
  }
}

.user-hero-avatar {
  width: 120px;
  height: 120px;
  border-radius: 50%;
  border: 4px solid rgba(255, 255, 255, 0.85);
  object-fit: cover;
  background: #fff;
  box-shadow: 0 16px 36px rgba(7, 21, 60, 0.32);

  @include mq-down($bp-md) {
    width: 96px;
    height: 96px;
  }
}

.user-hero-body {
  display: grid;
  gap: 6px;
  min-width: 0;

  .eyebrow {
    margin: 0;
    color: rgba(255, 255, 255, 0.78);
    font-size: 11px;
    letter-spacing: 0.16em;
  }

  h1 {
    margin: 0;
    color: #fff;
    font-size: clamp(24px, 3.6vw, 34px);
    font-weight: 600;
    letter-spacing: -0.01em;
  }
}

.user-handle {
  margin: 0;
  color: rgba(255, 255, 255, 0.74);
  font-size: 13px;
}

.user-link {
  display: inline-block;
  margin-top: 4px;
  color: #ffd9b0;
  font-size: 12px;
  word-break: break-all;
  @include interactive((color));

  &:hover {
    color: #fff;
    text-decoration: underline;
  }
}

.user-hero-stats {
  display: grid;
  grid-template-columns: repeat(3, auto);
  gap: 14px;
  padding: 14px 18px;
  border-radius: $radius-lg;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.18);
  backdrop-filter: blur(10px);
  text-align: center;

  @include mq-down($bp-md) {
    grid-column: 1 / -1;
  }

  div {
    display: grid;
    gap: 2px;
  }

  strong {
    font-size: 20px;
    font-weight: 600;
    color: #fff;
    font-variant-numeric: tabular-nums;
  }

  span {
    color: rgba(255, 255, 255, 0.72);
    font-size: 11px;
    letter-spacing: 0.06em;
    text-transform: uppercase;
  }
}

.user-section {
  display: grid;
  gap: 14px;
  padding: 22px;
  border-radius: $radius-xl - 4;
  background: $surface;
  box-shadow: $shadow-lg;
}

.user-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  h2 {
    margin: 0;
    font-size: 18px;
  }
}

.user-photo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 12px;
}

.user-photo-tile {
  position: relative;
  overflow: hidden;
  border-radius: $radius-sm + 2;
  background: $surface-soft;
  color: inherit;
  text-decoration: none;
  box-shadow: $shadow-sm;
  @include hover-lift(-3px, 0 16px 30px rgba(20, 45, 110, 0.22));

  &:focus-visible {
    outline: 2px solid rgba($primary, 0.45);
    outline-offset: 2px;
  }

  img {
    display: block;
    width: 100%;
    height: 180px;
    object-fit: cover;
    @include interactive((transform));
  }

  &:hover img {
    transform: scale(1.06);
  }
}

.user-photo-year {
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

.user-photo-overlay {
  position: absolute;
  inset: auto 0 0 0;
  display: grid;
  gap: 2px;
  padding: 20px 12px 10px;
  background: linear-gradient(180deg, transparent, rgba(8, 22, 60, 0.85));
  color: #fff;

  strong {
    font-size: 13px;
    font-weight: 600;
    @include truncate;
  }

  small {
    font-size: 11px;
    opacity: 0.78;
  }
}

.user-loadmore {
  display: flex;
  justify-content: center;
}
</style>

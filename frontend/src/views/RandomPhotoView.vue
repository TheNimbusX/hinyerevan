<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api, imageUrl, safeAvatarUrl } from '../api'
import { useI18n } from '../i18n'
import { directionLabel, formatDateTime } from '../utils/locale'
import { userDisplayName, userProfilePath } from '../utils/user'
import { photoDisplayLikes } from '../utils/photoStats'

const { t, currentLanguage } = useI18n()
const photo = ref(null)
const loading = ref(false)
const error = ref('')

const photoImage = computed(() =>
  photo.value ? imageUrl(photo.value.images.large || photo.value.images.thumb) : '',
)
const photoDirection = computed(() =>
  photo.value ? directionLabel(photo.value.direction, t) : '',
)
const addedLabel = computed(() =>
  photo.value ? formatDateTime(photo.value.datetime, currentLanguage.value) : '',
)

async function loadRandom() {
  loading.value = true
  error.value = ''
  try {
    photo.value = await api('/photos/random')
  } catch (event) {
    error.value = event.message
  } finally {
    loading.value = false
  }
}

onMounted(loadRandom)
</script>

<template>
  <section class="random-page">
    <header class="random-head">
      <div class="random-head-top">
        <p class="eyebrow">{{ t('randomPhoto') }}</p>
        <button class="button random-shuffle" type="button" :disabled="loading" @click="loadRandom">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path
              d="M18 4l3 3-3 3M18 14l3 3-3 3M3 7h4.5c1.2 0 2.3.6 3 1.6M21 7h-5c-1.6 0-3 .9-3.7 2.3M3 17h4.5c1.2 0 2.3-.6 3-1.6M21 17h-5"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
          <span>{{ t('anotherRandomPhoto') }}</span>
        </button>
      </div>
      <h1>{{ error || (photo ? photo.title : t('loading')) }}</h1>
    </header>

    <div v-if="error" class="random-error panel">{{ error }}</div>

    <RouterLink
      v-else-if="photo"
      class="random-card panel"
      :class="{ 'is-loading': loading }"
      :to="`/photos/${photo.id}`"
    >
      <div class="random-stage">
        <img :src="photoImage" :alt="photo.title" />
        <span class="random-year">{{ photo.year }}</span>
        <span v-if="photo.video" class="random-video" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="14" height="14"><path d="M8 5v14l11-7z" fill="currentColor" /></svg>
          video
        </span>
        <div class="random-overlay">
          <strong>{{ photo.title }}</strong>
          <span v-if="photoDirection" class="random-direction">{{ photoDirection }}</span>
        </div>
      </div>

      <footer class="random-meta">
        <span class="random-author">
          <img :src="safeAvatarUrl(photo.author?.photo)" :alt="userDisplayName(photo.author, t)" />
          <span>
            <small>{{ t('photographer') }}</small>
            {{ userDisplayName(photo.author, t) }}
          </span>
        </span>

        <span class="random-stats">
          <span class="random-stat" :title="t('views')">
            <svg viewBox="0 0 24 24" width="15" height="15"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
            {{ photo.views || 0 }}
          </span>
          <span class="random-stat" :title="t('comments')">
            <svg viewBox="0 0 24 24" width="15" height="15"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
            {{ photo.comments_count || 0 }}
          </span>
          <span class="random-stat" :title="t('likes')">
            <svg viewBox="0 0 24 24" width="15" height="15"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
            {{ photoDisplayLikes(photo) }}
          </span>
        </span>

        <span v-if="addedLabel" class="random-date">{{ t('addedOn') }}: {{ addedLabel }}</span>
      </footer>
    </RouterLink>

    <div v-else class="random-card panel is-skeleton">
      <div class="random-stage"><span class="random-shimmer" aria-hidden="true"></span></div>
    </div>
  </section>
</template>

<style lang="scss">
.random-page {
  display: grid;
  gap: 22px;
  max-width: 880px;
  margin-inline: auto;
}

.random-head {
  display: grid;
  gap: 10px;

  h1 {
    margin: 0;
  }
}

.random-head-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.random-shuffle {
  flex: none;
  display: inline-flex;
  align-items: center;
  gap: 9px;

  svg {
    flex: none;
  }
}

.random-error {
  padding: 24px;
  color: $danger;
  font-weight: 600;
}

.random-card {
  display: grid;
  gap: 0;
  padding: 0;
  overflow: hidden;
  text-decoration: none;
  color: inherit;
  transition:
    transform 0.25s ease,
    box-shadow 0.25s ease;

  &:not(.is-skeleton):hover {
    transform: translateY(-3px);
    box-shadow: $shadow-xl;

    .random-stage img {
      transform: scale(1.03);
    }
  }
}

.random-stage {
  position: relative;
  aspect-ratio: 16 / 10;
  background: $primary-light;
  overflow: hidden;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s ease;
  }
}

.random-year {
  position: absolute;
  top: 14px;
  left: 14px;
  padding: 5px 13px;
  border-radius: $radius-pill;
  background: $accent;
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
}

.random-video {
  position: absolute;
  top: 14px;
  right: 14px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 11px 4px 8px;
  border-radius: $radius-pill;
  background: rgba(15, 18, 26, 0.72);
  color: #fff;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.random-overlay {
  position: absolute;
  inset: auto 0 0 0;
  display: grid;
  gap: 6px;
  padding: 48px 20px 18px;
  background: linear-gradient(to top, rgba(8, 10, 16, 0.86) 10%, rgba(8, 10, 16, 0) 100%);
  color: #fff;

  strong {
    font-size: clamp(18px, 3vw, 23px);
    font-weight: 700;
    line-height: 1.3;
  }
}

.random-direction {
  justify-self: start;
  padding: 3px 11px;
  border-radius: $radius-pill;
  background: rgba(255, 255, 255, 0.18);
  backdrop-filter: blur(4px);
  font-size: 12px;
  font-weight: 600;
}

.random-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 16px;
  padding: 16px 20px;
}

.random-author {
  display: inline-flex;
  align-items: center;
  gap: 11px;
  font-weight: 600;
  color: $ink;

  img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid $line;
  }

  small {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: $muted;
  }
}

.random-stats {
  display: inline-flex;
  gap: 14px;
  margin-left: auto;
}

.random-stat {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: $muted;
  font-size: 14px;
  font-weight: 600;

  svg {
    color: $muted-soft;
  }
}

.random-date {
  width: 100%;
  padding-top: 12px;
  border-top: 1px solid $line;
  font-size: 13px;
  color: $muted;
}

.random-card.is-skeleton .random-stage {
  position: relative;
}

.random-shimmer {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    100deg,
    $primary-light 30%,
    rgba(255, 255, 255, 0.6) 50%,
    $primary-light 70%
  );
  background-size: 220% 100%;
  animation: random-shimmer 1.2s ease-in-out infinite;
}

@keyframes random-shimmer {
  0% {
    background-position: 220% 0;
  }
  100% {
    background-position: -220% 0;
  }
}

@media (max-width: 540px) {
  .random-stats {
    margin-left: 0;
  }
}
</style>

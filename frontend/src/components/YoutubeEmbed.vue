<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from '../i18n'
import { youtubeEmbedUrl, youtubeThumb, youtubeWatchUrl } from '../utils/video'

const props = defineProps({
  url: { type: String, default: '' },
  title: { type: String, default: '' },
})

const { t } = useI18n()
const activated = ref(false)

const thumb = computed(() => youtubeThumb(props.url))
const watchUrl = computed(() => youtubeWatchUrl(props.url))
const embedUrl = computed(() => youtubeEmbedUrl(props.url, { autoplay: true }))

// Reset the facade when a different video is shown (e.g. switching photos in the sheet).
watch(() => props.url, () => {
  activated.value = false
})

function activate() {
  activated.value = true
}
</script>

<template>
  <div class="yt-embed">
    <div class="yt-embed-frame">
      <iframe
        v-if="activated"
        :src="embedUrl"
        :title="title || 'YouTube video'"
        frameborder="0"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
        allowfullscreen
      ></iframe>
      <button
        v-else
        type="button"
        class="yt-embed-facade"
        :style="thumb ? { backgroundImage: `url(${thumb})` } : null"
        :aria-label="t('watchVideo')"
        @click="activate"
      >
        <span class="yt-embed-play" aria-hidden="true">
          <svg viewBox="0 0 68 48" width="68" height="48">
            <path class="yt-embed-play-bg" d="M66.5 7.7a8 8 0 0 0-5.6-5.7C56 .5 34 .5 34 .5s-22 0-26.9 1.5a8 8 0 0 0-5.6 5.7A83 83 0 0 0 0 24a83 83 0 0 0 1.5 16.3 8 8 0 0 0 5.6 5.7C12 47.5 34 47.5 34 47.5s22 0 26.9-1.5a8 8 0 0 0 5.6-5.7A83 83 0 0 0 68 24a83 83 0 0 0-1.5-16.3z" />
            <path d="M45 24 27 14v20z" fill="#fff" />
          </svg>
        </span>
      </button>
    </div>
    <a
      v-if="watchUrl"
      class="yt-embed-link"
      :href="watchUrl"
      target="_blank"
      rel="noopener noreferrer"
    >
      {{ t('openOnYoutube') }}
    </a>
  </div>
</template>

<style lang="scss">
.yt-embed {
  display: grid;
  gap: 8px;
}

.yt-embed-frame {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  border-radius: $radius-md;
  overflow: hidden;
  background: #000;

  iframe {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
  }
}

.yt-embed-facade {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
  padding: 0;
  border: 0;
  background-color: #000;
  background-position: center;
  background-size: cover;
  cursor: pointer;

  &::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(0, 0, 0, 0.05), rgba(0, 0, 0, 0.35));
    transition: background $duration ease;
  }

  &:hover::after {
    background: rgba(0, 0, 0, 0.2);
  }

  @include focus-ring(rgba(255, 255, 255, 0.7), -3px);
}

.yt-embed-play {
  position: relative;
  z-index: 1;
  display: grid;
  place-items: center;
  filter: drop-shadow(0 6px 16px rgba(0, 0, 0, 0.4));
  transition: transform $duration $ease;

  .yt-embed-play-bg {
    fill: #f00;
    transition: fill $duration ease;
  }

  .yt-embed-facade:hover & {
    transform: scale(1.08);

    .yt-embed-play-bg {
      fill: #f00;
    }
  }
}

.yt-embed-link {
  justify-self: start;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: $muted;
  font-size: 12px;
  font-weight: 500;
  text-decoration: none;

  &::before {
    content: '▶';
    font-size: 10px;
    color: #f00;
  }

  &:hover {
    color: $primary;
    text-decoration: underline;
  }
}
</style>

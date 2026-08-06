<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from '../i18n'
import YoutubeEmbed from './YoutubeEmbed.vue'

const { t } = useI18n()
const open = ref(false)
const url = ref('')
const title = ref('')

function show(event) {
  const detail = event?.detail || {}
  if (!detail.url) return
  url.value = detail.url
  title.value = detail.title || ''
  open.value = true
}

function close() {
  open.value = false
  url.value = ''
  title.value = ''
}

function onKey(event) {
  if (event.key === 'Escape' && open.value) close()
}

onMounted(() => {
  window.addEventListener('hinyerevan:play-video', show)
  window.addEventListener('keydown', onKey)
})

onBeforeUnmount(() => {
  window.removeEventListener('hinyerevan:play-video', show)
  window.removeEventListener('keydown', onKey)
})
</script>

<template>
  <Teleport to="body">
    <transition name="video-lightbox-fade">
      <div v-if="open" class="video-lightbox" @click.self="close">
        <div class="video-lightbox__inner">
          <button type="button" class="video-lightbox__close" :aria-label="t('cancel')" @click="close">×</button>
          <p v-if="title" class="video-lightbox__title">{{ title }}</p>
          <YoutubeEmbed :url="url" :title="title" />
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<style lang="scss">
.video-lightbox {
  position: fixed;
  inset: 0;
  z-index: 4000;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgba(0, 0, 0, 0.82);
  backdrop-filter: blur(4px);
}

.video-lightbox__inner {
  position: relative;
  width: min(960px, 100%);
}

.video-lightbox__title {
  margin: 0 0 10px;
  padding-right: 44px;
  color: #fff;
  font-size: 16px;
  font-weight: 600;
  line-height: 1.3;
}

.video-lightbox__close {
  position: absolute;
  top: -44px;
  right: 0;
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border: 0;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  font-size: 26px;
  line-height: 1;
  cursor: pointer;
  transition: background 0.15s ease;

  &:hover {
    background: rgba(255, 255, 255, 0.28);
  }
}

.video-lightbox-fade-enter-active,
.video-lightbox-fade-leave-active {
  transition: opacity 0.18s ease;
}

.video-lightbox-fade-enter-from,
.video-lightbox-fade-leave-to {
  opacity: 0;
}
</style>

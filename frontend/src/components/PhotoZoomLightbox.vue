<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from '../i18n'

const props = defineProps({
  open: { type: Boolean, default: false },
  src: { type: String, default: '' },
  alt: { type: String, default: '' },
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
})

const emit = defineEmits(['close'])

const { t } = useI18n()

const MIN_SCALE = 1
const MAX_SCALE = 6
const SCALE_STEP = 0.25

const scale = ref(1)
const translateX = ref(0)
const translateY = ref(0)
const dragging = ref(false)
const dragStart = ref({ x: 0, y: 0, tx: 0, ty: 0 })

const imageTransform = computed(
  () => `translate(${translateX.value}px, ${translateY.value}px) scale(${scale.value})`,
)

function resetZoom() {
  scale.value = 1
  translateX.value = 0
  translateY.value = 0
  dragging.value = false
}

function clampScale(value) {
  return Math.min(MAX_SCALE, Math.max(MIN_SCALE, value))
}

function close() {
  emit('close')
}

function zoomIn() {
  scale.value = clampScale(scale.value + SCALE_STEP)
}

function zoomOut() {
  const next = clampScale(scale.value - SCALE_STEP)
  scale.value = next
  if (next <= MIN_SCALE) {
    translateX.value = 0
    translateY.value = 0
  }
}

function onWheel(event) {
  const delta = event.deltaY > 0 ? -0.12 : 0.12
  const next = clampScale(scale.value + delta)
  scale.value = next
  if (next <= MIN_SCALE) {
    translateX.value = 0
    translateY.value = 0
  }
}

function onPointerDown(event) {
  if (scale.value <= 1 || event.button !== 0) return
  dragging.value = true
  dragStart.value = {
    x: event.clientX,
    y: event.clientY,
    tx: translateX.value,
    ty: translateY.value,
  }
  event.currentTarget.setPointerCapture(event.pointerId)
}

function onPointerMove(event) {
  if (!dragging.value) return
  translateX.value = dragStart.value.tx + (event.clientX - dragStart.value.x)
  translateY.value = dragStart.value.ty + (event.clientY - dragStart.value.y)
}

function stopDragging(event) {
  if (!dragging.value) return
  dragging.value = false
  event.currentTarget.releasePointerCapture?.(event.pointerId)
}

function onKeydown(event) {
  if (!props.open) return
  if (event.key === 'Escape') {
    close()
    return
  }
  if (event.key === '+' || event.key === '=') {
    event.preventDefault()
    zoomIn()
  }
  if (event.key === '-') {
    event.preventDefault()
    zoomOut()
  }
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      resetZoom()
      document.body.style.overflow = 'hidden'
      window.addEventListener('keydown', onKeydown)
    } else {
      document.body.style.overflow = ''
      window.removeEventListener('keydown', onKeydown)
    }
  },
)

onBeforeUnmount(() => {
  document.body.style.overflow = ''
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <Teleport to="body">
    <transition name="lightbox">
      <div v-if="open" class="photo-lightbox" @click.self="close">
        <button class="lightbox-close" type="button" :aria-label="t('cancel')" @click="close">×</button>
        <div class="lightbox-toolbar">
          <button
            type="button"
            class="lightbox-zoom-btn"
            :aria-label="t('zoomOut')"
            :disabled="scale <= MIN_SCALE"
            @click="zoomOut"
          >
            −
          </button>
          <button
            type="button"
            class="lightbox-zoom-btn lightbox-zoom-btn--primary"
            :aria-label="t('zoomIn')"
            :disabled="scale >= MAX_SCALE"
            @click="zoomIn"
          >
            +
          </button>
        </div>
        <figure class="lightbox-figure">
          <div
            class="lightbox-viewport"
            :class="{ 'is-dragging': dragging, 'is-zoomed': scale > 1 }"
            @wheel.prevent="onWheel"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="stopDragging"
            @pointercancel="stopDragging"
          >
            <img
              :src="src"
              :alt="alt"
              :style="{ transform: imageTransform }"
              draggable="false"
            />
          </div>
          <figcaption v-if="title || subtitle">
            <strong v-if="title">{{ title }}</strong>
            <span v-if="subtitle">{{ subtitle }}</span>
          </figcaption>
        </figure>
      </div>
    </transition>
  </Teleport>
</template>

<style lang="scss">
.photo-lightbox {
  position: fixed;
  inset: 0;
  z-index: 1100;
  display: flex;
  flex-direction: column;
  padding: 0;
  background: rgba(8, 11, 18, 0.96);
  backdrop-filter: blur(8px);
  cursor: default;
}

.lightbox-close {
  position: absolute;
  top: 16px;
  right: 16px;
  z-index: 3;
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border: 0;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  cursor: pointer;
  font-size: 28px;
  line-height: 0;
  @include interactive((background, transform));

  &:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: rotate(90deg);
  }

  @include focus-ring(rgba(255, 255, 255, 0.55), 2px);
}

.lightbox-toolbar {
  position: absolute;
  top: 16px;
  left: 16px;
  z-index: 3;
  display: inline-flex;
  gap: 8px;
}

.lightbox-zoom-btn {
  display: grid;
  place-items: center;
  min-width: 44px;
  height: 44px;
  padding: 0 12px;
  border: 0;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  cursor: pointer;
  font-size: 24px;
  font-weight: 500;
  line-height: 1;
  @include interactive((background, transform));

  &:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.2);
  }

  &:disabled {
    opacity: 0.35;
    cursor: default;
  }

  &--primary {
    font-size: 28px;
    font-weight: 400;
  }

  @include focus-ring(rgba(255, 255, 255, 0.55), 2px);
}

.lightbox-figure {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 12px;
  min-height: 0;
  margin: 0;
  padding: 72px 16px 20px;
  cursor: default;

  @include mq-down($bp-sm) {
    padding: 68px 10px 14px;
    gap: 10px;
  }
}

.lightbox-viewport {
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
  min-height: 0;
  overflow: hidden;
  touch-action: none;
  cursor: default;

  &.is-zoomed {
    cursor: grab;
  }

  &.is-dragging {
    cursor: grabbing;
  }

  img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    transform-origin: center center;
    user-select: none;
    -webkit-user-drag: none;
    will-change: transform;
  }
}

.lightbox-figure figcaption {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: baseline;
  justify-content: center;
  flex-shrink: 0;
  color: rgba(255, 255, 255, 0.92);

  strong {
    font-family: $font-serif;
    font-weight: 500;
    font-size: 18px;
  }

  span {
    color: rgba(255, 255, 255, 0.6);
    font-size: 13px;
  }
}

.lightbox-enter-active,
.lightbox-leave-active {
  transition: opacity $duration ease;
}

.lightbox-enter-from,
.lightbox-leave-to {
  opacity: 0;
}
</style>

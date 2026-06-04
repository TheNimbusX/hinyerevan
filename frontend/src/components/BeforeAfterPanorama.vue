<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { imageUrl } from '../api'

const props = defineProps({
  lat: { type: [Number, String], default: null },
  lng: { type: [Number, String], default: null },
  direction: { type: [Number, String], default: 0 },
  oldImage: { type: String, default: '' },
  title: { type: String, default: '' },
  t: { type: Function, required: true },
})

// Yandex JS API keys are public client keys (always visible in the browser);
// protection is the HTTP-referrer restriction set in the Yandex developer cabinet.
const API_KEY = import.meta.env.VITE_YANDEX_MAPS_KEY || '4deda5a2-ba1c-445c-88bb-8837947e46f2'

const status = ref('idle') // idle | loading | ready | none | error
const pos = ref(50)
const dragging = ref(false)
const paneEl = ref(null)
const compareEl = ref(null)
let player = null

const lat = computed(() => Number(props.lat))
const lng = computed(() => Number(props.lng))
const hasCoords = computed(() => Number.isFinite(lat.value) && Number.isFinite(lng.value))

// direction: 0 = aerial (no heading), 1=N … 8=NW → degrees clockwise from north.
const heading = computed(() => {
  const d = Number(props.direction)
  return Number.isInteger(d) && d >= 1 && d <= 8 ? ((d - 1) * 45) % 360 : 0
})

const oldSrc = computed(() => imageUrl(props.oldImage))

const yandexMapsLink = computed(() => {
  if (!hasCoords.value) return ''
  const point = `${lng.value}%2C${lat.value}`
  return `https://yandex.ru/maps/?ll=${point}&panorama%5Bpoint%5D=${point}&panorama%5Bdirection%5D=${heading.value}%2C0&l=stv%2Csta&z=18`
})

let apiPromise = null
function loadYandexApi() {
  if (window.ymaps && window.ymaps.panorama) {
    return new Promise((resolve) => window.ymaps.ready(() => resolve(window.ymaps)))
  }
  if (apiPromise) return apiPromise

  apiPromise = new Promise((resolve, reject) => {
    const ready = () => window.ymaps.ready(() => resolve(window.ymaps))
    const existing = document.getElementById('ymaps-api')
    if (existing) {
      existing.addEventListener('load', ready, { once: true })
      existing.addEventListener('error', reject, { once: true })
      return
    }
    const script = document.createElement('script')
    script.id = 'ymaps-api'
    script.async = true
    script.src = `https://api-maps.yandex.ru/2.1/?apikey=${API_KEY}&lang=ru_RU`
    script.onload = ready
    script.onerror = reject
    document.head.appendChild(script)
  })
  return apiPromise
}

async function reveal() {
  if (!hasCoords.value || status.value === 'loading' || status.value === 'ready') return
  status.value = 'loading'

  try {
    const ymaps = await loadYandexApi()
    if (!ymaps.panorama || !ymaps.panorama.isSupported()) {
      status.value = 'none'
      return
    }

    const panoramas = await ymaps.panorama.locate([lat.value, lng.value])
    if (!panoramas || !panoramas.length) {
      status.value = 'none'
      return
    }

    status.value = 'ready'
    await nextTick()
    if (!paneEl.value) return

    player = new ymaps.panorama.Player(paneEl.value, panoramas[0], {
      direction: [heading.value, 0],
      controls: ['zoomControl'],
      suppressMapOpenBlock: true,
      hotkeys: false,
    })
  } catch {
    status.value = 'error'
  }
}

function posFromClientX(clientX) {
  if (!compareEl.value) return pos.value
  const rect = compareEl.value.getBoundingClientRect()
  const x = Math.min(Math.max(clientX - rect.left, 0), rect.width)
  return rect.width ? (x / rect.width) * 100 : pos.value
}

function onPointerMove(event) {
  if (!dragging.value) return
  pos.value = posFromClientX(event.clientX)
}

function endDrag() {
  if (!dragging.value) return
  dragging.value = false
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerup', endDrag)
  window.removeEventListener('pointercancel', endDrag)
}

function startDrag(event) {
  dragging.value = true
  pos.value = posFromClientX(event.clientX)
  window.addEventListener('pointermove', onPointerMove)
  window.addEventListener('pointerup', endDrag)
  window.addEventListener('pointercancel', endDrag)
  event.preventDefault()
}

function nudge(step) {
  pos.value = Math.min(100, Math.max(0, pos.value + step))
}

onBeforeUnmount(() => {
  endDrag()
  if (player) {
    try {
      player.destroy()
    } catch {
      // player already torn down
    }
    player = null
  }
})
</script>

<template>
  <section v-if="hasCoords" class="panel before-after">
    <header class="before-after__head">
      <h2>{{ t('beforeAfterTitle') }}</h2>
      <p class="before-after__sub">{{ t('beforeAfterSubtitle') }}</p>
    </header>

    <!-- Fixed-ratio frame so the layout never jumps between states. -->
    <div class="ba-frame">
      <!-- Compare; kept in the DOM (v-show) so the player mounts into a sized element. -->
      <div
        v-show="status === 'ready'"
        ref="compareEl"
        class="ba-compare"
        :class="{ dragging }"
        :style="{ '--pos': pos + '%' }"
      >
        <div ref="paneEl" class="ba-pane"></div>
        <div class="ba-old" aria-hidden="true">
          <img :src="oldSrc" :alt="title" />
        </div>
        <span class="ba-tag ba-tag--old">{{ t('beforeAfterOld') }}</span>
        <span class="ba-tag ba-tag--now">{{ t('beforeAfterNow') }}</span>
        <div
          class="ba-divider"
          role="slider"
          tabindex="0"
          :aria-valuenow="Math.round(pos)"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-label="t('beforeAfterTitle')"
          @pointerdown="startDrag"
          @keydown.left.prevent="nudge(-4)"
          @keydown.right.prevent="nudge(4)"
        >
          <span class="ba-knob" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="16" height="16">
              <path
                d="M9 6l-5 6 5 6M15 6l5 6-5 6"
                fill="none"
                stroke="currentColor"
                stroke-width="2.2"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </span>
        </div>
      </div>

      <!-- Until ready, keep the old photo on screen so nothing collapses. -->
      <template v-if="status !== 'ready'">
        <img class="ba-base" :src="oldSrc" :alt="title" />
        <span class="ba-base-veil" aria-hidden="true"></span>

        <button v-if="status === 'idle'" type="button" class="ba-cta" @click="reveal">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path
              d="M3 12h7M14 12h7M10 5l-7 7 7 7M14 5l7 7-7 7"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
          <span>{{ t('beforeAfterCta') }}</span>
        </button>

        <div v-else-if="status === 'loading'" class="ba-overlay">
          <span class="ba-spinner" aria-hidden="true"></span>
          <span>{{ t('beforeAfterLoading') }}</span>
        </div>

        <div v-else class="ba-overlay">
          <p>{{ status === 'none' ? t('beforeAfterNone') : t('beforeAfterError') }}</p>
          <a
            v-if="status === 'none'"
            :href="yandexMapsLink"
            target="_blank"
            rel="noopener"
            class="ba-link"
          >{{ t('beforeAfterOpenYandex') }} →</a>
          <button v-else type="button" class="ba-link" @click="reveal">{{ t('beforeAfterRetry') }}</button>
        </div>
      </template>
    </div>

    <p v-show="status === 'ready'" class="before-after__hint">{{ t('beforeAfterHint') }}</p>
  </section>
</template>

<style lang="scss">
.before-after {
  display: grid;
  gap: 14px;
}

.before-after__head {
  display: grid;
  gap: 2px;

  h2 {
    margin: 0;
    font-size: 18px;
  }
}

.before-after__sub {
  margin: 0;
  color: $muted;
  font-size: 13px;
}

.before-after__hint {
  margin: 0;
  color: $muted;
  font-size: 12px;
  text-align: center;
}

/* Frame: holds a stable box across every state -------------------- */
.ba-frame {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 10;
  max-height: 70vh;
  border-radius: $radius-lg;
  overflow: hidden;
  background: $surface-soft;
  user-select: none;

  @include mq-down($bp-sm) {
    aspect-ratio: 4 / 3;
    border-radius: $radius-md;
  }
}

.ba-base {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.ba-base-veil {
  position: absolute;
  inset: 0;
  background: linear-gradient(180deg, rgba(8, 16, 36, 0.08), rgba(8, 16, 36, 0.5));
}

/* Call to action -------------------------------------------------- */
.ba-cta {
  position: absolute;
  left: 50%;
  bottom: 16px;
  transform: translateX(-50%);
  display: inline-flex;
  align-items: center;
  gap: 8px;
  max-width: calc(100% - 28px);
  padding: 10px 18px;
  border: 0;
  border-radius: $radius-pill;
  background: rgba(255, 255, 255, 0.96);
  color: $primary-dark;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.2;
  text-align: left;
  cursor: pointer;
  box-shadow: 0 10px 26px rgba(7, 21, 60, 0.24);
  backdrop-filter: blur(6px);
  @include interactive((transform, box-shadow));

  svg {
    flex-shrink: 0;
  }

  &:hover {
    transform: translateX(-50%) translateY(-1px);
    box-shadow: 0 14px 30px rgba(7, 21, 60, 0.3);
  }

  @include focus-ring(rgba($primary, 0.5), 3px);

  @include mq-down($bp-sm) {
    bottom: 10px;
    gap: 6px;
    padding: 7px 12px;
    font-size: 12px;

    svg {
      width: 15px;
      height: 15px;
    }
  }
}

/* Loading / empty / error overlay --------------------------------- */
.ba-overlay {
  position: absolute;
  inset: 0;
  display: grid;
  justify-items: center;
  align-content: center;
  gap: 10px;
  padding: 20px;
  text-align: center;
  color: #fff;
  background: rgba(8, 16, 36, 0.42);
  backdrop-filter: blur(2px);

  p {
    margin: 0;
    font-weight: 500;
  }
}

.ba-link {
  border: 0;
  background: none;
  padding: 0;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  text-decoration: underline;
  cursor: pointer;
}

.ba-spinner {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  border: 3px solid rgba(255, 255, 255, 0.35);
  border-top-color: #fff;
  animation: baSpin 0.7s linear infinite;
}

@keyframes baSpin {
  to {
    transform: rotate(360deg);
  }
}

/* Compare slider -------------------------------------------------- */
.ba-compare {
  position: absolute;
  inset: 0;
  z-index: 2;
  background: #0d1018;
  touch-action: pan-y;
}

.ba-pane {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}

/* While dragging, stop the panorama from grabbing pointer moves. */
.ba-compare.dragging .ba-pane {
  pointer-events: none;
}

.ba-old {
  position: absolute;
  inset: 0;
  z-index: 3;
  clip-path: inset(0 calc(100% - var(--pos)) 0 0);
  pointer-events: none;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.ba-tag {
  position: absolute;
  top: 12px;
  z-index: 4;
  padding: 4px 11px;
  border-radius: $radius-pill;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #fff;
  pointer-events: none;
  backdrop-filter: blur(4px);

  &--old {
    left: 12px;
    background: rgba(8, 16, 36, 0.62);
  }

  &--now {
    right: 12px;
    background: rgba(35, 116, 225, 0.78);
  }
}

.ba-divider {
  position: absolute;
  top: 0;
  bottom: 0;
  left: var(--pos);
  z-index: 5;
  width: 3px;
  margin-left: -1.5px;
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 0 0 1px rgba(8, 16, 36, 0.18);
  cursor: ew-resize;
  touch-action: none;

  @include focus-ring(rgba($primary, 0.5), 2px);
}

.ba-knob {
  position: absolute;
  top: 50%;
  left: 50%;
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  transform: translate(-50%, -50%);
  border-radius: 50%;
  background: #fff;
  color: $primary-dark;
  box-shadow: 0 6px 18px rgba(7, 21, 60, 0.34);
}

/* Yandex panorama chrome tweaks (scoped to our pane) -------------- */
.ba-pane [class*='panorama-control__copyright'] {
  display: none !important;
}

.ba-pane [class*='islets_round-button'],
.ba-pane [class*='islets_round-button__icon'] {
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
}

@include mq-down($bp-sm) {
  .ba-pane [class*='gotoymaps-container'] {
    display: none !important;
  }
}
</style>

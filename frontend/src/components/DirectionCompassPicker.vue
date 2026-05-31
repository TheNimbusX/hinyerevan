<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from '../i18n'
import CompassNeedle from './CompassNeedle.vue'

const props = defineProps({
  modelValue: {
    type: Number,
    default: 1,
  },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const dialRef = ref(null)
const dragging = ref(false)
const compassOpen = ref(false)

const ringDirections = [
  { value: 1, label: 'north', short: 'N' },
  { value: 2, label: 'northEast', short: 'NE' },
  { value: 3, label: 'east', short: 'E' },
  { value: 4, label: 'southEast', short: 'SE' },
  { value: 5, label: 'south', short: 'S' },
  { value: 6, label: 'southWest', short: 'SW' },
  { value: 7, label: 'west', short: 'W' },
  { value: 8, label: 'northWest', short: 'NW' },
]

const chipDirections = [
  ...ringDirections,
  { value: 0, label: 'topShot', short: '↑' },
]

const activeLabel = computed(() => {
  const item = chipDirections.find((entry) => entry.value === props.modelValue)
  return item ? t(item.label) : ''
})

function select(value) {
  emit('update:modelValue', value)
}

function directionFromPointer(clientX, clientY) {
  const node = dialRef.value
  if (!node) return props.modelValue

  const rect = node.getBoundingClientRect()
  const cx = rect.left + rect.width / 2
  const cy = rect.top + rect.height / 2
  const dx = clientX - cx
  const dy = clientY - cy
  const distance = Math.hypot(dx, dy)

  if (distance < rect.width * 0.14) {
    return 0
  }

  let degrees = (Math.atan2(dx, -dy) * 180) / Math.PI
  if (degrees < 0) degrees += 360
  const sector = Math.round(degrees / 45) % 8
  const order = [1, 2, 3, 4, 5, 6, 7, 8]

  return order[sector]
}

function onPointerDown(event) {
  dragging.value = true
  select(directionFromPointer(event.clientX, event.clientY))
  window.addEventListener('pointermove', onPointerMove)
  window.addEventListener('pointerup', onPointerUp)
}

function onPointerMove(event) {
  if (!dragging.value) return
  select(directionFromPointer(event.clientX, event.clientY))
}

function onPointerUp() {
  dragging.value = false
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerup', onPointerUp)
}

onBeforeUnmount(() => {
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerup', onPointerUp)
})
</script>

<template>
  <div class="direction-compass-picker">
    <button
      type="button"
      class="compass-toggle"
      :aria-expanded="compassOpen"
      @click="compassOpen = !compassOpen"
    >
      <span class="compass-toggle-preview" aria-hidden="true">
        <CompassNeedle :direction="modelValue" size="sm" />
      </span>
      <span class="compass-toggle-copy">
        <strong>{{ activeLabel }}</strong>
        <small>{{ compassOpen ? t('hideCompass') : t('showCompass') }}</small>
      </span>
      <svg class="compass-toggle-caret" viewBox="0 0 12 12" aria-hidden="true">
        <path d="M3 4.5 6 7.5l3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </button>

    <transition name="compass-expand">
      <div v-if="compassOpen" class="compass-panel">
        <div
          ref="dialRef"
          class="compass-dial"
          :class="{ dragging }"
          role="group"
          :aria-label="t('direction')"
          @pointerdown.prevent="onPointerDown"
        >
          <button
            v-for="item in ringDirections"
            :key="item.value"
            type="button"
            class="compass-wedge"
            :class="[`compass-wedge-${item.value}`, { active: modelValue === item.value }]"
            :aria-label="t(item.label)"
            :aria-pressed="modelValue === item.value"
            @pointerdown.stop
            @click.stop="select(item.value)"
          >
            <span>{{ item.short }}</span>
          </button>

          <button
            type="button"
            class="compass-center"
            :class="{ active: modelValue === 0 }"
            :aria-label="t('topShot')"
            :aria-pressed="modelValue === 0"
            @pointerdown.stop
            @click.stop="select(0)"
          >
            <CompassNeedle :direction="modelValue" size="lg" />
          </button>
        </div>
        <p class="compass-hint">{{ t('directionCompassHint') }}</p>
      </div>
    </transition>

    <div class="direction-chips" role="listbox" :aria-label="t('direction')">
      <button
        v-for="item in chipDirections"
        :key="item.value"
        type="button"
        role="option"
        class="direction-chip"
        :class="{ active: modelValue === item.value }"
        :aria-selected="modelValue === item.value"
        @click="select(item.value)"
      >
        {{ t(item.label) }}
      </button>
    </div>
  </div>
</template>

<style lang="scss">
.direction-compass-picker {
  display: grid;
  gap: 10px;
}

.compass-toggle {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 10px 12px;
  border: 1px solid rgba($primary, 0.14);
  border-radius: $radius-md;
  background: $surface-soft;
  color: $ink;
  cursor: pointer;
  text-align: left;
  @include interactive((border-color, background, box-shadow));

  &:hover {
    border-color: $primary;
    background: #fff;
    box-shadow: 0 6px 16px rgba($primary, 0.1);
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.compass-toggle-preview {
  display: grid;
  width: 36px;
  height: 36px;
  place-items: center;
  border-radius: 50%;
  background: $primary-light;

  .compass-needle {
    margin: auto;
  }
}

.compass-toggle-copy {
  display: grid;
  gap: 2px;
  min-width: 0;

  strong {
    font-size: 13px;
    font-weight: 600;
    color: $ink;
    @include truncate;
  }

  small {
    color: $muted;
    font-size: 11px;
    font-weight: 500;
  }
}

.compass-toggle-caret {
  width: 12px;
  height: 12px;
  color: $muted;
  transition: transform $duration $ease;
}

.compass-toggle[aria-expanded='true'] .compass-toggle-caret {
  transform: rotate(180deg);
}

.compass-panel {
  display: grid;
  gap: 8px;
}

.compass-expand-enter-active,
.compass-expand-leave-active {
  overflow: hidden;
  transition:
    opacity 0.22s ease,
    max-height 0.28s cubic-bezier(0.22, 1, 0.36, 1),
    transform 0.22s ease;
}

.compass-expand-enter-from,
.compass-expand-leave-to {
  max-height: 0;
  opacity: 0;
  transform: translateY(-6px);
}

.compass-expand-enter-to,
.compass-expand-leave-from {
  max-height: 260px;
  opacity: 1;
  transform: translateY(0);
}

.compass-dial {
  position: relative;
  width: min(100%, 200px);
  aspect-ratio: 1;
  margin-inline: auto;
  border: 1px solid rgba($primary, 0.12);
  border-radius: 50%;
  background:
    radial-gradient(circle at 50% 50%, rgba($primary, 0.06) 0 34%, transparent 35%),
    linear-gradient(180deg, #fff, $primary-light);
  touch-action: none;
  user-select: none;
  cursor: crosshair;

  &.dragging {
    cursor: grabbing;
  }
}

.compass-wedge {
  position: absolute;
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  border: 1px solid rgba($primary, 0.14);
  border-radius: 50%;
  background: #fff;
  color: $primary;
  cursor: pointer;
  font-size: 10px;
  font-weight: 600;
  transition:
    background 0.15s ease,
    border-color 0.15s ease,
    color 0.15s ease;

  &:hover {
    border-color: $primary;
    background: $primary-light;
  }

  &.active {
    border-color: $accent;
    background: $accent;
    color: #fff;
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.compass-wedge-1 {
  top: 4%;
  left: 50%;
  transform: translateX(-50%);
}

.compass-wedge-2 {
  top: 16%;
  right: 14%;
}

.compass-wedge-3 {
  top: 50%;
  right: 3%;
  transform: translateY(-50%);
}

.compass-wedge-4 {
  bottom: 16%;
  right: 14%;
}

.compass-wedge-5 {
  bottom: 4%;
  left: 50%;
  transform: translateX(-50%);
}

.compass-wedge-6 {
  bottom: 16%;
  left: 14%;
}

.compass-wedge-7 {
  top: 50%;
  left: 3%;
  transform: translateY(-50%);
}

.compass-wedge-8 {
  top: 16%;
  left: 14%;
}

.compass-center {
  position: absolute;
  top: 50%;
  left: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  padding: 0;
  border: 2px solid rgba($primary, 0.16);
  border-radius: 50%;
  background: #fff;
  cursor: pointer;
  transform: translate(-50%, -50%);

  .compass-needle {
    display: block;
  }

  &:hover {
    border-color: $primary;
  }

  &.active {
    border-color: $accent;
    box-shadow: 0 0 0 3px rgba($accent, 0.2);
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.compass-hint {
  margin: 0;
  color: $muted;
  font-size: 11px;
  font-weight: 500;
  text-align: center;
}

.direction-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.direction-chip {
  border: 1px solid rgba($primary, 0.14);
  border-radius: $radius-pill;
  padding: 7px 11px;
  background: $surface-soft;
  color: $primary;
  cursor: pointer;
  font-size: 11px;
  font-weight: 500;
  transition:
    background 0.15s ease,
    border-color 0.15s ease,
    color 0.15s ease;

  &:hover {
    border-color: $primary;
    background: #fff;
  }

  &.active {
    border-color: $accent;
    background: $accent;
    color: #fff;
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

[data-theme='dark'] {
  .compass-toggle {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(255, 255, 255, 0.1);
    color: #e7ebf3;

    &:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    strong {
      color: #e7ebf3;
    }
  }

  .compass-toggle-preview {
    background: rgba(255, 255, 255, 0.06);
  }

  .compass-dial {
    border-color: rgba(255, 255, 255, 0.1);
    background:
      radial-gradient(circle at 50% 50%, rgba(255, 255, 255, 0.05) 0 34%, transparent 35%),
      linear-gradient(180deg, #1e2330, #161b25);
  }

  .compass-wedge {
    background: #161b25;
    border-color: rgba(255, 255, 255, 0.1);
    color: #e7ebf3;

    &:hover {
      background: rgba(255, 255, 255, 0.06);
    }

    &.active {
      color: #14171e;
    }
  }

  .compass-center {
    background: #161b25;
    border-color: rgba(255, 255, 255, 0.12);
  }

  .direction-chip {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(255, 255, 255, 0.1);
    color: #e7ebf3;

    &:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    &.active {
      color: #14171e;
    }
  }
}
</style>

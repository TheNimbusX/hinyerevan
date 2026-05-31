<script setup>
import { computed } from 'vue'
import { DIRECTION_ANGLES } from '../utils/mapMarkerSvg'

const props = defineProps({
  direction: {
    type: Number,
    default: 1,
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg'].includes(value),
  },
})

const isTopShot = computed(() => props.direction === 0)
const angle = computed(() => DIRECTION_ANGLES[props.direction] ?? 0)
</script>

<template>
  <svg
    class="compass-needle"
    :class="`compass-needle--${size}`"
    viewBox="0 0 48 48"
    aria-hidden="true"
  >
    <g v-if="isTopShot" class="compass-needle__topshot">
      <circle cx="24" cy="24" r="12" fill="#fff" />
      <circle cx="24" cy="24" r="12" fill="none" stroke="#294fb3" stroke-width="2.5" />
      <circle cx="24" cy="24" r="5.5" fill="#ff910f" />
    </g>
    <g v-else class="compass-needle__arrow" :transform="`rotate(${angle} 24 24)`">
      <path
        fill="#ff910f"
        stroke="#ffffff"
        stroke-width="1.75"
        stroke-linejoin="round"
        d="M24 6 L31.2 23.2 C31.7 24.2 31 25.4 29.9 25.4 H26.4 V34.8 C26.4 36.1 25.3 37.2 24 37.2 C22.7 37.2 21.6 36.1 21.6 34.8 V25.4 H18.1 C17 25.4 16.3 24.2 16.8 23.2 Z"
      />
    </g>
  </svg>
</template>

<style lang="scss">
.compass-needle {
  display: block;
  flex-shrink: 0;
}

.compass-needle--sm {
  width: 28px;
  height: 28px;
}

.compass-needle--md {
  width: 36px;
  height: 36px;
}

.compass-needle--lg {
  width: 44px;
  height: 44px;
}
</style>

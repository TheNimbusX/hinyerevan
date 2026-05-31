<script setup>
import { computed } from 'vue'
import CompassNeedle from './CompassNeedle.vue'

const props = defineProps({
  direction: {
    type: Number,
    default: 0,
  },
  size: {
    type: String,
    default: 'normal',
  },
  label: {
    type: String,
    default: '',
  },
})

const needleSize = computed(() => (props.size === 'small' ? 'sm' : 'md'))
</script>

<template>
  <span class="direction-marker" :class="`direction-${size}`" :title="label">
    <CompassNeedle :direction="direction" :size="needleSize" />
  </span>
</template>

<style lang="scss">
.direction-marker {
  display: inline-flex;
  flex-shrink: 0;
  filter: drop-shadow(0 3px 6px rgba($accent-dark, 0.28));
  vertical-align: middle;
}
</style>

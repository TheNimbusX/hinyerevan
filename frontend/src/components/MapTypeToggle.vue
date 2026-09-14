<script setup>
import { useI18n } from '../i18n'

defineProps({
  modelValue: { type: String, default: 'scheme' },
  types: { type: Array, default: () => ['scheme', 'satellite'] },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const LABELS = {
  scheme: 'mapTypeScheme',
  satellite: 'mapTypeSatellite',
  hybrid: 'mapTypeHybrid',
  terrain: 'mapTypeTerrain',
}
</script>

<template>
  <div class="map-type-toggle" role="group" @click.stop @dblclick.stop>
    <button
      v-for="type in types"
      :key="type"
      type="button"
      :class="{ active: modelValue === type }"
      :aria-pressed="modelValue === type"
      @click="emit('update:modelValue', type)"
    >
      {{ t(LABELS[type] || 'mapTypeScheme') }}
    </button>
  </div>
</template>

<style lang="scss">
.map-type-toggle {
  position: absolute;
  top: 10px;
  right: 10px;
  z-index: 500;
  display: inline-flex;
  gap: 2px;
  padding: 3px;
  border-radius: $radius-pill;
  background: rgba(255, 255, 255, 0.96);
  box-shadow: 0 6px 18px rgba(23, 52, 126, 0.18);

  button {
    padding: 5px 11px;
    border: 0;
    border-radius: $radius-pill;
    background: transparent;
    color: $muted;
    cursor: pointer;
    font: inherit;
    font-size: 0.7857rem;
    font-weight: 600;
    white-space: nowrap;
    @include interactive((background, color));

    &:hover {
      color: $ink;
    }

    &.active {
      color: #fff;
      background: linear-gradient(135deg, $primary, $primary-dark);
    }

    @include focus-ring(rgba($primary, 0.42), 2px);
  }
}

.map-type-toggle--bottom {
  top: auto;
  bottom: 12px;
  right: 12px;
}

[data-theme='dark'] .map-type-toggle {
  background: rgba(22, 27, 37, 0.94);

  button:not(.active) {
    color: #c5cad6;

    &:hover {
      color: #f4f7ff;
    }
  }
}
</style>

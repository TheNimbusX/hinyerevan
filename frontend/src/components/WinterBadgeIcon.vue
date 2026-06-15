<script setup>
defineProps({
  size: {
    type: String,
    default: 'md',
  },
})
</script>

<template>
  <svg
    class="winter-badge-icon"
    :class="`winter-badge-icon--${size}`"
    viewBox="0 0 24 24"
    aria-hidden="true"
  >
    <path
      d="M12 3v18M12 3 9.5 5.5M12 3l2.5 2.5M12 21l-2.5-2.5M12 21l2.5-2.5M3 12h18M3 12l2.5-2.5M3 12l2.5 2.5M21 12l-2.5-2.5M21 12l-2.5 2.5M5.6 5.6l12.8 12.8M5.6 18.4 18.4 5.6M18.4 18.4 5.6 5.6"
      fill="none"
      stroke="currentColor"
      stroke-width="1.6"
      stroke-linecap="round"
    />
  </svg>
</template>

<style lang="scss">
.winter-badge-icon {
  flex-shrink: 0;
  display: block;

  &--sm {
    width: 13px;
    height: 13px;
  }

  &--md {
    width: 16px;
    height: 16px;
  }
}
</style>

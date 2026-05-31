<script setup>
defineProps({
  filled: { type: Boolean, default: false },
})
</script>

<template>
  <svg
    class="like-icon"
    :class="{ filled }"
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    aria-hidden="true"
  >
    <path
      v-if="filled"
      d="M2 10.5c0-.83.67-1.5 1.5-1.5H7v11H3.5c-.83 0-1.5-.67-1.5-1.5v-8Zm7-1.5h7.6c1.45 0 2.55 1.31 2.32 2.74l-1.2 7.4A2.36 2.36 0 0 1 15.4 21H9V9Zm.95-1.7L13.6 3.65a1.2 1.2 0 0 1 2.05.85V8H9.95Z"
    />
    <path
      v-else
      fill="none"
      stroke="currentColor"
      stroke-width="1.6"
      stroke-linejoin="round"
      d="M2.8 10.5c0-.94.76-1.7 1.7-1.7H7v10.6H4.5c-.94 0-1.7-.76-1.7-1.7v-7.2Zm6.2-1.7h7.4c1.5 0 2.65 1.36 2.4 2.84l-1.18 7.16A2.4 2.4 0 0 1 15.26 20.6H9V8.8Zm0 0 3.4-4.7a1.5 1.5 0 0 1 2.55 1.06V8.8H9Z"
    />
  </svg>
</template>

<style lang="scss">
.like-icon {
  width: 1em;
  height: 1em;
  fill: currentColor;
  flex-shrink: 0;
  vertical-align: -0.18em;
  transition: transform $duration $ease;

  &.filled {
    color: #2374e1;
  }
}
</style>

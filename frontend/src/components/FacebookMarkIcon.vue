<script setup>
defineProps({
  size: { type: Number, default: 14 },
})
</script>

<template>
  <span class="fb-mark" :style="{ width: `${size}px`, height: `${size}px` }" aria-hidden="true">
    <svg :width="size" :height="size" viewBox="0 0 24 24" focusable="false">
      <path
        fill="currentColor"
        d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.24 2.68.24v2.95h-1.51c-1.49 0-1.96.93-1.96 1.87V12h3.33l-.53 3.47h-2.8v8.38A12 12 0 0 0 24 12z"
      />
    </svg>
  </span>
</template>

<style lang="scss" scoped>
.fb-mark {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  color: #fff;
  background: #1877f2;
}
</style>

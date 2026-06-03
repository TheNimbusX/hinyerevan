<script setup>
import { useI18n } from '../i18n'

defineProps({
  facebook: { type: Object, default: null },
})

const { t } = useI18n()
</script>

<template>
  <a
    v-if="facebook?.post_url"
    class="facebook-published-badge"
    :href="facebook.post_url"
    target="_blank"
    rel="noopener noreferrer"
  >
    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
      <path
        fill="currentColor"
        d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.24 2.68.24v2.95h-1.51c-1.49 0-1.96.93-1.96 1.87V12h3.33l-.53 3.47h-2.8v8.38A12 12 0 0 0 24 12z"
      />
    </svg>
    <span>{{ t('facebookPublishedOn') }}</span>
  </a>
</template>

<style lang="scss">
.facebook-published-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
  padding: 8px 12px;
  border-radius: $radius-pill;
  font-size: 13px;
  font-weight: 600;
  color: #1877f2;
  background: rgba(24, 119, 242, 0.1);
  text-decoration: none;
  @include interactive((background, color));

  &:hover {
    background: rgba(24, 119, 242, 0.18);
    color: #0d5bd7;
  }
}

[data-theme='dark'] .facebook-published-badge {
  color: #8cb8ff;
  background: rgba(140, 184, 255, 0.12);

  &:hover {
    background: rgba(140, 184, 255, 0.2);
  }
}
</style>

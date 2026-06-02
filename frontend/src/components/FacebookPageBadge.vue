<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'
import { useI18n } from '../i18n'

const router = useRouter()
const { t } = useI18n()
const stats = ref(null)

onMounted(async () => {
  try {
    stats.value = await api('/facebook/page')
  } catch {
    stats.value = null
  }
})

function openPage() {
  router.push('/facebook')
}
</script>

<template>
  <button
    v-if="stats?.configured"
    type="button"
    class="facebook-page-badge"
    :aria-label="t('facebookPage')"
    @click="openPage"
  >
    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
      <path
        fill="currentColor"
        d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.24 2.68.24v2.95h-1.51c-1.49 0-1.96.93-1.96 1.87V12h3.33l-.53 3.47h-2.8v8.38A12 12 0 0 0 24 12z"
      />
    </svg>
    <span class="facebook-page-badge__count">{{ stats.followers_count || stats.fan_count || 0 }}</span>
  </button>
</template>

<style lang="scss">
.facebook-page-badge {
  position: fixed;
  right: 16px;
  bottom: 20px;
  z-index: 850;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border: 0;
  border-radius: $radius-pill;
  color: #fff;
  background: linear-gradient(145deg, #1877f2, #0d5bd7);
  box-shadow: 0 12px 28px rgba(24, 119, 242, 0.35);
  cursor: pointer;
  @include interactive((transform, box-shadow));

  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 32px rgba(24, 119, 242, 0.42);
  }

  @include mq-down($bp-md) {
    right: 12px;
    bottom: 14px;
    padding: 9px 12px;
  }

  &__count {
    font-size: 14px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
  }
}

[data-theme='dark'] .facebook-page-badge {
  box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
}
</style>

<script setup>
import { onMounted, watch } from 'vue'
import { useRecaptcha } from '../composables/useRecaptcha'
import { useI18n } from '../i18n'

const props = defineProps({
  active: { type: Boolean, default: true },
})

const { t } = useI18n()
const { enabled, el, loadFailed, loading, token: captchaToken, render, reset } = useRecaptcha()
const token = defineModel('token', { type: String, default: '' })

watch(captchaToken, (value) => {
  token.value = value
})

watch(
  () => props.active,
  (on) => {
    if (on) void render()
  },
  { immediate: true },
)

onMounted(() => {
  if (props.active) void render()
})

defineExpose({ reset, render })
</script>

<template>
  <div v-if="enabled" class="recaptcha-field">
    <div class="recaptcha-field__box">
      <div v-if="loading" class="recaptcha-field__preloader" aria-hidden="true">
        <span class="recaptcha-field__spinner"></span>
        <span>{{ t('captchaLoading') }}</span>
      </div>
      <div ref="el" class="recaptcha-field__widget"></div>
    </div>
    <p v-if="loadFailed" class="error captcha-retry">
      {{ t('captchaLoadFailed') }}
      <button type="button" class="link-button" @click="render">{{ t('captchaRetry') }}</button>
    </p>
  </div>
</template>

<style lang="scss">
.recaptcha-field {
  display: grid;
  gap: 8px;
}

.recaptcha-field__box {
  position: relative;
  display: flex;
  justify-content: center;
  min-height: 78px;
}

.recaptcha-field__widget {
  display: flex;
  justify-content: center;
  overflow: visible;

  @media (max-width: 360px) {
    transform: scale(0.86);
    transform-origin: center top;
  }
}

.recaptcha-field__preloader {
  position: absolute;
  inset: 0;
  z-index: 1;
  display: grid;
  justify-items: center;
  align-content: center;
  gap: 8px;
  border: 1px dashed rgba($primary, 0.22);
  border-radius: $radius-sm;
  background: $surface-soft;
  color: $muted;
  font-size: 12px;
}

.recaptcha-field__spinner {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 2px solid rgba($primary, 0.18);
  border-top-color: $primary;
  animation: recaptchaSpin 0.7s linear infinite;
}

@keyframes recaptchaSpin {
  to {
    transform: rotate(360deg);
  }
}
</style>

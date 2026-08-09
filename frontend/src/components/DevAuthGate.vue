<script setup>
import { ref } from 'vue'
import { api, setDevAuthToken } from '../api'
import { useI18n } from '../i18n'
import RecaptchaField from './RecaptchaField.vue'

const emit = defineEmits(['authenticated'])

const { t } = useI18n()
const username = ref('')
const password = ref('')
const recaptchaToken = ref('')
const recaptchaField = ref(null)
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  if (import.meta.env.VITE_RECAPTCHA_SITE_KEY && !recaptchaToken.value) {
    error.value = t('captchaRequired')
    return
  }

  loading.value = true
  try {
    const payload = await api('/dev-auth/login', {
      method: 'POST',
      body: {
        username: username.value,
        password: password.value,
        recaptcha_token: recaptchaToken.value,
      },
    })
    setDevAuthToken(payload.token)
    emit('authenticated')
  } catch (event) {
    error.value = event.message || t('devAuthFailed')
    recaptchaField.value?.reset()
    recaptchaToken.value = ''
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="dev-auth-backdrop">
    <section class="dev-auth-modal" role="dialog" aria-modal="true" :aria-label="t('devAuthTitle')">
      <header class="dev-auth-modal__head">
        <p class="dev-auth-modal__eyebrow">HinYerevan</p>
        <h2>{{ t('devAuthTitle') }}</h2>
        <p class="dev-auth-modal__intro">{{ t('devAuthIntro') }}</p>
      </header>

      <form class="dev-auth-modal__form" @submit.prevent="submit">
        <label>
          <span>{{ t('username') }}</span>
          <input v-model="username" autocomplete="username" required />
        </label>
        <label>
          <span>{{ t('password') }}</span>
          <input v-model="password" type="password" autocomplete="current-password" required />
        </label>
        <RecaptchaField ref="recaptchaField" v-model:token="recaptchaToken" :active="true" />
        <p v-if="error" class="error">{{ error }}</p>
        <button class="button" type="submit" :disabled="loading">
          {{ loading ? t('loading') : t('devAuthSubmit') }}
        </button>
      </form>
    </section>
  </div>
</template>

<style lang="scss">
.dev-auth-backdrop {
  position: fixed;
  inset: 0;
  z-index: 2000;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgba(12, 16, 24, 0.72);
}

.dev-auth-modal {
  width: min(420px, 100%);
  padding: 24px 22px;
  border: 1px solid $line;
  border-radius: $radius-lg;
  background: $surface;
  box-shadow: 0 20px 56px rgba(0, 0, 0, 0.28);
}

.dev-auth-modal__head {
  margin-bottom: 18px;

  h2 {
    margin: 0 0 8px;
    font-size: 1.7143rem;
  }
}

.dev-auth-modal__eyebrow {
  margin: 0 0 6px;
  font-size: 0.7857rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: $muted;
}

.dev-auth-modal__intro {
  margin: 0;
  color: $muted;
  line-height: 1.5;
  font-size: 1rem;
}

.dev-auth-modal__form {
  display: grid;
  gap: 14px;

  label {
    display: grid;
    gap: 6px;
    font-size: 0.9286rem;
  }

  input {
    width: 100%;
  }
}

[data-theme='dark'] .dev-auth-modal {
  background: #161b25;
  border-color: #2a313d;
  color: #e7ebf3;
}
</style>

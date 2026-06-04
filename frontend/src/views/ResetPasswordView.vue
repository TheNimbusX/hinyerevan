<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { useI18n } from '../i18n'
import { getUiLanguage } from '../utils/browserTranslate'
import { setPageMeta } from '../utils/seo'

const route = useRoute()
const router = useRouter()
const { t, setLanguage } = useI18n()

const email = ref('')
const token = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const error = ref('')
const success = ref('')
const loading = ref(false)

const ready = computed(() => Boolean(email.value && token.value))

onMounted(() => {
  const qLang = String(route.query.lang || '')
  if (['hy', 'ru', 'en'].includes(qLang)) {
    setLanguage(qLang)
  }

  email.value = String(route.query.email || '')
  token.value = String(route.query.token || '')
  setPageMeta({
    title: t('resetPasswordTitle'),
    path: route.fullPath,
    noindex: true,
  })
})

async function submit() {
  error.value = ''
  success.value = ''
  loading.value = true
  try {
    const payload = await api('/auth/reset-password', {
      method: 'POST',
      body: {
        email: email.value,
        token: token.value,
        password: password.value,
        password_confirmation: passwordConfirmation.value,
        lang: getUiLanguage(),
      },
    })
    success.value = payload?.message || t('resetPasswordSuccess')
    setTimeout(() => {
      window.dispatchEvent(new CustomEvent('hinyerevan:open-auth', { detail: { mode: 'login' } }))
      router.push('/')
    }, 1800)
  } catch (event) {
    error.value = event?.message || t('resetPasswordInvalidLink')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <section class="page-head">
    <p class="eyebrow">{{ t('signIn') }}</p>
    <h1>{{ t('resetPasswordTitle') }}</h1>
    <p class="page-intro">{{ t('resetPasswordIntro') }}</p>
  </section>

  <form v-if="ready && !success" class="panel reset-form" @submit.prevent="submit">
    <label class="reset-field">
      <span>{{ t('email') }}</span>
      <input v-model="email" type="email" readonly />
    </label>
    <label class="reset-field">
      <span>{{ t('newPassword') }}</span>
      <input v-model="password" type="password" minlength="6" required />
      <small>{{ t('passwordHelp') }}</small>
    </label>
    <label class="reset-field">
      <span>{{ t('confirmNewPassword') }}</span>
      <input v-model="passwordConfirmation" type="password" minlength="6" required />
    </label>
    <p v-if="error" class="error">{{ error }}</p>
    <button class="button" type="submit" :disabled="loading">
      {{ loading ? t('loading') : t('resetPasswordAction') }}
    </button>
  </form>

  <div v-else-if="success" class="panel reset-success">
    <p>{{ success }}</p>
  </div>

  <div v-else class="panel reset-invalid">
    <p>{{ t('resetPasswordInvalidLink') }}</p>
    <button class="link-button" type="button" @click="router.push('/')">{{ t('backToHome') }}</button>
  </div>
</template>

<style lang="scss">
.reset-form,
.reset-success,
.reset-invalid {
  max-width: 420px;
  margin: 0 auto;
  display: grid;
  gap: 14px;
}

.page-intro {
  margin: 8px 0 0;
  color: $muted;
  max-width: 560px;
}

.reset-field {
  display: grid;
  gap: 6px;

  span {
    font-size: 13px;
    font-weight: 500;
  }

  small {
    color: $muted;
    font-size: 12px;
  }
}

.reset-success p {
  margin: 0;
  color: $success;
}
</style>

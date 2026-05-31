<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, apiUrl, setToken } from '../api'
import { useI18n } from '../i18n'

const router = useRouter()
const route = useRoute()
const { t } = useI18n()
const mode = ref('login')
const error = ref('')
const socialProviders = ref([])
const form = ref({
  login: '',
  uid: '',
  first_name: '',
  last_name: '',
  email: '',
  password: '',
})

const providerIcons = {
  google:
    '<svg viewBox="0 0 24 24" width="18" height="18"><path fill="#4285F4" d="M22.5 12.2c0-.7-.06-1.4-.18-2.06H12v3.9h5.9a5.04 5.04 0 0 1-2.19 3.31v2.74h3.54c2.07-1.91 3.25-4.72 3.25-7.89z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.54-2.74c-.98.66-2.24 1.05-3.74 1.05-2.87 0-5.3-1.94-6.17-4.55H2.18v2.83A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.83 14.1a6.6 6.6 0 0 1 0-4.2V7.07H2.18a11 11 0 0 0 0 9.86l3.65-2.83z"/><path fill="#EA4335" d="M12 5.35c1.62 0 3.07.56 4.21 1.65l3.14-3.14C17.45 2.02 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07L5.83 9.9C6.7 7.3 9.13 5.35 12 5.35z"/></svg>',
  facebook:
    '<svg viewBox="0 0 24 24" width="18" height="18"><path fill="#1877F2" d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.24 2.68.24v2.95h-1.51c-1.49 0-1.96.93-1.96 1.87V12h3.33l-.53 3.47h-2.8v8.38A12 12 0 0 0 24 12z"/></svg>',
  yandex:
    '<svg viewBox="0 0 24 24" width="18" height="18"><circle cx="12" cy="12" r="12" fill="#FC3F1D"/><path fill="#fff" d="M13.3 6.4h-1.2c-1.4 0-2.4 1-2.4 2.5 0 1.6.7 2.4 2.1 3.3l1.1.8-3.2 4.6H7.5l2.9-4.3c-1.7-1.2-2.6-2.4-2.6-4.3 0-2.4 1.6-3.9 4.2-3.9h2.7v12.5h-1.4z"/></svg>',
  vkontakte:
    '<svg viewBox="0 0 24 24" width="18" height="18"><path fill="#0077FF" d="M.94 4.5C1.7 4.5 2.32 5.13 2.4 6.55c.2 3.49 1.55 6.32 2.66 6.32.27 0 .4-.15.4-.98V8.04c-.05-1.1-.46-1.36-.46-1.86 0-.3.25-.6.66-.6h2.9c.6 0 .8.32.8 1.02v4.96c0 .6.27.82.45.82.27 0 .5-.22 1-.72 1.1-1.23 2.06-3.13 2.06-3.13.18-.4.5-.77 1.1-.77h.92c.86 0 1.05.44.86 1.04-.36 1.66-3.3 4.13-3.3 4.13-.25.4-.35.6 0 1.05.25.34.53.62.78.96.46.6.8 1.1 1.1 1.6.36.66.07 1-.6 1h-2.9c-.55 0-.84-.31-1.45-1.04-.5-.6-1.06-1.3-1.55-1.3-.32 0-.4.2-.4.93v1.4c0 .54-.16.86-1.5.86-2.22 0-4.66-1.35-6.4-3.85C.74 10.1 0 6.6 0 5.9c0-.86.34-1.4 1-1.4z"/></svg>',
  odnoklassniki:
    '<svg viewBox="0 0 24 24" width="18" height="18"><path fill="#EE8208" d="M12 11.6a4.8 4.8 0 1 0 0-9.6 4.8 4.8 0 0 0 0 9.6zm0-7a2.2 2.2 0 1 1 0 4.4 2.2 2.2 0 0 1 0-4.4zM14.2 14.8a8 8 0 0 0 2.5-1.04 1.3 1.3 0 1 0-1.38-2.2 5.4 5.4 0 0 1-6.64 0 1.3 1.3 0 1 0-1.38 2.2 8 8 0 0 0 2.5 1.05L7.1 17.9a1.3 1.3 0 0 0 1.84 1.84L12 16.68l3.06 3.06a1.3 1.3 0 1 0 1.84-1.84z"/></svg>',
}

function providerIcon(id) {
  return providerIcons[id] || ''
}

function loginWith(provider) {
  window.location.href = apiUrl(`/auth/social/${provider}/redirect`)
}

async function submit() {
  error.value = ''
  try {
    const payload =
      mode.value === 'login'
        ? await api('/auth/login', { method: 'POST', body: { login: form.value.login, password: form.value.password } })
        : await api('/auth/register', { method: 'POST', body: form.value })
    setToken(payload.token)
    router.push('/profile')
  } catch (event) {
    error.value = event.message
  }
}

function handleSocialCallback() {
  const token = route.query.social_token
  const socialError = route.query.social_error
  if (!token && !socialError) return

  // Strip the OAuth params from the URL regardless of outcome.
  const cleanQuery = { ...route.query }
  delete cleanQuery.social_token
  delete cleanQuery.social_error
  router.replace({ query: cleanQuery })

  if (token) {
    setToken(token)
    router.push('/profile')
  } else if (socialError) {
    error.value = String(socialError) || t('socialLoginFailed')
  }
}

onMounted(async () => {
  handleSocialCallback()
  try {
    socialProviders.value = await api('/auth/social/providers')
  } catch {
    socialProviders.value = []
  }
})
</script>

<template>
  <section class="auth-card panel">
    <p class="eyebrow">{{ t('account') }}</p>
    <h1>{{ mode === 'login' ? t('signIn') : t('createAccount') }}</h1>
    <div class="segmented">
      <button :class="{ active: mode === 'login' }" @click="mode = 'login'">{{ t('login') }}</button>
      <button :class="{ active: mode === 'register' }" @click="mode = 'register'">{{ t('register') }}</button>
    </div>
    <div v-if="socialProviders.length" class="social-login">
      <p class="social-login-title">{{ t('continueWith') }}</p>
      <div class="social-buttons">
        <button
          v-for="provider in socialProviders"
          :key="provider.id"
          type="button"
          class="social-button"
          :style="{ '--brand': provider.color }"
          @click="loginWith(provider.id)"
        >
          <span class="social-button-icon" v-html="providerIcon(provider.id)"></span>
          <span>{{ provider.label }}</span>
        </button>
      </div>
      <div class="social-divider"><span>{{ t('socialOr') }}</span></div>
    </div>

    <form class="stack-form" @submit.prevent="submit">
      <input v-if="mode === 'login'" v-model="form.login" :placeholder="t('usernameOrEmail')" required />
      <template v-else>
        <input v-model="form.uid" :placeholder="t('username')" required />
        <input v-model="form.first_name" :placeholder="t('firstName')" required />
        <input v-model="form.last_name" :placeholder="t('lastName')" />
        <input v-model="form.email" type="email" :placeholder="t('email')" required />
      </template>
      <input v-model="form.password" type="password" :placeholder="t('password')" required />
      <button class="button" type="submit">{{ t('continue') }}</button>
      <p v-if="error" class="error">{{ error }}</p>
    </form>
  </section>
</template>

<style lang="scss">
.auth-card {
  max-width: 560px;
  margin-inline: auto;

  h1 {
    font-size: clamp(26px, 4vw, 36px);
  }
}

.social-login {
  margin-top: 18px;
}

.social-login-title {
  margin: 0 0 12px;
  font-size: 13px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: $muted;
}

.social-buttons {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 10px;
}

.social-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  padding: 11px 14px;
  border: 1px solid $line;
  border-radius: $radius-sm;
  background: $surface;
  color: $ink;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition:
    border-color 0.18s ease,
    background 0.18s ease,
    transform 0.18s ease;

  &:hover {
    border-color: var(--brand);
    background: color-mix(in srgb, var(--brand) 8%, transparent);
    transform: translateY(-1px);
  }

  &-icon {
    display: inline-flex;
    width: 18px;
    height: 18px;
  }
}

.social-divider {
  position: relative;
  margin: 20px 0 4px;
  text-align: center;

  &::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 0;
    right: 0;
    height: 1px;
    background: $line;
  }

  span {
    position: relative;
    padding: 0 12px;
    background: $surface;
    color: $muted;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
}
</style>

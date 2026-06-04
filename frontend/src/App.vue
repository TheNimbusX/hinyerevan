<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, apiUrl, getToken, safeAvatarUrl, setToken } from './api'
import { getUiLanguage } from './utils/browserTranslate'
import { useI18n } from './i18n'
import siteLogo from './assets/logos/Logo2026.png'
import { socialProviderIcon } from './utils/socialProviderIcons'
import ThemeToggle from './components/ThemeToggle.vue'
import LanguageSwitcher from './components/LanguageSwitcher.vue'
import SiteFooter from './components/SiteFooter.vue'
import FacebookPageBadge from './components/FacebookPageBadge.vue'
import FacebookPageModal from './components/FacebookPageModal.vue'
import HeaderUserMenu from './components/HeaderUserMenu.vue'

const menuOpen = ref(false)
const facebookOpen = ref(false)
const authOpen = ref(false)
const authMode = ref('login')
const authError = ref('')
const authRedirect = ref(null)
const currentUser = ref(null)
provide('currentUser', currentUser)
const authForm = ref({
  login: '',
  uid: '',
  first_name: '',
  last_name: '',
  email: '',
  sex: '',
  birth_day: '',
  birth_month: '',
  birth_year: '',
  photo: null,
  password: '',
  password_confirmation: '',
  recaptcha_token: '',
})
const forgotEmail = ref('')
const forgotMessage = ref('')
const forgotLoading = ref(false)
const socialProviders = ref([])
const socialRedirecting = ref(null)
function providerIcon(id) {
  return socialProviderIcon(id)
}

const router = useRouter()
const { t } = useI18n()
const days = Array.from({ length: 31 }, (_, index) => index + 1)
const months = Array.from({ length: 12 }, (_, index) => index + 1)
const years = Array.from({ length: 127 }, (_, index) => new Date().getFullYear() - index)
const recaptchaSiteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY || ''
const recaptchaReady = computed(() => recaptchaSiteKey !== '')
const recaptchaEl = ref(null)
const recaptchaLoadFailed = ref(false)
let recaptchaWidgetId = null
let recaptchaScriptPromise = null

function recaptchaLang() {
  const lang = getUiLanguage()
  return lang === 'ru' ? 'ru' : lang === 'en' ? 'en' : 'hy'
}

function loadRecaptchaScript() {
  if (recaptchaScriptPromise) return recaptchaScriptPromise

  recaptchaScriptPromise = new Promise((resolve, reject) => {
    if (window.grecaptcha?.render) {
      resolve()
      return
    }
    const script = document.createElement('script')
    // recaptcha.net mirror — google.com is often blocked in RU/AM.
    const hl = recaptchaLang()
    script.src = `https://www.recaptcha.net/recaptcha/api.js?render=explicit&hl=${hl}`
    script.async = true
    script.defer = true
    script.onload = resolve
    script.onerror = reject
    document.head.appendChild(script)
  })
  return recaptchaScriptPromise
}

// The api.js onload fires slightly before grecaptcha.render is wired up, so poll briefly.
function whenGrecaptchaReady(timeout = 6000) {
  return new Promise((resolve) => {
    const start = Date.now()
    const tick = () => {
      if (window.grecaptcha?.render) {
        resolve(true)
      } else if (Date.now() - start > timeout) {
        resolve(false)
      } else {
        setTimeout(tick, 100)
      }
    }
    tick()
  })
}

async function renderRecaptcha() {
  if (!recaptchaReady.value) return

  recaptchaLoadFailed.value = false
  await nextTick()

  if (!recaptchaEl.value) {
    await nextTick()
  }

  try {
    await loadRecaptchaScript()
  } catch {
    recaptchaLoadFailed.value = true
    return
  }

  const ready = await whenGrecaptchaReady()
  await nextTick()
  const grecaptcha = window.grecaptcha
  if (!ready || !grecaptcha?.render || !recaptchaEl.value) {
    recaptchaLoadFailed.value = true
    return
  }

  if (recaptchaWidgetId !== null) {
    grecaptcha.reset(recaptchaWidgetId)
    authForm.value.recaptcha_token = ''
    return
  }

  try {
    recaptchaWidgetId = grecaptcha.render(recaptchaEl.value, {
      sitekey: recaptchaSiteKey,
      callback: (token) => {
        authForm.value.recaptcha_token = token
        recaptchaLoadFailed.value = false
      },
      'expired-callback': () => {
        authForm.value.recaptcha_token = ''
      },
      'error-callback': () => {
        recaptchaLoadFailed.value = true
      },
    })
  } catch {
    recaptchaLoadFailed.value = true
  }
}

function resetRecaptcha() {
  authForm.value.recaptcha_token = ''
  if (recaptchaWidgetId !== null && window.grecaptcha?.reset) {
    window.grecaptcha.reset(recaptchaWidgetId)
  }
}

function avatarUrl(user) {
  return safeAvatarUrl(user?.photo, siteLogo)
}

async function loadCurrentUser() {
  if (!getToken()) {
    currentUser.value = null
    return
  }
  try {
    currentUser.value = await api('/auth/me')
  } catch {
    setToken(null)
    currentUser.value = null
  }
}

function syncAuthState() {
  loadCurrentUser()
}

let socialCallbackConsumed = false

// OAuth providers redirect back to "/" with a one-time token (or an error)
// in the query string. Consume it, then strip the params from the URL.
async function handleSocialCallback() {
  if (socialCallbackConsumed) return false

  const route = router.currentRoute.value
  const rawToken = route.query.social_token
  const rawError = route.query.social_error
  const token = Array.isArray(rawToken) ? rawToken[0] : rawToken
  const socialError = Array.isArray(rawError) ? rawError[0] : rawError
  if (!token && !socialError) return false

  socialCallbackConsumed = true

  const cleanQuery = { ...route.query }
  delete cleanQuery.social_token
  delete cleanQuery.social_error
  await router.replace({ path: route.path, query: cleanQuery })

  if (token) {
    setToken(String(token))
    try {
      currentUser.value = await api('/auth/me')
      await router.push('/profile')
      return true
    } catch (event) {
      setToken(null)
      currentUser.value = null
      openAuth('login')
      authError.value = event?.message || t('socialLoginFailed')
      return true
    }
  }

  if (socialError) {
    openAuth('login')
    authError.value = String(socialError) || t('socialLoginFailed')
    return true
  }

  return false
}

function closeMenu() {
  menuOpen.value = false
}

function shareSite() {
  const payload = {
    title: 'HinYerevan',
    text: t('tagline'),
    url: window.location.origin,
  }

  if (navigator.share) {
    navigator.share(payload)
  } else {
    navigator.clipboard?.writeText(payload.url)
  }
}

function openAuth(mode = 'login') {
  authMode.value = mode
  authError.value = ''
  forgotMessage.value = ''
  authOpen.value = true
  closeMenu()
}

async function submitForgotPassword() {
  if (forgotLoading.value) return

  const email = forgotEmail.value.trim()
  if (!email) {
    authError.value = t('emailRequired')
    return
  }

  authError.value = ''
  forgotMessage.value = ''
  forgotLoading.value = true

  try {
    const payload = await api('/auth/forgot-password', {
      method: 'POST',
      body: { email, lang: getUiLanguage() },
      timeoutMs: 20000,
    })
    forgotMessage.value = payload?.message || t('forgotPasswordSent')
  } catch (event) {
    authError.value =
      event?.name === 'AbortError' || event?.message === 'Request timed out'
        ? t('forgotPasswordError')
        : event?.message || t('forgotPasswordError')
  } finally {
    forgotLoading.value = false
  }
}

function showForgotPassword() {
  authMode.value = 'forgot'
  authError.value = ''
  forgotMessage.value = ''
  if (String(authForm.value.login).includes('@')) {
    forgotEmail.value = authForm.value.login.trim()
  }
}

function requireAuthForUpload() {
  if (currentUser.value || getToken()) {
    router.push('/photos/add')
    closeMenu()
    return
  }

  authRedirect.value = '/photos/add'
  openAuth('login')
}

async function submitAuth() {
  authError.value = ''
  try {
    const payload = authMode.value === 'login'
      ? await api('/auth/login', {
          method: 'POST',
          body: { login: authForm.value.login, password: authForm.value.password },
        })
      : await register()

    setToken(payload.token)
    currentUser.value = payload.user
    authOpen.value = false
    const next = authRedirect.value || '/profile'
    authRedirect.value = null
    router.push(next)
  } catch (event) {
    authError.value = event.message
    // reCAPTCHA tokens are single-use — refresh after a failed attempt.
    if (authMode.value === 'register') resetRecaptcha()
  }
}

async function register() {
  if (recaptchaReady.value && !authForm.value.recaptcha_token) {
    throw new Error(t('captchaRequired'))
  }

  const body = new FormData()
  Object.entries(authForm.value).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      body.append(key, value)
    }
  })
  body.append('lang', getUiLanguage())

  return api('/auth/register', { method: 'POST', body })
}

function selectAvatar(event) {
  authForm.value.photo = event.target.files[0] || null
}

function socialLogin(providerId) {
  socialRedirecting.value = providerId
  window.location.href = apiUrl(`/auth/social/${providerId}/redirect`)
}

async function loadSocialProviders() {
  try {
    socialProviders.value = await api('/auth/social/providers')
  } catch {
    socialProviders.value = []
  }
}

function handleOpenAuth(event) {
  const mode = event?.detail?.mode || 'login'
  authRedirect.value = event?.detail?.redirect || null
  openAuth(mode)
}

watch(menuOpen, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
})

// Render (or reset) the reCAPTCHA widget whenever the register tab is shown.
watch(
  [authOpen, authMode],
  ([open, mode]) => {
    if (open && mode === 'register') {
      void renderRecaptcha()
    }
  },
  { flush: 'post' },
)

function openFacebookModal() {
  facebookOpen.value = true
}

function closeFacebookModal() {
  facebookOpen.value = false
  const route = router.currentRoute.value
  if (route.query.facebook) {
    const cleanQuery = { ...route.query }
    delete cleanQuery.facebook
    router.replace({ path: route.path, query: cleanQuery })
  }
}

onMounted(async () => {
  // Must run before loadCurrentUser(): a stale token in localStorage can 401 and
  // call setToken(null), wiping the fresh social_token we just received.
  await handleSocialCallback()
  if (!currentUser.value) {
    await loadCurrentUser()
  }
  loadSocialProviders()
  if (router.currentRoute.value.query.facebook === '1') {
    openFacebookModal()
  }
  window.addEventListener('hinyerevan:auth-changed', syncAuthState)
  window.addEventListener('hinyerevan:open-auth', handleOpenAuth)
})

onBeforeUnmount(() => {
  document.body.style.overflow = ''
  window.removeEventListener('hinyerevan:auth-changed', syncAuthState)
  window.removeEventListener('hinyerevan:open-auth', handleOpenAuth)
})
</script>

<template>
  <div class="app-shell">
    <header class="site-header notranslate">
      <div class="site-header-inner">
        <RouterLink class="brand" to="/" @click="closeMenu">
          <img class="brand-logo" :src="siteLogo" alt="HinYerevan" />
          <span class="brand-text">
            <strong>HinYerevan<em>.com</em></strong>
            <small>{{ t('tagline') }}</small>
          </span>
        </RouterLink>

        <div class="header-menu" :class="{ open: menuOpen }">
          <nav class="main-nav" aria-label="Primary navigation">
            <RouterLink to="/" @click="closeMenu">{{ t('map') }}</RouterLink>
            <RouterLink to="/photos" @click="closeMenu">{{ t('photos') }}</RouterLink>
            <RouterLink to="/photos/random" @click="closeMenu">{{ t('randomPhoto') }}</RouterLink>
            <RouterLink to="/news" @click="closeMenu">{{ t('news') }}</RouterLink>
            <RouterLink to="/pages/aboutus" @click="closeMenu">{{ t('about') }}</RouterLink>
            <RouterLink to="/pages/faq" @click="closeMenu">{{ t('faq') }}</RouterLink>
          </nav>

          <div class="header-tools">
            <div class="header-tools-row">
              <LanguageSwitcher />
              <ThemeToggle />
            </div>
            <button class="nav-button header-action" type="button" @click="requireAuthForUpload">{{ t('addPhoto') }}</button>
            <HeaderUserMenu v-if="currentUser" :user="currentUser" @close="closeMenu" />
            <button v-else class="button button-small" type="button" @click="openAuth('login')">{{ t('signIn') }}</button>
          </div>
        </div>

        <button
          class="menu-toggle"
          type="button"
          :class="{ open: menuOpen }"
          :aria-expanded="menuOpen"
          aria-label="Menu"
          @click="menuOpen = !menuOpen"
        >
          <span class="menu-toggle-box" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
          </span>
        </button>
      </div>
    </header>

    <Transition name="menu-backdrop">
      <button
        v-if="menuOpen"
        type="button"
        class="mobile-menu-backdrop"
        aria-label="Close menu"
        tabindex="-1"
        @click="closeMenu"
      />
    </Transition>

    <main class="site-content">
      <RouterView />
    </main>

    <SiteFooter />

    <FacebookPageBadge @open="openFacebookModal" />
    <FacebookPageModal :open="facebookOpen" @close="closeFacebookModal" />

    <Teleport to="body">
      <div v-if="authOpen" class="auth-modal-backdrop" @click.self="authOpen = false">
        <section class="auth-modal" role="dialog" aria-modal="true">
          <button class="auth-close" type="button" @click="authOpen = false" :aria-label="t('cancel')" />

          <header class="auth-modal__head">
            <h2>{{ authMode === 'forgot' ? t('forgotPasswordTitle') : authMode === 'login' ? t('signIn') : t('createAccount') }}</h2>
            <p>{{ authMode === 'forgot' ? t('forgotPasswordIntro') : authMode === 'login' ? t('loginIntro') : t('registerIntro') }}</p>
          </header>

          <div v-if="authMode !== 'forgot'" class="auth-tabs">
            <button type="button" :class="{ on: authMode === 'login' }" @click="authMode = 'login'">{{ t('login') }}</button>
            <button type="button" :class="{ on: authMode === 'register' }" @click="authMode = 'register'">{{ t('register') }}</button>
          </div>

          <div v-if="authMode !== 'forgot' && socialProviders.length" class="auth-social">
            <button
              v-for="provider in socialProviders"
              :key="provider.id"
              type="button"
              class="auth-social__btn"
              :class="[`auth-social__btn--${provider.id}`, { 'is-loading': socialRedirecting === provider.id }]"
              :disabled="Boolean(socialRedirecting)"
              :aria-label="provider.label"
              @click="socialLogin(provider.id)"
            >
              <span class="auth-social__icon" v-html="providerIcon(provider.id)"></span>
            </button>
          </div>
          <p v-else-if="authMode !== 'forgot'" class="auth-social-empty">{{ t('socialLoginNoneConfigured') }}</p>

          <p v-if="authMode !== 'forgot' && socialProviders.length" class="auth-divider"><span>{{ t('orContinueWithEmail') }}</span></p>

          <form v-if="authMode === 'forgot'" class="auth-form" @submit.prevent="submitForgotPassword">
            <input
              v-model="forgotEmail"
              type="email"
              :placeholder="t('email')"
              :disabled="forgotLoading"
              required
            />
            <p v-if="forgotMessage" class="success">{{ forgotMessage }}</p>
            <p v-if="authError" class="error">{{ authError }}</p>
            <button class="button" type="submit" :disabled="forgotLoading">
              {{ forgotLoading ? t('forgotPasswordSending') : t('sendResetLink') }}
            </button>
            <button class="link-button auth-forgot-back" type="button" :disabled="forgotLoading" @click="authMode = 'login'">
              {{ t('backToLogin') }}
            </button>
          </form>

          <form v-else class="auth-form" @submit.prevent="submitAuth">
            <input v-if="authMode === 'login'" v-model="authForm.login" :placeholder="t('usernameOrEmail')" required />
            <template v-else>
              <label class="register-field">
                <span>{{ t('firstName') }}</span>
                <input v-model="authForm.first_name" :placeholder="t('firstName')" minlength="3" required />
                <small>{{ t('firstNameHelp') }}</small>
              </label>
              <label class="register-field">
                <span>{{ t('lastName') }}</span>
                <input v-model="authForm.last_name" :placeholder="t('lastName')" minlength="3" required />
                <small>{{ t('lastNameHelp') }}</small>
              </label>
              <label class="register-field">
                <span>{{ t('email') }}</span>
                <input v-model="authForm.email" type="email" :placeholder="t('email')" required />
                <small>{{ t('emailHelp') }}</small>
              </label>
              <label class="register-field">
                <span>{{ t('sex') }}</span>
                <select v-model="authForm.sex" required>
                  <option value="" disabled>{{ t('chooseSex') }}</option>
                  <option value="1">{{ t('male') }}</option>
                  <option value="0">{{ t('female') }}</option>
                </select>
                <small>{{ t('sexHelp') }}</small>
              </label>
              <label class="register-field">
                <span>{{ t('birthDate') }}</span>
                <div class="birth-grid">
                  <select v-model="authForm.birth_day">
                    <option value="">{{ t('day') }}</option>
                    <option v-for="day in days" :key="day" :value="day">{{ day }}</option>
                  </select>
                  <select v-model="authForm.birth_month">
                    <option value="">{{ t('month') }}</option>
                    <option v-for="month in months" :key="month" :value="month">{{ month }}</option>
                  </select>
                  <select v-model="authForm.birth_year">
                    <option value="">{{ t('year') }}</option>
                    <option v-for="year in years" :key="year" :value="year">{{ year }}</option>
                  </select>
                </div>
                <small>{{ t('birthDateHelp') }}</small>
              </label>
              <label class="file-picker register-avatar">
                <input type="file" accept="image/*" @change="selectAvatar" />
                <span>{{ authForm.photo?.name || t('mainPhoto') }}</span>
                <small>{{ t('mainPhotoHelp') }}</small>
              </label>
              <label class="register-field">
                <span>{{ t('username') }}</span>
                <input v-model="authForm.uid" :placeholder="t('username')" minlength="3" pattern="[A-Za-z0-9]+" required />
                <small>{{ t('usernameHelp') }}</small>
              </label>
            </template>
            <input v-model="authForm.password" type="password" :placeholder="t('password')" required />
            <button
              v-if="authMode === 'login'"
              type="button"
              class="link-button auth-forgot-link"
              @click="showForgotPassword"
            >
              {{ t('forgotPassword') }}
            </button>
            <template v-if="authMode === 'register'">
              <input
                v-model="authForm.password_confirmation"
                type="password"
                :placeholder="t('passwordConfirmation')"
                required
              />
              <small class="form-help">{{ t('passwordHelp') }}</small>
              <div v-if="recaptchaReady" ref="recaptchaEl" class="g-recaptcha-host"></div>
              <p v-if="recaptchaReady && recaptchaLoadFailed" class="error captcha-retry">
                {{ t('captchaLoadFailed') }}
                <button type="button" class="link-button" @click="renderRecaptcha">{{ t('captchaRetry') }}</button>
              </p>
            </template>
            <button class="button" type="submit">{{ t('continue') }}</button>
            <p v-if="authError" class="error">{{ authError }}</p>
          </form>
        </section>
      </div>
    </Teleport>
  </div>
</template>

<style lang="scss">
// ---------- Top navigation (floating pill) -----------------------
.site-header {
  position: fixed;
  top: 14px;
  left: 0;
  right: 0;
  z-index: 900;
  width: calc(100% - 32px);
  max-width: 1248px;
  margin-inline: auto;
  border: 1px solid rgba($ink, 0.06);
  border-radius: $radius-pill;
  background: rgba($bg, 0.7);
  backdrop-filter: blur(24px) saturate(180%);
  -webkit-backdrop-filter: blur(24px) saturate(180%);
  box-shadow: $shadow-md;

  @include mq-down($bp-md) {
    top: 10px;
    width: calc(100% - 20px);
    border-radius: $radius-lg;
  }
}

.site-header-inner {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 20px;
  height: 56px;
  padding: 0 14px 0 18px;

  @include mq-down($bp-md) {
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    height: 52px;
    padding: 0 8px 0 12px;
  }
}

.header-menu {
  display: grid;
  grid-template-columns: 1fr auto;
  align-items: center;
  gap: 12px;
  min-width: 0;
  width: 100%;

  .main-nav {
    justify-self: center;
    grid-column: 1;
  }

  .header-tools {
    grid-column: 2;
  }

  @include mq-down($bp-md) {
    display: none;
    position: fixed;
    top: calc(10px + 52px + 10px);
    left: 50%;
    z-index: 890;
    flex-direction: column;
    align-items: stretch;
    gap: 12px;
    width: min(400px, calc(100vw - 24px));
    max-height: min(72vh, calc(100vh - 90px));
    padding: 12px;
    border: 1px solid rgba($ink, 0.08);
    border-radius: $radius-lg;
    background: rgba($surface, 0.97);
    backdrop-filter: blur(20px) saturate(160%);
    -webkit-backdrop-filter: blur(20px) saturate(160%);
    box-shadow: 0 24px 60px rgba(20, 24, 34, 0.22);
    overflow-x: hidden;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    transform: translateX(-50%) translateY(-10px) scale(0.98);
    opacity: 0;
    pointer-events: none;
    transition:
      opacity 0.22s ease,
      transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);

    &.open {
      display: flex;
      transform: translateX(-50%) translateY(0) scale(1);
      opacity: 1;
      pointer-events: auto;
    }

    .main-nav,
    .header-tools {
      grid-column: auto;
      justify-self: stretch;
    }
  }
}

.brand {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  color: $ink;
  text-decoration: none;
  @include interactive((transform, opacity));

  &:hover {
    transform: translateY(-1px);
  }

  &:focus-visible {
    outline: 2px solid rgba($primary, 0.4);
    outline-offset: 4px;
    border-radius: 12px;
  }
}

.brand-logo {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  object-fit: cover;
  background: #fff;
  box-shadow: 0 6px 14px rgba(20, 24, 34, 0.12);
}

.brand-text {
  display: grid;
  gap: 2px;
  align-content: center;
  line-height: 1;

  strong {
    font-family: $font-serif;
    font-size: 18px;
    font-weight: 500;
    line-height: 1;
    letter-spacing: -0.01em;
    color: $ink;
    white-space: nowrap;

    em {
      font-style: normal;
      font-weight: 400;
      color: inherit;
    }
  }

  small {
    color: $muted;
    font-size: 11px;
    font-weight: 400;
    letter-spacing: 0.005em;
    line-height: 1;
    white-space: nowrap;
  }

  @include mq-down($bp-sm) {
    small {
      display: none;
    }
  }
}

.main-nav {
  display: flex;
  align-items: center;
  gap: 4px;

  @include mq-down($bp-md) {
    display: grid;
    gap: 4px;
    width: 100%;
  }

  a {
    position: relative;
    padding: 8px 14px;
    color: $muted;
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    border-radius: $radius-pill;
    @include interactive((color, background));

    &:hover {
      color: $ink;
      background: rgba($ink, 0.04);
    }

    &.router-link-active {
      color: $ink;

      &::after {
        content: '';
        position: absolute;
        left: 50%;
        bottom: 2px;
        width: 18px;
        height: 2px;
        border-radius: 999px;
        background: $accent;
        transform: translateX(-50%);
      }
    }

    @include focus-ring(rgba($primary, 0.4), 2px);
  }
}

.header-tools {
  display: flex;
  align-items: center;
  gap: 10px;

  @include mq-down($bp-md) {
    display: grid;
    gap: 8px;
    width: 100%;
  }
}

.header-tools-row {
  display: contents;

  @include mq-down($bp-md) {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 8px;
    align-items: center;
    width: 100%;

    .language-switcher {
      grid-column: 1;
      min-width: 0;
    }

    .theme-toggle {
      grid-column: 2;
      justify-self: end;
    }
  }
}

.header-action {
  padding: 8px 14px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface;
  color: $ink;
  cursor: pointer;
  font-size: 12px;
  font-weight: 500;
  @include interactive((background, color, border-color, transform));

  &:hover {
    color: #fff;
    border-color: $ink;
    background: $ink;
    transform: translateY(-1px);
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.language-switcher {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  padding: 3px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface;

  button {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 8px;
    border: 0;
    border-radius: $radius-pill;
    background: transparent;
    color: $muted;
    cursor: pointer;
    font-size: 11px;
    font-weight: 500;
    @include interactive((color, background));

    &:hover {
      color: $ink;
      background: rgba($ink, 0.05);
    }

    &.active {
      color: $ink;
      background: rgba($ink, 0.08);
    }

    @include focus-ring(rgba($primary, 0.4), 2px);
  }
}

.flag {
  width: 16px;
  height: 11px;
  border-radius: 2px;
  object-fit: cover;
}

.header-user {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 4px 12px 4px 4px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface;
  color: $ink;
  font-size: 13px;
  font-weight: 500;
  text-decoration: none;
  @include interactive((background, transform, border-color));

  &:hover {
    border-color: $ink;
    transform: translateY(-1px);
  }

  img {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    object-fit: cover;
    background: $bg;
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

// ---------- Burger + mobile drawer -------------------------------
.menu-toggle {
  display: none;

  @include mq-down($bp-md) {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    padding: 0;
    border: 1px solid rgba($ink, 0.1);
    border-radius: 10px;
    background: $surface;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    transition:
      background 0.2s ease,
      border-color 0.2s ease,
      transform 0.2s ease;

    &:hover {
      border-color: $ink;
      transform: translateY(-1px);
    }

    &.open {
      border-color: $ink;
      background: $ink;

      .menu-toggle-box span {
        background: #fff;
      }
    }

    @include focus-ring(rgba($primary, 0.42));
  }
}

.menu-toggle-box {
  display: inline-flex;
  flex-direction: column;
  justify-content: center;
  gap: 5px;
  width: 16px;
  height: 16px;

  span {
    display: block;
    width: 16px;
    height: 1.5px;
    border-radius: 999px;
    background: $ink;
    transform-origin: center;
    transition:
      transform 0.28s cubic-bezier(0.22, 1, 0.36, 1),
      opacity 0.18s ease,
      width 0.2s ease;
  }
}

.menu-toggle.open .menu-toggle-box span:nth-child(1) {
  transform: translateY(6.5px) rotate(45deg);
}

.menu-toggle.open .menu-toggle-box span:nth-child(2) {
  opacity: 0;
  transform: scaleX(0);
}

.menu-toggle.open .menu-toggle-box span:nth-child(3) {
  transform: translateY(-6.5px) rotate(-45deg);
}

.mobile-menu-backdrop {
  position: fixed;
  inset: 0;
  z-index: 880;
  border: 0;
  padding: 0;
  background: rgba(12, 16, 24, 0.42);
  backdrop-filter: blur(2px);
  cursor: pointer;
}

.menu-backdrop-enter-active,
.menu-backdrop-leave-active {
  transition: opacity 0.22s ease;
}

.menu-backdrop-enter-from,
.menu-backdrop-leave-to {
  opacity: 0;
}

@include mq-down($bp-md) {
  .main-nav a {
    padding: 11px 14px;
    text-align: left;
    border-radius: $radius-md;
    background: $surface-soft;
    border: 1px solid $line;

    &.router-link-active {
      color: #fff;
      background: $ink;
      border-color: $ink;

      &::after {
        display: none;
      }
    }
  }

  .header-tools .header-action,
  .header-tools .header-user-menu,
  .header-tools .button {
    width: 100%;
    min-height: 44px;
    justify-content: center;
  }
}

// ---------- Auth modal -------------------------------------------
.auth-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  // Scroll the backdrop (not the modal) so a tall form stays fully reachable
  // on mobile; `margin: auto` on the modal still centers it when it fits.
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  padding: 20px;
  background: rgba(12, 16, 24, 0.55);
  backdrop-filter: blur(6px);
}

.auth-modal {
  position: relative;
  width: min(440px, 100%);
  margin: auto;
  overflow-x: hidden;
  padding: 24px 22px 22px;
  border: 1px solid $line;
  border-radius: $radius-lg;
  background: $surface;
  box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
}

@include mq-down($bp-md) {
  .auth-modal-backdrop {
    padding: 12px;
  }

  .auth-modal {
    padding: 20px 16px 16px;
  }
}

.auth-modal__head {
  margin: 0 0 16px;
  padding-right: 32px;

  h2 {
    margin: 0 0 4px;
    font-size: 20px;
    font-weight: 600;
  }

  p {
    margin: 0;
    color: $muted;
    font-size: 13px;
    line-height: 1.45;
  }
}

.auth-close {
  position: absolute;
  top: 12px;
  right: 12px;
  width: 32px;
  height: 32px;
  border: 1px solid $line;
  border-radius: 50%;
  color: $muted;
  background: $surface-soft;
  cursor: pointer;
  padding: 0;

  // Cross drawn from two centered bars — always pixel-perfect, regardless of font metrics.
  &::before,
  &::after {
    content: "";
    position: absolute;
    top: 50%;
    left: 50%;
    width: 14px;
    height: 1.7px;
    border-radius: 2px;
    background: currentColor;
  }

  &::before {
    transform: translate(-50%, -50%) rotate(45deg);
  }

  &::after {
    transform: translate(-50%, -50%) rotate(-45deg);
  }

  &:hover {
    color: $ink;
    border-color: $ink;
  }
}

.auth-tabs {
  display: flex;
  gap: 0;
  margin-bottom: 16px;
  border-bottom: 1px solid $line;

  button {
    flex: 1;
    padding: 10px 8px;
    border: 0;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    background: none;
    color: $muted;
    font: inherit;
    font-size: 14px;
    cursor: pointer;

    &.on {
      color: $ink;
      font-weight: 600;
      border-bottom-color: $primary;
    }
  }
}

.auth-social {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 10px;
  margin-bottom: 14px;
}

.auth-social-empty {
  margin: 0 0 14px;
  color: $muted;
  font-size: 12px;
  line-height: 1.45;
  text-align: center;
}

.auth-social__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  padding: 0;
  border: 1px solid $line;
  border-radius: 8px;
  background: $surface;
  color: $ink;
  cursor: pointer;
  transition:
    transform 0.15s ease,
    box-shadow 0.15s ease,
    border-color 0.15s ease,
    opacity 0.15s ease;

  .auth-social__icon {
    display: inline-flex;
    width: 22px;
    height: 22px;
  }

  .auth-social__icon svg {
    display: block;
    width: 100%;
    height: 100%;
  }

  &:hover:not(:disabled) {
    transform: translateY(-1px);
    border-color: $muted-soft;
    box-shadow: 0 4px 12px rgba(20, 24, 34, 0.1);
  }

  &:disabled {
    opacity: 0.55;
    cursor: wait;
  }

  &.is-loading {
    pointer-events: none;

    .auth-social__icon {
      opacity: 0.45;
    }
  }
}

.auth-divider {
  margin: 0 0 14px;
  color: $muted;
  font-size: 12px;
  text-align: center;

  span {
    display: inline-block;
    padding: 0 10px;
    background: $surface;
  }

  &::before {
    content: '';
    display: block;
    height: 1px;
    margin-bottom: -8px;
    background: $line;
  }
}

.auth-form {
  display: grid;
  gap: 10px;

  input,
  select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid $line;
    border-radius: $radius-sm;
    background: $surface;
    font: inherit;
    font-size: 14px;
  }

  .button {
    margin-top: 4px;
    width: 100%;
  }

  .error {
    margin: 0;
    color: #b42318;
    font-size: 13px;
  }

  .success {
    margin: 0;
    color: $success;
    font-size: 13px;
  }
}

.auth-forgot-link,
.auth-forgot-back {
  justify-self: start;
  margin: -2px 0 0;
  padding: 0;
  font-size: 13px;
}

.register-field {
  display: grid;
  gap: 5px;

  span {
    color: $muted;
    font-size: 12px;
    font-weight: 500;
  }

  small {
    color: $muted;
    font-size: 11px;
    font-weight: 400;
    line-height: 1.3;
  }
}

.form-help {
  color: $muted;
  font-size: 12px;
  font-weight: 500;
}

.register-avatar {
  small {
    color: $muted;
    font-size: 11px;
    font-weight: 400;
  }
}

.birth-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}

.captcha-retry {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.g-recaptcha-host {
  display: flex;
  justify-content: center;
  min-height: 78px;
  overflow: visible;

  // The reCAPTCHA iframe is a fixed 304px wide; scale it down on narrow phones
  // so it never spills out of the modal.
  @media (max-width: 360px) {
    transform: scale(0.86);
    transform-origin: center top;
  }
}

.file-picker {
  display: grid;
  min-height: 60px;
  place-items: center;
  padding: 10px;
  border: 2px dashed rgba($primary, 0.25);
  border-radius: $radius-md;
  color: $primary;
  background: $primary-light;
  cursor: pointer;
  text-align: center;
  font-size: 12px;
  font-weight: 500;
  @include interactive((border-color, background, color));

  &:hover {
    border-color: $primary;
    background: #fff;
  }

  input {
    display: none;
  }

  small {
    color: $muted;
    font-weight: 400;
  }
}
</style>

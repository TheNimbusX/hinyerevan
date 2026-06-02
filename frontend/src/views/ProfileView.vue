<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, apiUrl, imageUrl, safeAvatarUrl, setToken } from '../api'
import { useAuthGate } from '../composables/useAuthGate'
import { useI18n } from '../i18n'
import { formatCommentBody } from '../utils/commentBody'
import { isAdminUser, parseBirthdate } from '../utils/user'
import siteLogo from '../assets/logos/Logo2026.png'
import LikeIcon from '../components/LikeIcon.vue'

const router = useRouter()
const route = useRoute()
const { t, currentLanguage } = useI18n()
const { requireAuth } = useAuthGate()

const days = Array.from({ length: 31 }, (_, index) => index + 1)
const months = Array.from({ length: 12 }, (_, index) => index + 1)
const years = Array.from({ length: 127 }, (_, index) => new Date().getFullYear() - index)

const user = ref(null)
const stats = ref({ photos_total: 0, photos_published: 0, photos_pending: 0, comments_total: 0, views_total: 0 })
const myPhotos = ref([])
const myComments = ref([])
const myFavorites = ref([])
const loading = ref(true)

const activeTab = ref('overview')
const tabs = computed(() => [
  { id: 'overview', label: t('overview') },
  { id: 'photos', label: t('myPhotos') },
  { id: 'favorites', label: t('myCollection') },
  { id: 'comments', label: t('myComments') },
  { id: 'settings', label: t('settings') },
  { id: 'security', label: t('security') },
])

const profileForm = ref({
  first_name: '',
  last_name: '',
  email: '',
  identity: '',
  sex: '',
  birth_day: '',
  birth_month: '',
  birth_year: '',
})
const profileMessage = ref('')
const profileError = ref('')

const passwordForm = ref({ current_password: '', password: '', password_confirmation: '' })
const passwordMessage = ref('')
const passwordError = ref('')

const avatarInput = ref(null)
const avatarBusy = ref(false)
const avatarMessage = ref('')
const avatarError = ref('')

const socialProviders = ref([])
const socialLinkBusy = ref(null)
const socialLinkError = ref('')
const socialLinkMessage = ref('')

const canLinkSocial = computed(() => (user.value?.network || '').toLowerCase() === 'hinyerevan')
const linkedNetworkLabel = computed(() => {
  const id = (user.value?.network || '').toLowerCase()
  if (!id || id === 'hinyerevan') return ''
  const found = socialProviders.value.find((p) => p.id === id)
  return found?.label || id
})

const memberSince = computed(() => formatDate(stats.value?.member_since))

const isAdmin = computed(() => isAdminUser(user.value))
const roleLabel = computed(() => (isAdmin.value ? t('roleAdmin') : t('roleUser')))

const avatarFailed = ref(false)
const initials = computed(() => {
  const source = (user.value?.name || user.value?.uid || '').trim()
  if (!source) return 'U'
  const letters = source
    .split(/\s+/)
    .map((part) => part[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
  return letters.toUpperCase() || source[0].toUpperCase()
})

function avatarFor(u) {
  return safeAvatarUrl(u?.photo, siteLogo)
}

function formatDate(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleDateString()
}

function syncForm() {
  if (!user.value) return
  const birth = parseBirthdate(user.value.bdate)
  profileForm.value = {
    first_name: user.value.first_name || '',
    last_name: user.value.last_name || '',
    email: user.value.email || '',
    identity: user.value.identity || '',
    sex: user.value.sex === 0 || user.value.sex === 1 ? String(user.value.sex) : '',
    ...birth,
  }
}

function goAddPhoto() {
  requireAuth('/photos/add')
}

async function loadAll() {
  loading.value = true
  try {
    const [me, statData, photos, comments, favorites, providers] = await Promise.all([
      api('/auth/me'),
      api('/auth/stats').catch(() => stats.value),
      api('/auth/photos?per_page=12').catch(() => ({ data: [] })),
      api('/auth/comments?per_page=12').catch(() => ({ data: [] })),
      api('/auth/favorites?per_page=18').catch(() => ({ data: [] })),
      api('/auth/social/providers').catch(() => []),
    ])
    socialProviders.value = providers
    user.value = me
    stats.value = { ...stats.value, ...statData }
    myPhotos.value = photos.data || []
    myComments.value = comments.data || []
    myFavorites.value = favorites.data || []
    syncForm()
  } catch {
    setToken(null)
    router.push('/')
  } finally {
    loading.value = false
  }
}

async function removeFavorite(photoId) {
  try {
    await api(`/photos/${photoId}/favorite`, { method: 'DELETE' })
    myFavorites.value = myFavorites.value.filter((item) => item.photo?.id !== photoId)
  } catch {
    /* ignore */
  }
}

async function saveProfile() {
  profileMessage.value = ''
  profileError.value = ''
  try {
    user.value = await api('/auth/profile', {
      method: 'PUT',
      body: {
        ...profileForm.value,
        sex: Number(profileForm.value.sex),
        birth_day: Number(profileForm.value.birth_day),
        birth_month: Number(profileForm.value.birth_month),
        birth_year: Number(profileForm.value.birth_year),
      },
    })
    profileMessage.value = t('profileSaved')
    syncForm()
  } catch (event) {
    profileError.value = event.message
  }
}

async function changePassword() {
  passwordMessage.value = ''
  passwordError.value = ''
  try {
    await api('/auth/password', { method: 'PUT', body: passwordForm.value })
    passwordMessage.value = t('passwordSaved')
    passwordForm.value = { current_password: '', password: '', password_confirmation: '' }
  } catch (event) {
    passwordError.value = event.message
  }
}

async function uploadAvatar(event) {
  const file = event.target.files?.[0]
  if (!file) return

  avatarBusy.value = true
  avatarMessage.value = ''
  avatarError.value = ''
  try {
    const body = new FormData()
    body.append('photo', file)
    user.value = await api('/auth/avatar', { method: 'POST', body })
    avatarFailed.value = false
    avatarMessage.value = t('avatarSaved')
  } catch (e) {
    avatarError.value = e.message
  } finally {
    avatarBusy.value = false
    if (event.target) event.target.value = ''
  }
}

function pickAvatar() {
  avatarInput.value?.click()
}

async function startSocialLink(providerId) {
  socialLinkError.value = ''
  socialLinkMessage.value = ''
  socialLinkBusy.value = providerId
  try {
    const { redirect_url: redirectUrl } = await api(`/auth/social/link/${providerId}/start`, {
      method: 'POST',
    })
    window.location.href = redirectUrl || apiUrl(`/auth/social/${providerId}/redirect`)
  } catch (event) {
    socialLinkError.value = event.message
    socialLinkBusy.value = null
  }
}

async function logout() {
  try {
    await api('/auth/logout', { method: 'POST' })
  } catch {
    /* ignore */
  }
  setToken(null)
  router.push('/')
}

onMounted(() => {
  const tab = route.query.tab
  if (typeof tab === 'string' && tabs.value.some((item) => item.id === tab)) {
    activeTab.value = tab
  }
  if (route.query.social_linked) {
    socialLinkMessage.value = t('socialLinked')
    router.replace({ path: '/profile', query: { tab: 'settings' } })
  }
  loadAll()
})
watch(currentLanguage, loadAll)
</script>

<template>
  <div v-if="loading" class="panel profile-loading">{{ t('loading') }}</div>

  <section v-else-if="user" class="profile-dashboard">
    <header class="profile-hero">
      <div class="profile-hero-body">
        <button class="profile-avatar" type="button" @click="pickAvatar" :disabled="avatarBusy">
          <img v-if="!avatarFailed" :src="avatarFor(user)" :alt="user.name" @error="avatarFailed = true" />
          <span v-else class="profile-avatar-initials" aria-hidden="true">{{ initials }}</span>
          <span class="profile-avatar-overlay">{{ t('changeAvatar') }}</span>
        </button>
        <input ref="avatarInput" type="file" accept="image/*" hidden @change="uploadAvatar" />

        <div class="profile-hero-info">
          <p class="eyebrow">{{ t('dashboard') }}</p>
          <h1>{{ user.name || user.uid }}</h1>
          <p class="profile-handle">@{{ user.uid }}</p>
          <div class="profile-badges">
            <span class="badge" :class="isAdmin ? 'badge-admin' : 'badge-user'">{{ roleLabel }}</span>
            <span v-if="memberSince" class="badge badge-soft">{{ t('memberSince') }} {{ memberSince }}</span>
            <span v-if="user.email" class="badge badge-soft">{{ user.email }}</span>
          </div>
        </div>

        <div class="profile-hero-actions">
          <button class="button" type="button" @click="goAddPhoto">{{ t('addPhoto') }}</button>
          <button class="button button-ghost" type="button" @click="logout">{{ t('logout') }}</button>
        </div>
      </div>
    </header>

    <p v-if="avatarMessage" class="success-line">{{ avatarMessage }}</p>
    <p v-if="avatarError" class="error-line">{{ avatarError }}</p>

    <section class="profile-stats">
      <article class="stat-card">
        <span class="stat-icon stat-icon-photos" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22">
            <rect x="3" y="5" width="18" height="14" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8" />
            <circle cx="8.5" cy="10" r="1.6" fill="currentColor" />
            <path d="M4 17l5-4 4 3 3-2 4 3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </span>
        <span class="stat-body">
          <span class="stat-label">{{ t('totalPhotos') }}</span>
          <strong class="stat-value">{{ stats.photos_total }}</strong>
          <span class="stat-meta">
            {{ stats.photos_published }} {{ t('published') }} · {{ stats.photos_pending }} {{ t('pending') }}
          </span>
        </span>
      </article>
      <article class="stat-card">
        <span class="stat-icon stat-icon-views" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22">
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
            <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8" />
          </svg>
        </span>
        <span class="stat-body">
          <span class="stat-label">{{ t('totalViews') }}</span>
          <strong class="stat-value">{{ stats.views_total.toLocaleString() }}</strong>
          <span class="stat-meta">{{ t('views') }}</span>
        </span>
      </article>
      <article class="stat-card">
        <span class="stat-icon stat-icon-comments" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22">
            <path d="M4 5h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-4 3v-3H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
          </svg>
        </span>
        <span class="stat-body">
          <span class="stat-label">{{ t('totalComments') }}</span>
          <strong class="stat-value">{{ stats.comments_total }}</strong>
          <span class="stat-meta">{{ t('comments') }}</span>
        </span>
      </article>
    </section>

    <nav class="profile-tabs" role="tablist">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        type="button"
        role="tab"
        :class="['profile-tab', { active: activeTab === tab.id }]"
        @click="activeTab = tab.id"
      >
        {{ tab.label }}
      </button>
    </nav>

    <section v-if="activeTab === 'overview'" class="profile-tab-pane overview-pane">
      <article class="panel profile-block">
        <header class="profile-block-head">
          <h2>{{ t('latestPhotos') }}</h2>
          <button class="link-button" type="button" @click="activeTab = 'photos'">{{ t('seeAll') }}</button>
        </header>
        <p v-if="!myPhotos.length" class="empty">{{ t('noPhotosYet') }}</p>
        <div v-else class="profile-photo-grid">
          <RouterLink
            v-for="photo in myPhotos.slice(0, 6)"
            :key="photo.id"
            class="profile-photo"
            :to="`/photos/${photo.id}`"
          >
            <img :src="imageUrl(photo.images.thumb)" :alt="photo.title" />
            <span class="profile-photo-year">{{ photo.year }}</span>
            <span v-if="!photo.published" class="profile-photo-flag">{{ t('onModeration') }}</span>
            <strong>{{ photo.title }}</strong>
          </RouterLink>
        </div>
      </article>

      <article class="panel profile-block">
        <header class="profile-block-head">
          <h2>{{ t('myComments') }}</h2>
          <button class="link-button" type="button" @click="activeTab = 'comments'">{{ t('seeAll') }}</button>
        </header>
        <p v-if="!myComments.length" class="empty">{{ t('noCommentsYet') }}</p>
        <ul v-else class="profile-comments preview">
          <li v-for="comment in myComments.slice(0, 4)" :key="comment.id">
            <RouterLink v-if="comment.photo" class="profile-comment-thumb" :to="`/photos/${comment.photo.id}`">
              <img :src="imageUrl(comment.photo.thumb_url)" :alt="comment.photo.title" />
            </RouterLink>
            <div>
              <RouterLink v-if="comment.photo" class="profile-comment-title" :to="`/photos/${comment.photo.id}`">
                {{ comment.photo.title }}
              </RouterLink>
              <p class="comment-body">{{ formatCommentBody(comment.body) }}</p>
              <small>{{ formatDate(comment.datetime) }}</small>
            </div>
          </li>
        </ul>
      </article>

      <article class="panel profile-block profile-quick">
        <h2>{{ t('quickActions') }}</h2>
        <div class="profile-quick-grid">
          <button class="quick-action" type="button" @click="goAddPhoto">
            <span class="quick-icon quick-icon-add" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="20" height="20">
                <path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
              </svg>
            </span>
            <span><strong>{{ t('addPhoto') }}</strong><small>{{ t('addNew') }}</small></span>
          </button>
          <RouterLink class="quick-action" to="/photos/random">
            <span class="quick-icon quick-icon-random" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="20" height="20">
                <path d="M16 4h4v4M20 4l-6 6M8 20H4v-4M4 20l6-6M16 20h4v-4M14 14l6 6M4 4l6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </span>
            <span><strong>{{ t('randomPhoto') }}</strong><small>{{ t('explorePhotos') }}</small></span>
          </RouterLink>
          <button class="quick-action" type="button" @click="activeTab = 'settings'">
            <span class="quick-icon quick-icon-edit" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="20" height="20">
                <path d="M4 20h4L19 9a2 2 0 0 0-3-3L5 17v3z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                <path d="M14 7l3 3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
              </svg>
            </span>
            <span><strong>{{ t('editProfile') }}</strong><small>{{ t('settings') }}</small></span>
          </button>
          <button class="quick-action" type="button" @click="activeTab = 'security'">
            <span class="quick-icon quick-icon-security" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="20" height="20">
                <path d="M12 3l7 3v5c0 4.4-3 7.7-7 9-4-1.3-7-4.6-7-9V6l7-3z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                <path d="M9.5 12l1.8 1.8L15 10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </span>
            <span><strong>{{ t('changePassword') }}</strong><small>{{ t('security') }}</small></span>
          </button>
        </div>
      </article>
    </section>

    <section v-if="activeTab === 'photos'" class="profile-tab-pane">
      <article class="panel profile-block">
        <header class="profile-block-head">
          <h2>{{ t('myPhotos') }}</h2>
          <button class="button button-ghost button-small" type="button" @click="goAddPhoto">{{ t('addNew') }}</button>
        </header>
        <p v-if="!myPhotos.length" class="empty">{{ t('noPhotosYet') }}</p>
        <div v-else class="profile-photo-grid">
          <RouterLink
            v-for="photo in myPhotos"
            :key="photo.id"
            class="profile-photo"
            :to="`/photos/${photo.id}`"
          >
            <img :src="imageUrl(photo.images.thumb)" :alt="photo.title" />
            <span class="profile-photo-year">{{ photo.year }}</span>
            <span v-if="!photo.published" class="profile-photo-flag">{{ t('onModeration') }}</span>
            <strong>{{ photo.title }}</strong>
            <small>{{ photo.views }} {{ t('views') }} · {{ photo.comments_count }} {{ t('comments') }}</small>
          </RouterLink>
        </div>
      </article>
    </section>

    <section v-if="activeTab === 'favorites'" class="profile-tab-pane">
      <article class="panel profile-block">
        <header class="profile-block-head">
          <h2>{{ t('myCollection') }}</h2>
          <span class="profile-counter"><LikeIcon filled /> {{ myFavorites.length }}</span>
        </header>
        <p v-if="!myFavorites.length" class="empty">{{ t('noFavoritesYet') }}</p>
        <div v-else class="profile-photo-grid">
          <div v-for="item in myFavorites" :key="item.id" class="favorite-tile">
            <RouterLink class="profile-photo favorite-photo" :to="`/photos/${item.photo.id}`">
              <img :src="imageUrl(item.photo.images.thumb)" :alt="item.photo.title" />
              <span class="profile-photo-year">{{ item.photo.year }}</span>
              <strong>{{ item.photo.title }}</strong>
              <small v-if="item.photo.author">{{ t('byAuthor') }} {{ item.photo.author.name || item.photo.author.uid }}</small>
            </RouterLink>
            <button
              type="button"
              class="favorite-remove"
              :aria-label="t('removeFromFavorites')"
              :title="t('removeFromFavorites')"
              @click="removeFavorite(item.photo.id)"
            ><LikeIcon filled /></button>
          </div>
        </div>
      </article>
    </section>

    <section v-if="activeTab === 'comments'" class="profile-tab-pane">
      <article class="panel profile-block">
        <h2>{{ t('myComments') }}</h2>
        <p v-if="!myComments.length" class="empty">{{ t('noCommentsYet') }}</p>
        <ul v-else class="profile-comments">
          <li v-for="comment in myComments" :key="comment.id">
            <RouterLink v-if="comment.photo" class="profile-comment-thumb" :to="`/photos/${comment.photo.id}`">
              <img :src="imageUrl(comment.photo.thumb_url)" :alt="comment.photo.title" />
            </RouterLink>
            <div>
              <RouterLink v-if="comment.photo" class="profile-comment-title" :to="`/photos/${comment.photo.id}`">
                {{ comment.photo.title }}
              </RouterLink>
              <p class="comment-body">{{ formatCommentBody(comment.body) }}</p>
              <small>{{ formatDate(comment.datetime) }}</small>
            </div>
          </li>
        </ul>
      </article>
    </section>

    <section v-if="activeTab === 'settings'" class="profile-tab-pane">
      <article class="panel profile-block">
        <h2>{{ t('editProfile') }}</h2>
        <form class="profile-form" @submit.prevent="saveProfile">
          <div class="profile-form-row">
            <label>
              <span>{{ t('firstName') }}</span>
              <input v-model="profileForm.first_name" :placeholder="t('firstName')" required />
            </label>
            <label>
              <span>{{ t('lastName') }}</span>
              <input v-model="profileForm.last_name" :placeholder="t('lastName')" />
            </label>
          </div>
          <label>
            <span>{{ t('email') }}</span>
            <input v-model="profileForm.email" type="email" :placeholder="t('email')" required />
          </label>
          <label>
            <span>{{ t('username') }}</span>
            <input :value="user.uid" disabled />
          </label>
          <label>
            <span>{{ t('sex') }}</span>
            <select v-model="profileForm.sex" required>
              <option value="" disabled>{{ t('chooseSex') }}</option>
              <option value="1">{{ t('male') }}</option>
              <option value="0">{{ t('female') }}</option>
            </select>
          </label>
          <label>
            <span>{{ t('birthDate') }}</span>
            <div class="profile-birth-grid">
              <select v-model="profileForm.birth_day" required>
                <option value="" disabled>{{ t('day') }}</option>
                <option v-for="day in days" :key="day" :value="day">{{ day }}</option>
              </select>
              <select v-model="profileForm.birth_month" required>
                <option value="" disabled>{{ t('month') }}</option>
                <option v-for="month in months" :key="month" :value="month">{{ month }}</option>
              </select>
              <select v-model="profileForm.birth_year" required>
                <option value="" disabled>{{ t('year') }}</option>
                <option v-for="year in years" :key="year" :value="year">{{ year }}</option>
              </select>
            </div>
          </label>
          <label>
            <span>{{ t('profileUrl') }}</span>
            <input v-model="profileForm.identity" :placeholder="t('profileUrl')" />
          </label>
          <p v-if="linkedNetworkLabel" class="profile-readonly">{{ t('linkedVia') }}: {{ linkedNetworkLabel }}</p>

          <div v-if="canLinkSocial && socialProviders.length" class="profile-social-link">
            <h3>{{ t('linkSocialAccount') }}</h3>
            <p class="muted-hint">{{ t('linkSocialHint') }}</p>
            <div class="profile-social-link-grid">
              <button
                v-for="provider in socialProviders"
                :key="provider.id"
                type="button"
                class="button button-ghost button-small profile-social-link-btn"
                :disabled="socialLinkBusy === provider.id"
                @click="startSocialLink(provider.id)"
              >
                {{ socialLinkBusy === provider.id ? t('loading') : provider.label }}
              </button>
            </div>
            <p v-if="socialLinkMessage" class="success-line">{{ socialLinkMessage }}</p>
            <p v-if="socialLinkError" class="error-line">{{ socialLinkError }}</p>
          </div>

          <div class="form-actions">
            <button class="button" type="submit">{{ t('saveProfile') }}</button>
            <p v-if="profileMessage" class="success-line">{{ profileMessage }}</p>
            <p v-if="profileError" class="error-line">{{ profileError }}</p>
          </div>
        </form>
      </article>

      <article class="panel profile-block">
        <h2>{{ t('changeAvatar') }}</h2>
        <div class="avatar-editor">
          <img class="avatar-preview" :src="avatarFor(user)" :alt="user.name" />
          <div>
            <button class="button" type="button" :disabled="avatarBusy" @click="pickAvatar">
              {{ avatarBusy ? t('loading') : t('changeAvatar') }}
            </button>
            <p class="muted-hint">{{ t('avatarHint') }}</p>
            <p v-if="avatarMessage" class="success-line">{{ avatarMessage }}</p>
            <p v-if="avatarError" class="error-line">{{ avatarError }}</p>
          </div>
        </div>
      </article>
    </section>

    <section v-if="activeTab === 'security'" class="profile-tab-pane">
      <article class="panel profile-block">
        <h2>{{ t('changePassword') }}</h2>
        <form class="profile-form" @submit.prevent="changePassword">
          <label>
            <span>{{ t('currentPassword') }}</span>
            <input v-model="passwordForm.current_password" type="password" required />
          </label>
          <label>
            <span>{{ t('newPassword') }}</span>
            <input v-model="passwordForm.password" type="password" minlength="8" required />
          </label>
          <label>
            <span>{{ t('confirmNewPassword') }}</span>
            <input v-model="passwordForm.password_confirmation" type="password" minlength="8" required />
          </label>
          <div class="form-actions">
            <button class="button" type="submit">{{ t('changePassword') }}</button>
            <p v-if="passwordMessage" class="success-line">{{ passwordMessage }}</p>
            <p v-if="passwordError" class="error-line">{{ passwordError }}</p>
          </div>
        </form>
      </article>
    </section>
  </section>
</template>

<style lang="scss">
.profile-loading {
  margin: 32px auto;
  max-width: 720px;
  text-align: center;
}

.profile-dashboard {
  display: grid;
  gap: 22px;
  padding: 0 22px 40px;
  max-width: 1180px;
  margin: 10px auto;

  @include mq-down($bp-md) {
    padding: 0 14px 32px;
  }
}

// ---------- Hero -------------------------------------------------
.profile-hero {
  position: relative;
  overflow: hidden;
  border: 1px solid $line;
  border-radius: $radius-xl;
  background:
    radial-gradient(120% 140% at 0% 0%, rgba($primary, 0.08), transparent 42%),
    radial-gradient(120% 160% at 100% 0%, rgba($accent, 0.07), transparent 46%),
    $surface;
  box-shadow: $shadow-lg;

  // Slim brand accent strip along the top edge.
  &::before {
    content: '';
    position: absolute;
    inset: 0 0 auto 0;
    height: 4px;
    background: linear-gradient(90deg, $primary, $accent);
    pointer-events: none;
  }
}

.profile-hero-body {
  position: relative;
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 24px;
  padding: 26px 30px;

  @include mq-down($bp-md) {
    grid-template-columns: auto 1fr;
    gap: 14px;
    padding: 20px;
  }

  @include mq-down($bp-sm) {
    grid-template-columns: 1fr;
    text-align: center;
    justify-items: center;
  }
}

.profile-avatar {
  position: relative;
  width: 112px;
  height: 112px;
  padding: 0;
  border: 3px solid $surface;
  border-radius: 50%;
  overflow: hidden;
  background: $surface-soft;
  cursor: pointer;
  box-shadow: 0 0 0 3px rgba($primary, 0.5), 0 12px 28px rgba(20, 45, 110, 0.18);
  @include interactive((transform, box-shadow));

  @include mq-down($bp-md) {
    width: 96px;
    height: 96px;
  }

  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 0 0 3px $primary, 0 16px 34px rgba(20, 45, 110, 0.26);
  }

  &:focus-visible {
    outline: 3px solid rgba($primary, 0.5);
    outline-offset: 4px;
  }

  &:disabled {
    cursor: progress;
    opacity: 0.7;
  }

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  &-overlay {
    position: absolute;
    inset: auto 0 0 0;
    padding: 6px 10px;
    background: rgba(8, 22, 60, 0.65);
    color: #fff;
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.02em;
    text-align: center;
    opacity: 0;
    transform: translateY(4px);
    @include interactive((opacity, transform));
  }

  &:hover &-overlay,
  &:focus-visible &-overlay {
    opacity: 1;
    transform: translateY(0);
  }
}

.profile-avatar-initials {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
  background: linear-gradient(135deg, $primary, $primary-dark);
  color: #fff;
  font-size: 40px;
  font-weight: 600;
  letter-spacing: 0.01em;
  user-select: none;
}

.profile-hero-info {
  display: grid;
  gap: 6px;
  min-width: 0;

  .eyebrow {
    color: $primary;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    font-size: 11px;
    font-weight: 600;
  }

  h1 {
    margin: 0;
    color: $ink;
    font-size: clamp(22px, 4vw, 28px);
    font-weight: 600;
    letter-spacing: -0.01em;
    word-break: break-word;
  }
}

.profile-handle {
  margin: 0;
  color: $muted;
  font-size: 13px;
}

.profile-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 6px;

  @include mq-down($bp-sm) {
    justify-content: center;
  }
}

.badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 11px;
  border-radius: $radius-pill;
  font-size: 12px;
  font-weight: 500;
}

.badge-admin {
  background: rgba($accent, 0.14);
  color: $accent-dark;
  box-shadow: inset 0 0 0 1px rgba($accent, 0.3);
}

.badge-user {
  background: rgba($primary, 0.1);
  color: $primary-dark;
  box-shadow: inset 0 0 0 1px rgba($primary, 0.22);
}

.badge-soft {
  background: rgba($ink, 0.04);
  color: $muted;
  box-shadow: inset 0 0 0 1px rgba($ink, 0.08);
}

.profile-hero-actions {
  display: grid;
  gap: 8px;
  justify-items: stretch;

  @include mq-down($bp-md) {
    grid-column: 1 / -1;
    grid-auto-flow: column;
    grid-auto-columns: 1fr;
  }

  .button {
    min-width: 168px;
  }
}

// ---------- Stats ------------------------------------------------
.profile-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 14px;

  @include mq-down($bp-md) {
    grid-template-columns: 1fr;
  }
}

.stat-card {
  display: grid;
  grid-template-columns: auto 1fr;
  align-items: center;
  gap: 14px;
  padding: 18px 20px;
  border: 1px solid $line;
  border-radius: $radius-lg;
  background: $surface;
  box-shadow: $shadow-sm;
  @include hover-lift(-2px, $shadow-md);
}

.stat-icon {
  display: grid;
  place-items: center;
  width: 46px;
  height: 46px;
  border-radius: $radius-md;
  color: $primary;
  background: rgba($primary, 0.1);

  &-views {
    color: $accent-dark;
    background: rgba($accent, 0.14);
  }

  &-comments {
    color: #1f9d63;
    background: rgba(34, 173, 110, 0.14);
  }
}

.stat-body {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.stat-label {
  color: $muted;
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.stat-value {
  font-size: 26px;
  font-weight: 600;
  color: $ink;
  line-height: 1.1;
  letter-spacing: -0.02em;
  font-variant-numeric: tabular-nums;
}

.stat-meta {
  color: $muted;
  font-size: 12px;
}

// ---------- Tabs -------------------------------------------------
.profile-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 6px;
  border-radius: $radius-md;
  background: $surface;
  box-shadow: $shadow-lg;

  @include mq-down($bp-md) {
    flex-wrap: nowrap;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;

    &::-webkit-scrollbar {
      display: none;
    }
  }
}

.profile-tab {
  flex: 1 1 120px;
  padding: 10px 14px;

  @include mq-down($bp-md) {
    flex: 0 0 auto;
    white-space: nowrap;
    padding: 10px 16px;
  }
  border: 0;
  border-radius: $radius-sm;
  background: transparent;
  color: $muted;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  @include interactive((background, color, box-shadow));

  &:hover {
    color: $primary;
    background: rgba($primary, 0.06);
  }

  &.active {
    background: linear-gradient(135deg, $primary, $primary-dark);
    color: #fff;
    box-shadow: 0 10px 26px rgba($primary, 0.32);
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.profile-tab-pane {
  display: grid;
  gap: 18px;
}

.overview-pane {
  grid-template-columns: 1.5fr 1fr;

  @include mq-down($bp-md) {
    grid-template-columns: 1fr;
  }

  .profile-quick {
    grid-column: 1 / -1;
  }
}

.profile-block {
  padding: 22px 24px;
}

.profile-block-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;

  h2 {
    margin: 0;
    font-size: 17px;
  }
}

// ---------- Photos grid -----------------------------------------
.profile-photo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 12px;
}

.profile-photo {
  position: relative;
  display: grid;
  gap: 4px;
  padding: 0;
  border-radius: $radius-sm + 2;
  overflow: hidden;
  background: $surface-soft;
  text-decoration: none;
  color: inherit;
  box-shadow: $shadow-sm;
  @include hover-lift(-3px, 0 14px 30px rgba(20, 45, 110, 0.2));

  &:focus-visible {
    outline: 2px solid rgba($primary, 0.45);
    outline-offset: 2px;
  }

  img {
    display: block;
    width: 100%;
    height: 130px;
    object-fit: cover;
    @include interactive((transform));
  }

  &:hover img {
    transform: scale(1.04);
  }

  strong {
    display: block;
    padding: 8px 10px 0;
    font-size: 13px;
    font-weight: 500;
    line-height: 1.3;
    @include truncate;
  }

  small {
    display: block;
    padding: 2px 10px 10px;
    color: $muted;
    font-size: 11px;
  }

  &-year {
    position: absolute;
    top: 8px;
    left: 8px;
    padding: 3px 8px;
    border-radius: $radius-pill;
    background: rgba(8, 22, 60, 0.6);
    color: #fff;
    font-size: 11px;
    font-weight: 500;
    backdrop-filter: blur(4px);
  }

  &-flag {
    position: absolute;
    top: 8px;
    right: 8px;
    padding: 3px 8px;
    border-radius: $radius-pill;
    background: rgba($accent, 0.95);
    color: #261301;
    font-size: 11px;
    font-weight: 500;
  }
}

// ---------- Comments list ---------------------------------------
.profile-comments {
  display: grid;
  gap: 12px;
  list-style: none;
  margin: 0;
  padding: 0;

  li {
    display: grid;
    grid-template-columns: 64px 1fr;
    gap: 12px;
    padding: 10px;
    border-radius: $radius-sm + 2;
    background: $surface-soft;
    @include interactive((background));

    &:hover {
      background: darken($surface-soft, 2%);
    }
  }

  &.preview li {
    background: transparent;
    padding: 6px 0;
    border-bottom: 1px solid $line;

    &:last-child {
      border-bottom: 0;
    }

    &:hover {
      background: transparent;
    }
  }

  p,
  .comment-body {
    margin: 4px 0 4px;
    font-size: 13px;
    line-height: 1.45;
    color: $ink;
    white-space: pre-line;
    @include clamp-lines(3);
  }

  small {
    color: $muted;
    font-size: 11px;
  }
}

.profile-comment-thumb img {
  width: 64px;
  height: 64px;
  border-radius: $radius-sm;
  object-fit: cover;
}

.profile-comment-title {
  display: block;
  color: $primary;
  font-size: 13px;
  font-weight: 600;
  text-decoration: none;
  @include interactive((color));

  &:hover {
    color: $primary-dark;
    text-decoration: underline;
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

// ---------- Quick actions ---------------------------------------
.profile-quick-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 12px;
}

.quick-action {
  display: grid;
  grid-template-columns: 44px 1fr;
  align-items: center;
  gap: 12px;
  padding: 14px;
  border: 1px solid $line;
  border-radius: $radius-sm + 2;
  background: $surface;
  color: $ink;
  text-align: left;
  cursor: pointer;
  text-decoration: none;
  font: inherit;
  @include interactive((border-color, transform, box-shadow));

  &:hover {
    border-color: $primary;
    transform: translateY(-1px);
    box-shadow: 0 12px 24px rgba(20, 45, 110, 0.12);
  }

  &:active {
    transform: translateY(0);
  }

  @include focus-ring(rgba($primary, 0.4), 3px);

  span:last-child {
    display: grid;
    gap: 2px;
  }

  strong {
    font-size: 13px;
    font-weight: 600;
  }

  small {
    color: $muted;
    font-size: 11px;
  }
}

.quick-icon {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: $radius-sm;
  background: rgba($primary, 0.1);
  color: $primary;
  @include interactive((background, color));

  svg {
    display: block;
  }

  &-random {
    color: $accent-dark;
    background: rgba($accent, 0.14);
  }

  &-security {
    color: #1f9d63;
    background: rgba(34, 173, 110, 0.14);
  }
}

.quick-action:hover .quick-icon {
  background: $primary;
  color: #fff;

  &-random {
    background: $accent-dark;
  }

  &-security {
    background: #1f9d63;
  }
}

// ---------- Forms -----------------------------------------------
.profile-form {
  display: grid;
  gap: 14px;

  label {
    display: grid;
    gap: 6px;
    color: $muted;
    font-size: 12px;
    font-weight: 500;
  }

  input,
  select {
    padding: 11px 14px;
    border: 1px solid $line;
    border-radius: $radius-sm;
    background-color: $surface-soft;
    color: $ink;
    font-size: 14px;
    font-weight: 400;
    @include interactive((border-color, background-color, box-shadow));

    &:hover {
      border-color: darken($line, 8%);
    }

    &:focus {
      outline: none;
      border-color: $primary;
      background-color: #fff;
      box-shadow: 0 0 0 3px rgba($primary, 0.16);
    }

    &:disabled {
      background-color: $line;
      color: $muted;
      cursor: not-allowed;
    }
  }

  select {
    width: 100%;
    appearance: none;
    -webkit-appearance: none;
    padding-right: 38px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 13px center;
    cursor: pointer;
  }
}

.profile-birth-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}

.profile-readonly {
  margin: 0;
  color: $muted;
  font-size: 13px;
}

.profile-social-link {
  display: grid;
  gap: 10px;
  padding-top: 8px;
  border-top: 1px solid $line;

  h3 {
    margin: 0;
    font-size: 15px;
  }
}

.profile-social-link-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.profile-form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;

  @include mq-down($bp-md) {
    grid-template-columns: 1fr;
  }
}

.avatar-editor {
  display: grid;
  grid-template-columns: 96px 1fr;
  gap: 16px;
  align-items: center;

  @include mq-down($bp-md) {
    grid-template-columns: 80px 1fr;
  }
}

.avatar-preview {
  width: 96px;
  height: 96px;
  border-radius: 50%;
  object-fit: cover;
  background: $surface-soft;
  box-shadow: 0 8px 20px rgba(20, 45, 110, 0.12);
}

// ---------- Favorites / likes tab -------------------------------
.profile-counter {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  border-radius: $radius-pill;
  background: linear-gradient(135deg, #2374e1, #1457b3);
  color: #fff;
  font-size: 12px;
  font-weight: 600;
  box-shadow: 0 6px 14px rgba(35, 116, 225, 0.3);

  .like-icon {
    font-size: 14px;
    color: #fff;
  }
}

.favorite-tile {
  position: relative;
}

.favorite-photo {
  width: 100%;
}

.favorite-remove {
  position: absolute;
  top: 8px;
  right: 8px;
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: linear-gradient(135deg, #2374e1, #1457b3);
  color: #fff;
  cursor: pointer;
  line-height: 1;
  box-shadow: 0 6px 14px rgba(8, 22, 60, 0.18);
  @include interactive((background, color, transform));

  .like-icon {
    font-size: 16px;
    color: #fff;
  }

  &:hover {
    background: linear-gradient(135deg, #1c64c8, #11489a);
    transform: scale(1.08);
  }

  @include focus-ring(rgba(35, 116, 225, 0.4), 2px);
}
</style>

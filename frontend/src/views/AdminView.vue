<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, imageUrl } from '../api'
import { useI18n } from '../i18n'
import { formatDate } from '../utils/locale'
import { isAdminUser, parseBirthdate, sexLabel } from '../utils/user'

const router = useRouter()
const { t, currentLanguage } = useI18n()

const PER_PAGE = 50

const tab = ref('photos')
const photoFilter = ref('pending')
const rows = ref([])
const meta = ref(null)
const stats = ref(null)
const loading = ref(false)
const loadingMore = ref(false)
const error = ref('')
const actionError = ref('')
const busyId = ref(null)

const userSearch = ref('')
let userSearchTimer

const sentinel = ref(null)
let observer

const newsEditorOpen = ref(false)
const newsForm = ref(emptyNewsForm())
const userEditorId = ref(null)
const userDetailId = ref(null)
const userPassword = ref('')
const feedbackDetailId = ref(null)

const pendingCount = computed(() => stats.value?.photos_pending ?? 0)
const feedbackUnreadCount = computed(() => stats.value?.feedback_unread ?? 0)
const hasMore = computed(() => meta.value && meta.value.current_page < meta.value.last_page)
const shownCount = computed(() => rows.value.length)
const totalCount = computed(() => meta.value?.total ?? rows.value.length)
const isPaginatedTab = computed(() => ['photos', 'users', 'news', 'feedback'].includes(tab.value))

const tabs = computed(() => [
  { id: 'photos', label: t('photos'), icon: '🖼', badge: pendingCount.value },
  { id: 'users', label: t('users'), icon: '👤', badge: 0 },
  { id: 'news', label: t('news'), icon: '📰', badge: 0 },
  { id: 'feedback', label: t('feedback'), icon: '✉️', badge: feedbackUnreadCount.value },
])

const tabDescription = computed(
  () =>
    ({
      photos: t('adminTabPhotosDesc'),
      users: t('adminTabUsersDesc'),
      news: t('adminTabNewsDesc'),
      feedback: t('adminTabFeedbackDesc'),
    })[tab.value] || '',
)

function emptyNewsForm() {
  return { id: null, title: '', content: '', date: '', published: true }
}

function formatNewsDateInput(value) {
  if (!value) return new Date().toISOString().slice(0, 16)
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return new Date().toISOString().slice(0, 16)
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

async function ensureAdmin() {
  try {
    const me = await api('/auth/me')
    if (!isAdminUser(me)) {
      router.replace('/')
      return false
    }
    return true
  } catch {
    router.replace('/')
    return false
  }
}

function listEndpoint(page = 1) {
  if (tab.value === 'photos') {
    const params = new URLSearchParams({
      per_page: String(PER_PAGE),
      page: String(page),
    })
    if (photoFilter.value === 'pending') {
      params.set('status', 'pending')
    } else if (photoFilter.value === 'published') {
      params.set('status', 'published')
    } else if (photoFilter.value === 'review') {
      params.set('status', 'review')
    }
    return `/admin/photos?${params.toString()}`
  }
  if (tab.value === 'users') {
    const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) })
    if (userSearch.value.trim()) params.set('search', userSearch.value.trim())
    return `/admin/users?${params.toString()}`
  }
  if (tab.value === 'news') {
    return `/admin/news?per_page=${PER_PAGE}&page=${page}`
  }
  if (tab.value === 'feedback') {
    return `/admin/feedback?per_page=${PER_PAGE}&page=${page}`
  }
  return `/admin/photos?per_page=${PER_PAGE}&page=${page}`
}

async function loadTab(nextTab = tab.value, { append = false, page = 1 } = {}) {
  if (!append) {
    tab.value = nextTab
    closeEditors()
  }

  if (append) {
    loadingMore.value = true
  } else {
    loading.value = true
    error.value = ''
    actionError.value = ''
    rows.value = []
    meta.value = null
  }

  try {
    const payload = await api(listEndpoint(page))

    rows.value = append ? [...rows.value, ...(payload.data || [])] : payload.data || []
    meta.value = payload
  } catch (event) {
    if (!append) {
      error.value = event.message
      rows.value = []
      meta.value = null
    } else {
      actionError.value = event.message
    }
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

async function loadDashboard() {
  stats.value = await api('/admin/dashboard')
}

async function refresh() {
  await loadDashboard()
  await loadTab(tab.value)
}

function setPhotoFilter(next) {
  photoFilter.value = next
  if (tab.value === 'photos') {
    loadTab('photos')
  }
}

function loadMore() {
  if (!hasMore.value || loadingMore.value || loading.value) return
  loadTab(tab.value, { append: true, page: meta.value.current_page + 1 })
}

function removeRow(id) {
  const index = rows.value.findIndex((item) => item.id === id)
  if (index >= 0) rows.value.splice(index, 1)
  if (meta.value && typeof meta.value.total === 'number') {
    meta.value.total = Math.max(0, meta.value.total - 1)
  }
}

function closeEditors() {
  newsEditorOpen.value = false
  userEditorId.value = null
  userPassword.value = ''
  feedbackDetailId.value = null
}

function feedbackMailto(row) {
  const subject = encodeURIComponent(`HinYerevan — ${row.name}`)
  return `mailto:${row.email}?subject=${subject}`
}

async function toggleFeedbackDetail(row) {
  feedbackDetailId.value = feedbackDetailId.value === row.id ? null : row.id
  if (!row.read) {
    await markFeedbackRead(row, { silent: true })
  }
}

async function markFeedbackRead(row, { silent = false } = {}) {
  if (row.read) return

  busyId.value = row.id
  actionError.value = ''
  try {
    const updated = await api(`/admin/feedback/${row.id}`, { method: 'PUT' })
    const index = rows.value.findIndex((item) => item.id === row.id)
    if (index >= 0) rows.value[index] = updated
    if (stats.value?.feedback_unread) {
      stats.value.feedback_unread = Math.max(0, stats.value.feedback_unread - 1)
    }
  } catch (event) {
    if (!silent) actionError.value = event.message
  } finally {
    busyId.value = null
  }
}

async function deleteFeedback(row) {
  if (!window.confirm(t('adminFeedbackDeleteConfirm'))) return

  busyId.value = row.id
  actionError.value = ''
  try {
    await api(`/admin/feedback/${row.id}`, { method: 'DELETE' })
    if (feedbackDetailId.value === row.id) feedbackDetailId.value = null
    removeRow(row.id)
    await loadDashboard()
  } catch (event) {
    actionError.value = event.message
  } finally {
    busyId.value = null
  }
}

async function approvePhoto(photo, published) {
  busyId.value = photo.id
  actionError.value = ''
  try {
    const updated = await api(`/admin/photos/${photo.id}`, {
      method: 'PUT',
      body: { published: published ? 1 : 0 },
    })
    const droppedFromList =
      (photoFilter.value === 'pending' && published) ||
      (photoFilter.value === 'published' && !published)

    if (droppedFromList) {
      removeRow(photo.id)
    } else {
      const index = rows.value.findIndex((item) => item.id === photo.id)
      if (index >= 0) rows.value[index] = updated
    }
    await loadDashboard()
  } catch (event) {
    actionError.value = event.message
  } finally {
    busyId.value = null
  }
}

async function markLocated(photo) {
  busyId.value = photo.id
  actionError.value = ''
  try {
    const updated = await api(`/admin/photos/${photo.id}`, {
      method: 'PUT',
      body: { needs_location_review: 0 },
    })
    if (photoFilter.value === 'review') {
      removeRow(photo.id)
    } else {
      const index = rows.value.findIndex((item) => item.id === photo.id)
      if (index >= 0) rows.value[index] = updated
    }
  } catch (event) {
    actionError.value = event.message
  } finally {
    busyId.value = null
  }
}

async function deletePhoto(photo) {
  if (!window.confirm(t('adminDeleteConfirm'))) return

  busyId.value = photo.id
  actionError.value = ''
  try {
    await api(`/admin/photos/${photo.id}`, { method: 'DELETE' })
    removeRow(photo.id)
    await loadDashboard()
  } catch (event) {
    actionError.value = event.message
  } finally {
    busyId.value = null
  }
}

async function changeUserRole(user, event) {
  const type = Number(event.target.value)
  if (user.type === type) return

  busyId.value = user.id
  actionError.value = ''
  try {
    const updated = await api(`/admin/users/${user.id}`, { method: 'PUT', body: { type } })
    user.type = Number(updated?.type ?? type)
  } catch (err) {
    actionError.value = err.message
    event.target.value = String(user.type)
  } finally {
    busyId.value = null
  }
}

function toggleUserEditor(user) {
  userEditorId.value = userEditorId.value === user.id ? null : user.id
  userPassword.value = ''
}

function toggleUserDetail(user) {
  userDetailId.value = userDetailId.value === user.id ? null : user.id
}

function userBirthLabel(user) {
  const birth = parseBirthdate(user.bdate)
  if (!birth.birth_year) return '—'
  return `${birth.birth_day}.${birth.birth_month}.${birth.birth_year}`
}

async function saveUserPassword(user) {
  if (!userPassword.value || userPassword.value.length < 8) {
    actionError.value = t('newPassword')
    return
  }

  busyId.value = user.id
  actionError.value = ''
  try {
    await api(`/admin/users/${user.id}`, { method: 'PUT', body: { password: userPassword.value } })
    userPassword.value = ''
    userEditorId.value = null
  } catch (event) {
    actionError.value = event.message
  } finally {
    busyId.value = null
  }
}

function openNewsEditor(item = null) {
  if (!item) {
    newsForm.value = emptyNewsForm()
    newsForm.value.date = formatNewsDateInput()
  } else {
    newsForm.value = {
      id: item.id,
      title: item.title || '',
      content: item.content || '',
      date: formatNewsDateInput(item.date),
      published: item.published !== false && item.published !== 0,
    }
  }
  newsEditorOpen.value = true
}

async function saveNews() {
  busyId.value = newsForm.value.id || 'new'
  actionError.value = ''
  const body = {
    title: newsForm.value.title.trim(),
    content: newsForm.value.content,
    date: new Date(newsForm.value.date).toISOString(),
    published: newsForm.value.published ? 1 : 0,
  }

  try {
    if (newsForm.value.id) {
      await api(`/admin/news/${newsForm.value.id}`, { method: 'PUT', body })
    } else {
      await api('/admin/news', { method: 'POST', body })
    }
    newsEditorOpen.value = false
    await loadDashboard()
    await loadTab('news')
  } catch (event) {
    actionError.value = event.message
  } finally {
    busyId.value = null
  }
}

async function deleteNewsItem() {
  if (!newsForm.value.id || !window.confirm(t('adminNewsDeleteConfirm'))) return

  busyId.value = newsForm.value.id
  actionError.value = ''
  try {
    await api(`/admin/news/${newsForm.value.id}`, { method: 'DELETE' })
    newsEditorOpen.value = false
    await loadDashboard()
    await loadTab('news')
  } catch (event) {
    actionError.value = event.message
  } finally {
    busyId.value = null
  }
}

function openPhoto(photo) {
  router.push(`/photos/${photo.id}`)
}

function setupObserver() {
  observer?.disconnect()
  if (!sentinel.value) return
  observer = new IntersectionObserver((entries) => {
    if (entries.some((entry) => entry.isIntersecting)) loadMore()
  })
  observer.observe(sentinel.value)
}

onMounted(async () => {
  if (!(await ensureAdmin())) return
  await refresh()
  await nextTick()
  setupObserver()
})

onBeforeUnmount(() => observer?.disconnect())

watch(userSearch, () => {
  if (tab.value !== 'users') return
  clearTimeout(userSearchTimer)
  userSearchTimer = setTimeout(() => loadTab('users'), 320)
})

watch(tab, async () => {
  await nextTick()
  setupObserver()
})

watch([hasMore, loading], async () => {
  await nextTick()
  setupObserver()
})
</script>

<template>
  <section class="admin">
    <header class="admin__head">
      <h1>{{ t('moderationConsole') }}</h1>
      <div v-if="stats" class="admin__stat-cards">
        <div class="admin__stat-card admin__stat-card--accent">
          <strong>{{ pendingCount }}</strong>
          <span>{{ t('pending') }}</span>
        </div>
        <div class="admin__stat-card">
          <strong>{{ stats.photos_published }}</strong>
          <span>{{ t('published') }}</span>
        </div>
        <div class="admin__stat-card">
          <strong>{{ stats.photos_total }}</strong>
          <span>{{ t('totalPhotos') }}</span>
        </div>
        <div class="admin__stat-card">
          <strong>{{ stats.users_total }}</strong>
          <span>{{ t('users') }}</span>
        </div>
        <div v-if="feedbackUnreadCount" class="admin__stat-card admin__stat-card--accent">
          <strong>{{ feedbackUnreadCount }}</strong>
          <span>{{ t('feedback') }}</span>
        </div>
      </div>
    </header>

    <nav class="admin__tabs">
      <button
        v-for="item in tabs"
        :key="item.id"
        type="button"
        class="admin__tab"
        :class="{ on: tab === item.id }"
        @click="loadTab(item.id)"
      >
        <span class="admin__tab-icon" aria-hidden="true">{{ item.icon }}</span>
        <span>{{ item.label }}</span>
        <span v-if="item.badge" class="admin__tab-badge">{{ item.badge }}</span>
      </button>
    </nav>

    <p v-if="tabDescription" class="admin__desc">{{ tabDescription }}</p>

    <div v-if="tab === 'photos'" class="admin__subtabs">
      <button type="button" class="admin__chip" :class="{ on: photoFilter === 'pending' }" @click="setPhotoFilter('pending')">{{ t('pending') }}</button>
      <button type="button" class="admin__chip" :class="{ on: photoFilter === 'published' }" @click="setPhotoFilter('published')">{{ t('published') }}</button>
      <button type="button" class="admin__chip" :class="{ on: photoFilter === 'review' }" @click="setPhotoFilter('review')">{{ t('adminNeedsReview') }}</button>
      <button type="button" class="admin__chip" :class="{ on: photoFilter === 'all' }" @click="setPhotoFilter('all')">{{ t('allPhotos') }}</button>
    </div>

    <div v-if="tab === 'users'" class="admin__bar">
      <input v-model="userSearch" type="search" class="admin__input" :placeholder="t('adminSearchUsers')" />
    </div>

    <div v-if="tab === 'news'" class="admin__bar">
      <button type="button" class="admin__btn" @click="openNewsEditor()">{{ t('adminAddNews') }}</button>
    </div>

    <p v-if="error" class="admin__msg admin__msg--err">{{ error }}</p>
    <p v-if="actionError" class="admin__msg admin__msg--err">{{ actionError }}</p>

    <section v-if="newsEditorOpen" class="admin__box admin__form">
      <h2>{{ newsForm.id ? t('adminEditNews') : t('adminAddNews') }}</h2>
      <label class="admin__field">
        <span>{{ t('adminNewsTitle') }}</span>
        <input v-model="newsForm.title" class="admin__input" type="text" required />
      </label>
      <label class="admin__field">
        <span>{{ t('adminNewsDate') }}</span>
        <input v-model="newsForm.date" class="admin__input" type="datetime-local" />
      </label>
      <label class="admin__field admin__field--row">
        <input v-model="newsForm.published" type="checkbox" />
        <span>{{ t('published') }}</span>
      </label>
      <label class="admin__field">
        <span>{{ t('adminNewsContent') }}</span>
        <textarea v-model="newsForm.content" class="admin__input admin__textarea" rows="12" />
      </label>
      <div class="admin__form-actions">
        <button type="button" class="admin__btn" :disabled="!!busyId" @click="saveNews">{{ t('save') }}</button>
        <button type="button" class="admin__btn admin__btn--plain" @click="newsEditorOpen = false">{{ t('cancel') }}</button>
        <button v-if="newsForm.id" type="button" class="admin__btn admin__btn--danger" :disabled="!!busyId" @click="deleteNewsItem">
          {{ t('adminDelete') }}
        </button>
      </div>
    </section>

    <section class="admin__box">
      <p v-if="loading && !rows.length" class="admin__empty">{{ t('loading') }}</p>
      <p v-else-if="!rows.length" class="admin__empty">{{ t('adminEmpty') }}</p>

      <template v-else>
        <p v-if="isPaginatedTab && meta" class="admin__count">
          {{ t('adminShowing', { shown: shownCount, total: totalCount }) }}
        </p>

        <table v-if="tab === 'photos'" class="admin__table">
          <thead>
            <tr>
              <th></th>
              <th>{{ t('title') }}</th>
              <th>{{ t('year') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in rows"
              :key="row.id"
              class="admin__photo-row"
              :class="row.needs_location_review ? 'admin__photo-row--review' : (row.published ? 'admin__photo-row--published' : 'admin__photo-row--pending')"
            >
              <td class="admin__thumb">
                <a href="#" @click.prevent="openPhoto(row)">
                  <img :src="imageUrl(row.images?.thumb)" :alt="row.title" loading="lazy" width="64" height="48" />
                </a>
              </td>
              <td>
                <a href="#" class="admin__link" @click.prevent="openPhoto(row)">{{ row.title }}</a>
                <div class="admin__muted">
                  {{ row.author?.name || row.user }}
                  <template v-if="row.datetime"> · {{ formatDate(row.datetime, currentLanguage) }}</template>
                </div>
                <span v-if="row.needs_location_review" class="admin__review-badge">{{ t('reviewBadge') }}</span>
              </td>
              <td>{{ row.year }}</td>
              <td class="admin__actions">
                <div class="admin__act-group">
                  <button v-if="!row.published" type="button" class="admin__act admin__act--ok" :disabled="busyId === row.id" @click="approvePhoto(row, true)">
                    {{ t('approve') }}
                  </button>
                  <button v-else type="button" class="admin__act" :disabled="busyId === row.id" @click="approvePhoto(row, false)">
                    {{ t('unpublish') }}
                  </button>
                  <button v-if="row.needs_location_review" type="button" class="admin__act admin__act--review" :disabled="busyId === row.id" @click="markLocated(row)">
                    {{ t('markLocated') }}
                  </button>
                  <button type="button" class="admin__act admin__act--danger" :disabled="busyId === row.id" @click="deletePhoto(row)">
                    {{ t('adminDelete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>

        <table v-else class="admin__table">
          <tbody>
          <tr v-for="row in rows" :key="row.id">
            <template v-if="tab === 'users'">
              <td colspan="2">
                <button type="button" class="admin__link admin__link--title" @click="toggleUserDetail(row)">
                  {{ row.first_name }} {{ row.last_name }}
                </button>
                <div class="admin__muted">{{ row.email }} · @{{ row.uid }}</div>
                <div v-if="userDetailId === row.id" class="admin__user-detail">
                  <p><strong>{{ t('sex') }}:</strong> {{ sexLabel(row.sex, t) }}</p>
                  <p><strong>{{ t('birthDate') }}:</strong> {{ userBirthLabel(row) }}</p>
                  <p v-if="row.identity"><strong>{{ t('profileUrl') }}:</strong> {{ row.identity }}</p>
                  <p v-if="row.network"><strong>{{ t('network') }}:</strong> {{ row.network }}</p>
                  <p v-if="row.last_ip"><strong>IP:</strong> {{ row.last_ip }}</p>
                  <p><strong>{{ t('unique') }}:</strong> {{ row.unique }}</p>
                </div>
                <div v-if="userEditorId === row.id" class="admin__inline">
                  <input v-model="userPassword" type="password" class="admin__input" :placeholder="t('adminNewPassword')" />
                  <button type="button" class="admin__btn" :disabled="busyId === row.id" @click="saveUserPassword(row)">{{ t('save') }}</button>
                </div>
              </td>
              <td>
                <select
                  class="admin__select"
                  :value="row.type"
                  :disabled="busyId === row.id"
                  @change="changeUserRole(row, $event)"
                >
                  <option :value="0">{{ t('roleUser') }}</option>
                  <option :value="1">{{ t('blocked') }}</option>
                  <option :value="5">{{ t('roleAdmin') }}</option>
                </select>
              </td>
              <td>
                <div class="admin__act-group">
                  <button type="button" class="admin__act" @click="toggleUserDetail(row)">{{ t('adminEdit') }}</button>
                  <button type="button" class="admin__act" @click="toggleUserEditor(row)">{{ t('changePassword') }}</button>
                </div>
              </td>
            </template>

            <template v-else-if="tab === 'news'">
              <td colspan="2">
                <button type="button" class="admin__link admin__link--title" @click="openNewsEditor(row)">{{ row.title }}</button>
                <div class="admin__muted">{{ formatDate(row.date, currentLanguage) }}</div>
              </td>
              <td>
                <span class="admin__badge" :class="row.published ? 'admin__badge--ok' : 'admin__badge--wait'">
                  {{ row.published ? t('published') : t('pending') }}
                </span>
              </td>
              <td>
                <div class="admin__act-group">
                  <button type="button" class="admin__act" @click="openNewsEditor(row)">{{ t('adminEdit') }}</button>
                </div>
              </td>
            </template>

            <template v-else-if="tab === 'feedback'">
              <td colspan="2" :class="{ 'admin__cell--unread': !row.read }">
                <button type="button" class="admin__link admin__link--title" @click="toggleFeedbackDetail(row)">
                  {{ row.name }}
                </button>
                <div class="admin__muted">
                  <a :href="`mailto:${row.email}`">{{ row.email }}</a>
                  <template v-if="row.created_at"> · {{ formatDate(row.created_at, currentLanguage) }}</template>
                </div>
                <p v-if="feedbackDetailId === row.id" class="admin__feedback-body">{{ row.content }}</p>
                <p v-else class="admin__feedback-preview">{{ row.content }}</p>
              </td>
              <td>
                <span v-if="!row.read" class="admin__badge admin__badge--wait">{{ t('pending') }}</span>
                <span v-else class="admin__muted">—</span>
              </td>
              <td class="admin__actions">
                <div class="admin__act-group">
                  <a class="admin__act admin__act--ok" :href="feedbackMailto(row)">{{ t('adminFeedbackReply') }}</a>
                  <button v-if="!row.read" type="button" class="admin__act" :disabled="busyId === row.id" @click="markFeedbackRead(row)">
                    {{ t('adminFeedbackMarkRead') }}
                  </button>
                  <button type="button" class="admin__act admin__act--danger" :disabled="busyId === row.id" @click="deleteFeedback(row)">
                    {{ t('adminDelete') }}
                  </button>
                </div>
              </td>
            </template>

          </tr>
          </tbody>
        </table>

        <p v-if="isPaginatedTab && hasMore" class="admin__more">
          <button type="button" class="admin__btn admin__btn--plain" :disabled="loadingMore" @click="loadMore">
            {{ loadingMore ? t('loading') : t('adminLoadMore') }}
          </button>
          <span ref="sentinel" class="admin__sentinel" aria-hidden="true" />
        </p>

        <p v-else-if="isPaginatedTab && meta && !hasMore && rows.length" class="admin__empty admin__empty--end">{{ t('allPhotosLoaded') }}</p>
      </template>
    </section>
  </section>
</template>

<style lang="scss">
.admin {
  max-width: 960px;
  margin: 0 auto 48px;
  padding: 0 16px;
  font-size: 14px;
  line-height: 1.45;
}

.admin__head {
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid $line;

  h1 {
    margin: 0 0 6px;
    font-size: 18px;
    font-weight: 600;
  }
}

.admin__stat-cards {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0;
}

.admin__stat-card {
  display: flex;
  flex-direction: column;
  min-width: 78px;
  padding: 8px 12px;
  border: 1px solid $line;
  border-radius: $radius-md;
  background: $surface-soft;

  strong {
    font-size: 18px;
    font-weight: 700;
    line-height: 1.1;
  }

  span {
    color: $muted;
    font-size: 11px;
    font-weight: 500;
  }

  &--accent {
    border-color: rgba($accent, 0.35);
    background: rgba($accent, 0.08);

    strong {
      color: $accent-dark;
    }
  }
}

.admin__tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
}

.admin__tab {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 8px 14px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface;
  color: $muted;
  font: inherit;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  @include interactive((background, color, border-color, box-shadow));

  &:hover {
    color: $ink;
    border-color: rgba($primary, 0.4);
  }

  &.on {
    color: #fff;
    border-color: transparent;
    background: linear-gradient(135deg, $primary, $primary-dark);
    box-shadow: 0 6px 14px rgba($primary, 0.26);
  }
}

.admin__tab-icon {
  font-size: 15px;
  line-height: 1;
}

.admin__tab-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: $radius-pill;
  background: $accent;
  color: #fff;
  font-size: 11px;
  font-weight: 700;

  .admin__tab.on & {
    background: rgba(255, 255, 255, 0.28);
  }
}

.admin__desc {
  margin: 0 0 14px;
  padding: 10px 14px;
  border-left: 3px solid rgba($primary, 0.5);
  border-radius: 0 $radius-sm $radius-sm 0;
  background: $primary-soft;
  color: $primary-dark;
  font-size: 13px;
  line-height: 1.5;
}

.admin__subtabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 14px;
}

.admin__chip {
  padding: 6px 13px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface;
  color: $muted;
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  @include interactive((background, color, border-color));

  &:hover {
    color: $ink;
  }

  &.on {
    color: $primary-dark;
    border-color: rgba($primary, 0.5);
    background: $primary-soft;
  }
}

.admin__bar {
  margin-bottom: 12px;
}

.admin__box {
  border: 1px solid $line;
  background: $surface;
}

.admin__count {
  margin: 0;
  padding: 8px 12px;
  border-bottom: 1px solid $line;
  color: $muted;
  font-size: 12px;
}

.admin__table {
  width: 100%;
  border-collapse: collapse;

  th,
  td {
    padding: 8px 12px;
    border-bottom: 1px solid $line;
    text-align: left;
    vertical-align: top;
  }

  th {
    color: $muted;
    font-size: 12px;
    font-weight: 500;
  }

  tr:last-child td {
    border-bottom: 0;
  }
}

.admin__thumb img {
  display: block;
  object-fit: cover;
  background: $surface-soft;
}

// Status colours: published / pending / needs-location-review (third colour)
.admin__photo-row td:first-child {
  border-left: 3px solid transparent;
}

.admin__photo-row--published td:first-child {
  border-left-color: $success;
}

.admin__photo-row--pending td:first-child {
  border-left-color: $warning;
}

.admin__photo-row--review td:first-child {
  border-left-color: $review-color;
}

.admin__photo-row--review {
  background: rgba($review-color, 0.06);
}

.admin__review-badge {
  display: inline-block;
  margin-top: 6px;
  padding: 2px 9px;
  border-radius: $radius-pill;
  color: #fff;
  background: linear-gradient(135deg, $review-color, $review-color-dark);
  font-size: 11px;
  font-weight: 600;
}

.admin__actions {
  font-size: 13px;
}

.admin__act-group {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.admin__act {
  display: inline-flex;
  align-items: center;
  padding: 5px 11px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface;
  color: $ink;
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;
  cursor: pointer;
  @include interactive((background, color, border-color));

  &:hover {
    border-color: rgba($primary, 0.45);
    background: $primary-soft;
  }

  &--ok {
    color: $success;
    border-color: rgba($success, 0.4);

    &:hover {
      background: rgba($success, 0.1);
    }
  }

  &--review {
    color: $review-color-dark;
    border-color: rgba($review-color, 0.45);

    &:hover {
      background: rgba($review-color, 0.1);
    }
  }

  &--danger {
    color: #b3261e;
    border-color: rgba(#b3261e, 0.35);

    &:hover {
      background: rgba(#b3261e, 0.08);
    }
  }

  &:disabled {
    opacity: 0.45;
    cursor: not-allowed;
  }
}

.admin__badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: $radius-pill;
  font-size: 11px;
  font-weight: 600;

  &--ok {
    color: $success;
    background: rgba($success, 0.12);
  }

  &--wait {
    color: $accent-dark;
    background: rgba($accent, 0.14);
  }
}

.admin__muted {
  margin-top: 2px;
  color: $muted;
  font-size: 12px;
}

.admin__cell--unread .admin__link--title {
  font-weight: 600;
}

.admin__feedback-preview {
  margin: 8px 0 0;
  color: $muted;
  font-size: 13px;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.admin__feedback-body {
  margin: 10px 0 0;
  white-space: pre-wrap;
  line-height: 1.55;
}

.admin__link {
  padding: 0;
  border: 0;
  background: none;
  color: $ink;
  font: inherit;
  font-size: inherit;
  text-decoration: underline;
  cursor: pointer;

  &--title {
    font-weight: 600;
  }

  &--danger {
    color: #a00;
  }

  &--review {
    color: $review-color-dark;
    font-weight: 600;
  }

  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
}

a.admin__link {
  display: inline;
}

.admin__btn {
  padding: 6px 12px;
  border: 1px solid $line;
  background: $surface;
  color: $ink;
  font: inherit;
  font-size: 13px;
  cursor: pointer;

  &--plain {
    border-color: transparent;
    background: transparent;
  }

  &--danger {
    color: #a00;
    border-color: #dcc;
  }

  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
}

.admin__input,
.admin__select {
  max-width: 100%;
  padding: 6px 8px;
  border: 1px solid $line;
  background: $surface;
  color: $ink;
  font: inherit;
  font-size: 13px;
}

.admin__textarea {
  width: 100%;
  font-family: ui-monospace, monospace;
  resize: vertical;
}

.admin__inline {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
}

.admin__user-detail {
  margin-top: 10px;
  padding: 10px 12px;
  border: 1px solid $line;
  background: $surface-soft;
  font-size: 13px;

  p {
    margin: 0 0 6px;

    &:last-child {
      margin-bottom: 0;
    }
  }
}

.admin__empty {
  margin: 0;
  padding: 24px 12px;
  text-align: center;
  color: $muted;

  &--end {
    padding: 12px;
    font-size: 12px;
  }
}

.admin__more {
  margin: 0;
  padding: 12px;
  text-align: center;
  border-top: 1px solid $line;
}

.admin__sentinel {
  display: block;
  height: 1px;
}

.admin__msg {
  margin: 0 0 12px;
  padding: 8px 12px;
  font-size: 13px;

  &--err {
    border: 1px solid #e0c0c0;
    background: #fff5f5;
    color: #800;
  }
}

.admin__form {
  display: grid;
  gap: 12px;
  margin-bottom: 16px;
  padding: 16px;
  border: 1px solid $line;

  h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
  }
}

.admin__field {
  display: grid;
  gap: 4px;

  span {
    font-size: 12px;
    color: $muted;
  }

  &--row {
    grid-template-columns: auto 1fr;
    align-items: center;
    width: fit-content;
  }
}

.admin__form-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>

<script setup>
import { computed, inject, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import PhotoCommentThread from '../components/PhotoCommentThread.vue'
import { api, clearApiCacheForPath, getToken, localizedApi } from '../api'
import { useI18n } from '../i18n'
import { useLanguageReload, useLocalizedReady } from '../composables/useLanguageReload'
import { formatDate } from '../utils/locale'
import { isAdminUser } from '../utils/user'
import { setPageMeta } from '../utils/seo'

const route = useRoute()
const { t, currentLanguage } = useI18n()
const currentUser = inject('currentUser', ref(null))
const item = ref(null)
const comments = ref([])
const comment = ref('')
const error = ref('')
const commentError = ref('')

const isAuthenticated = computed(() => Boolean(currentUser.value || getToken()))
const isAdmin = computed(() => isAdminUser(currentUser.value))
const currentUserUnique = computed(() => currentUser.value?.unique || '')

function plainText(html) {
  if (!html) return ''
  const node = document.createElement('div')
  node.innerHTML = html
  return (node.textContent || '').trim()
}

function normalizeComments(rows) {
  return (rows || []).map((entry) => ({
    ...entry,
    source: entry.source || 'site',
    replies: entry.replies || [],
  }))
}

async function load({ soft = false } = {}) {
  if (!soft) {
    item.value = null
  }
  const newsPath = `/news/${route.params.id}`
  const commentsPath = `/news/${route.params.id}/comments`
  item.value = await localizedApi(newsPath)
  comments.value = normalizeComments(await localizedApi(commentsPath))
  setPageMeta({
    title: item.value.title,
    description: plainText(item.value.content).slice(0, 160) || item.value.title,
    path: route.fullPath,
    type: 'article',
  })
}

async function applyLocalized({ path }) {
  const newsPath = `/news/${route.params.id}`
  const commentsPath = `/news/${route.params.id}/comments`
  if (path === newsPath) {
    item.value = await localizedApi(newsPath)
    setPageMeta({
      title: item.value.title,
      description: plainText(item.value.content).slice(0, 160) || item.value.title,
      path: route.fullPath,
      type: 'article',
    })
  }
  if (path === commentsPath) {
    comments.value = normalizeComments(await localizedApi(commentsPath))
  }
}

async function submitComment() {
  error.value = ''
  try {
    await api(`/news/${route.params.id}/comments`, {
      method: 'POST',
      body: { body: comment.value },
    })
    comment.value = ''
    clearApiCacheForPath(`/news/${route.params.id}/comments`)
    await load()
  } catch (event) {
    error.value = event.message
  }
}

async function deleteComment(entry) {
  if (!entry || typeof entry.id !== 'number') return

  const snapshot = comments.value
  comments.value = comments.value.filter((row) => row.id !== entry.id)
  commentError.value = ''

  try {
    await api(`/comments/${entry.id}`, { method: 'DELETE' })
    clearApiCacheForPath(`/news/${route.params.id}/comments`)
  } catch (event) {
    comments.value = snapshot
    commentError.value = event.message
  }
}

onMounted(() => load())
watch(() => route.params.id, () => load())
useLanguageReload(() => load({ soft: true }))
useLocalizedReady(applyLocalized)
</script>

<template>
  <article v-if="item" class="panel content-card news-detail-card">
    <p class="eyebrow">{{ formatDate(item.date, currentLanguage) }}</p>
    <h1>{{ item.title }}</h1>
    <div v-html="item.content"></div>
  </article>

  <section v-if="item" class="panel">
    <h2>{{ t('comments') }}</h2>
    <form class="comment-form" @submit.prevent="submitComment">
      <textarea v-model="comment" :placeholder="t('writeComment')" required />
      <button class="button" type="submit">{{ t('postComment') }}</button>
      <p v-if="error" class="error">{{ error }}</p>
    </form>
    <PhotoCommentThread
      :threads="comments"
      :t="t"
      :lang="currentLanguage"
      :is-authenticated="isAuthenticated"
      :is-admin="isAdmin"
      :allow-reply="false"
      :current-user-unique="currentUserUnique"
      @delete="deleteComment"
    />
    <p v-if="commentError" class="error">{{ commentError }}</p>
  </section>
</template>

<style lang="scss">
.news-detail-card {
  display: block;
  width: fit-content;
  max-width: min(920px, 100%);
  padding: 28px;
  line-height: 1.65;
}
</style>

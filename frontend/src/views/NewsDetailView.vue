<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api'
import { useI18n } from '../i18n'
import { formatDate } from '../utils/locale'
import { userDisplayName, userProfilePath } from '../utils/user'
import { setPageMeta } from '../utils/seo'

const route = useRoute()
const { t, currentLanguage } = useI18n()
const item = ref(null)
const comments = ref([])
const comment = ref('')
const error = ref('')

function plainText(html) {
  if (!html) return ''
  const node = document.createElement('div')
  node.innerHTML = html
  return (node.textContent || '').trim()
}

async function load() {
  item.value = await api(`/news/${route.params.id}`)
  comments.value = await api(`/news/${route.params.id}/comments`)
  setPageMeta({
    title: item.value.title,
    description: plainText(item.value.content).slice(0, 160) || item.value.title,
    path: route.fullPath,
    type: 'article',
  })
}

async function submitComment() {
  error.value = ''
  try {
    await api(`/news/${route.params.id}/comments`, {
      method: 'POST',
      body: { body: comment.value },
    })
    comment.value = ''
    await load()
  } catch (event) {
    error.value = event.message
  }
}

onMounted(load)
watch(currentLanguage, load)
watch(() => route.params.id, load)
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
    <div v-for="entry in comments" :key="entry.id" class="comment">
      <span class="comment-avatar placeholder-avatar">{{ userDisplayName(entry.author, t).slice(0, 1) }}</span>
      <span>
        <RouterLink class="comment-author" :to="userProfilePath(entry.author)">
          {{ userDisplayName(entry.author, t) }}
        </RouterLink>
        <p>{{ entry.body }}</p>
      </span>
    </div>
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

.placeholder-avatar {
  display: grid;
  place-items: center;
  color: #fff;
  background: linear-gradient(135deg, $primary, $accent);
  font-weight: 600;
}
</style>

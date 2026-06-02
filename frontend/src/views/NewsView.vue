<script setup>
import { onMounted, ref, watch } from 'vue'
import { localizedApi } from '../api'
import { useI18n } from '../i18n'
import { useLanguageReload, useLocalizedReady } from '../composables/useLanguageReload'
import { formatDate } from '../utils/locale'

const { t, currentLanguage } = useI18n()
const news = ref([])
const loading = ref(true)
const error = ref('')

async function load({ soft = false } = {}) {
  if (!soft) {
    loading.value = true
  }
  error.value = ''
  try {
    const payload = await localizedApi('/news?per_page=50')
    news.value = payload?.data ?? []
  } catch (event) {
    if (!soft) {
      news.value = []
    }
    error.value = event.message
  } finally {
    loading.value = false
  }
}

onMounted(() => load())
useLanguageReload(() => load({ soft: true }))
useLocalizedReady(async ({ path }) => {
  if (path === '/news?per_page=50') {
    const payload = await localizedApi(path)
    news.value = payload?.data ?? []
  }
})
</script>

<template>
  <section class="page-head">
    <p class="eyebrow">{{ t('news') }}</p>
    <h1>{{ t('projectUpdates') }}</h1>
  </section>
  <p v-if="loading" class="panel">{{ t('loading') }}</p>
  <p v-else-if="error" class="panel error">{{ error }}</p>

  <section v-else class="news-list">
    <p v-if="!news.length" class="panel">{{ t('adminEmpty') }}</p>
    <RouterLink v-for="item in news" :key="item.id" class="panel content-card news-card" :to="`/news/${item.id}`">
      <p class="eyebrow">{{ formatDate(item.date, currentLanguage) }}</p>
      <h2>{{ item.title }}</h2>
      <div class="news-preview" v-html="item.content"></div>
    </RouterLink>
  </section>
</template>

<style lang="scss">
.news-list {
  display: grid;
  gap: 14px;
  max-width: 960px;
}

.news-card {
  display: block;
  overflow: hidden;
  text-decoration: none;
  color: inherit;
  @include hover-lift(-2px, 0 18px 36px rgba($primary, 0.18));

  h2 {
    margin-bottom: 8px;
    @include interactive((color));
  }

  &:hover h2 {
    color: $primary;
  }

  &:focus-visible {
    outline: 2px solid rgba($primary, 0.45);
    outline-offset: 3px;
  }
}

.news-preview {
  @include clamp-lines(3);
  color: $muted;

  * {
    margin: 0;
    color: inherit !important;
    font: inherit !important;
    background: transparent !important;
  }
}
</style>

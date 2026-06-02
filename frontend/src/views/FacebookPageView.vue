<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { api } from '../api'
import { useI18n } from '../i18n'
import { currentLanguage } from '../i18n'
import { loadFacebookSdk, parseFacebookXfbml } from '../utils/facebookSdk'
import { setPageMeta } from '../utils/seo'

const { t } = useI18n()
const stats = ref(null)
const plugin = ref(null)
const loading = ref(true)

const fbLocale = computed(() => {
  const lang = currentLanguage.value
  if (lang === 'hy') return 'hy_AM'
  if (lang === 'en') return 'en_US'
  return 'ru_RU'
})

const followUrl = computed(() => stats.value?.page_url || 'https://www.facebook.com/HinYerevanCom/')

onMounted(async () => {
  try {
    const [pageStats, config] = await Promise.all([
      api('/facebook/page'),
      api('/facebook/plugin-config'),
    ])
    stats.value = pageStats
    setPageMeta({
      title: t('facebookPage'),
      description: t('facebookPageIntro'),
      path: '/facebook',
    })

    if (config?.app_id && config?.page_url) {
      await loadFacebookSdk(config.app_id, fbLocale.value)
      await nextTick()
      parseFacebookXfbml()
    }
  } catch {
    stats.value = { page_url: 'https://www.facebook.com/HinYerevanCom/', configured: false }
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <article class="panel facebook-page">
    <header class="facebook-page__head">
      <p class="eyebrow">Facebook</p>
      <h1>{{ stats?.name || 'HinYerevan' }}</h1>
      <p v-if="stats?.followers_count" class="facebook-page__stats">
        {{ t('facebookFollowers') }}: <strong>{{ stats.followers_count.toLocaleString() }}</strong>
      </p>
      <p class="facebook-page__intro">{{ t('facebookPageIntro') }}</p>
      <a
        class="button facebook-page__follow"
        :href="followUrl"
        target="_blank"
        rel="noopener noreferrer"
      >
        {{ t('facebookFollow') }}
      </a>
    </header>

    <div v-if="loading" class="facebook-page__loading">{{ t('loading') }}</div>

    <div v-else ref="plugin" class="facebook-page__plugin">
      <div
        class="fb-page"
        :data-href="followUrl"
        data-tabs="timeline"
        data-width="500"
        data-height="620"
        data-small-header="false"
        data-adapt-container-width="true"
        data-hide-cover="false"
        data-show-facepile="true"
      />
    </div>

    <p class="facebook-page__hint muted-hint">{{ t('facebookEmbedHint') }}</p>
  </article>
</template>

<style lang="scss">
.facebook-page {
  max-width: 720px;
  margin: 0 auto;
  padding: clamp(24px, 4vw, 40px);
}

.facebook-page__head {
  display: grid;
  gap: 10px;
  margin-bottom: 20px;

  h1 {
    margin: 0;
  }
}

.facebook-page__stats {
  margin: 0;
  color: $primary;
  font-weight: 500;
}

.facebook-page__intro {
  margin: 0;
  color: $muted;
  line-height: 1.6;
}

.facebook-page__follow {
  justify-self: start;
  margin-top: 4px;
}

.facebook-page__plugin {
  min-height: 300px;
  overflow: hidden;
  border-radius: $radius-lg;
  background: $surface-soft;
}

.facebook-page__loading {
  padding: 40px 0;
  text-align: center;
  color: $muted;
}

.facebook-page__hint {
  margin: 14px 0 0;
  font-size: 13px;
}
</style>

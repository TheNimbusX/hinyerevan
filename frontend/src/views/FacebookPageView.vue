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
const pluginReady = ref(false)
const pluginFailed = ref(false)
const embedHref = ref('')

const fbLocale = computed(() => {
  const lang = currentLanguage.value
  if (lang === 'hy') return 'hy_AM'
  if (lang === 'en') return 'en_US'
  return 'ru_RU'
})

const followUrl = computed(() => embedHref.value || stats.value?.page_url || 'https://www.facebook.com/HinYerevanCom/')

function schedulePluginCheck() {
  window.setTimeout(() => {
    const root = plugin.value
    const iframe = root?.querySelector('iframe')
    if (!iframe) {
      pluginFailed.value = true
    }
  }, 4500)
}

onMounted(async () => {
  try {
    const [pageStats, config] = await Promise.all([
      api('/facebook/page'),
      api('/facebook/plugin-config'),
    ])
    stats.value = pageStats
    embedHref.value = config?.page_url || pageStats?.page_url || ''
    setPageMeta({
      title: t('facebookPage'),
      description: t('facebookPageIntro'),
      path: '/facebook',
    })

    if (config?.app_id && embedHref.value) {
      const ok = await loadFacebookSdk(config.app_id, fbLocale.value)
      if (ok) {
        pluginReady.value = true
        await nextTick()
        parseFacebookXfbml(plugin.value)
        schedulePluginCheck()
      } else {
        pluginFailed.value = true
      }
    } else {
      pluginFailed.value = true
    }
  } catch {
    stats.value = { page_url: 'https://www.facebook.com/HinYerevanCom/', configured: false }
    pluginFailed.value = true
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
      <p v-if="stats?.followers_count != null" class="facebook-page__stats">
        {{ t('facebookFollowers') }}:
        <strong>{{ (stats.followers_count || stats.fan_count || 0).toLocaleString() }}</strong>
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
        v-if="pluginReady && !pluginFailed"
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
      <div v-else class="facebook-page__fallback">
        <p>{{ t('facebookEmbedUnavailable') }}</p>
        <a class="button" :href="followUrl" target="_blank" rel="noopener noreferrer">
          {{ t('facebookOpenPage') }}
        </a>
      </div>
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

.facebook-page__fallback {
  display: grid;
  gap: 16px;
  place-items: center;
  padding: 48px 24px;
  text-align: center;
  color: $muted;

  p {
    margin: 0;
    max-width: 36ch;
    line-height: 1.5;
  }
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

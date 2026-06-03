<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { api } from '../api'
import { useI18n } from '../i18n'
import { currentLanguage } from '../i18n'
import { loadFacebookSdk, parseFacebookXfbml } from '../utils/facebookSdk'

const props = defineProps({
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])

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

let pluginCheckTimer = null

function schedulePluginCheck() {
  clearTimeout(pluginCheckTimer)
  pluginCheckTimer = window.setTimeout(() => {
    const iframe = plugin.value?.querySelector('iframe')
    if (!iframe) pluginFailed.value = true
  }, 4500)
}

async function loadPanel() {
  loading.value = true
  pluginReady.value = false
  pluginFailed.value = false
  try {
    const [pageStats, config] = await Promise.all([
      api('/facebook/page'),
      api('/facebook/plugin-config'),
    ])
    stats.value = pageStats
    embedHref.value = config?.page_url || pageStats?.page_url || ''

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
}

function onKeydown(event) {
  if (event.key === 'Escape') emit('close')
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      loadPanel()
      window.addEventListener('keydown', onKeydown)
    } else {
      window.removeEventListener('keydown', onKeydown)
      clearTimeout(pluginCheckTimer)
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  clearTimeout(pluginCheckTimer)
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="auth-modal-backdrop facebook-modal-backdrop" @click.self="emit('close')">
      <section class="auth-modal facebook-modal" role="dialog" aria-modal="true" :aria-label="t('facebookPage')">
        <button class="auth-close" type="button" :aria-label="t('cancel')" @click="emit('close')" />

        <header class="facebook-modal__head">
          <p class="eyebrow">Facebook</p>
          <h2>{{ stats?.name || 'HinYerevan' }}</h2>
          <p v-if="stats?.followers_count != null" class="facebook-modal__stats">
            {{ t('facebookFollowers') }}:
            <strong>{{ (stats.followers_count || stats.fan_count || 0).toLocaleString() }}</strong>
          </p>
          <p class="facebook-modal__intro">{{ t('facebookPageIntro') }}</p>
          <a
            class="button facebook-modal__follow"
            :href="followUrl"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ t('facebookFollow') }}
          </a>
        </header>

        <div v-if="loading" class="facebook-modal__loading">{{ t('loading') }}</div>

        <div v-else ref="plugin" class="facebook-modal__plugin">
          <div
            v-if="pluginReady && !pluginFailed"
            class="fb-page"
            :data-href="followUrl"
            data-tabs="timeline"
            data-width="500"
            data-height="480"
            data-small-header="false"
            data-adapt-container-width="true"
            data-hide-cover="false"
            data-show-facepile="true"
          />
          <div v-else class="facebook-modal__fallback">
            <p>{{ t('facebookEmbedUnavailable') }}</p>
            <a class="button" :href="followUrl" target="_blank" rel="noopener noreferrer">
              {{ t('facebookOpenPage') }}
            </a>
          </div>
        </div>
      </section>
    </div>
  </Teleport>
</template>

<style lang="scss">
.facebook-modal-backdrop {
  z-index: 920;
}

.facebook-modal {
  display: flex;
  flex-direction: column;
  width: min(560px, calc(100vw - 28px));
  max-height: calc(100vh - 40px);
  padding: 0;
  overflow: hidden;
}

.facebook-modal__head {
  display: grid;
  gap: 8px;
  padding: 22px 22px 12px;

  h2 {
    margin: 0;
    font-size: clamp(22px, 4vw, 28px);
  }
}

.facebook-modal__stats {
  margin: 0;
  color: $primary;
}

.facebook-modal__intro {
  margin: 0;
  color: $muted;
  line-height: 1.55;
  font-size: 14px;
}

.facebook-modal__follow {
  justify-self: start;
  margin-top: 4px;
}

.facebook-modal__plugin {
  flex: 1;
  min-height: 280px;
  margin: 0 16px 20px;
  overflow: hidden;
  border-radius: $radius-lg;
  background: $surface-soft;
}

.facebook-modal__fallback {
  display: grid;
  gap: 14px;
  place-items: center;
  padding: 40px 20px;
  text-align: center;
  color: $muted;

  p {
    margin: 0;
    max-width: 32ch;
    line-height: 1.5;
  }
}

.facebook-modal__loading {
  padding: 32px;
  text-align: center;
  color: $muted;
}
</style>

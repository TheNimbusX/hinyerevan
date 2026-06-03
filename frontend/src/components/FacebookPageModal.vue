<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { api } from '../api'
import { useI18n } from '../i18n'
import { currentLanguage } from '../i18n'
import { loadFacebookSdk, parseFacebookXfbml } from '../utils/facebookSdk'

const PLUGIN_HEIGHT = 480

const props = defineProps({
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])

const { t } = useI18n()
const stats = ref(null)
const plugin = ref(null)
const apiLoading = ref(false)
const embedLoading = ref(false)
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

const showSkeleton = computed(
  () => apiLoading.value || (embedLoading.value && pluginReady.value && !pluginFailed.value),
)

const showEmbed = computed(() => !apiLoading.value && (pluginReady.value || pluginFailed.value))

let pluginCheckTimer = null
let embedWatchTimer = null

function clearEmbedWatch() {
  clearTimeout(pluginCheckTimer)
  clearInterval(embedWatchTimer)
  embedWatchTimer = null
  pluginCheckTimer = null
}

function finishEmbedLoading() {
  embedLoading.value = false
  clearEmbedWatch()
}

function waitForPluginIframe(root, timeoutMs = 8000) {
  clearEmbedWatch()

  return new Promise((resolve) => {
    if (!root) {
      resolve(false)
      return
    }

    const hasIframe = () => Boolean(root.querySelector('iframe'))

    if (hasIframe()) {
      resolve(true)
      return
    }

    let settled = false
    const done = (ok) => {
      if (settled) return
      settled = true
      observer.disconnect()
      clearInterval(embedWatchTimer)
      clearTimeout(pluginCheckTimer)
      embedWatchTimer = null
      pluginCheckTimer = null
      resolve(ok)
    }

    const observer = new MutationObserver(() => {
      if (hasIframe()) done(true)
    })
    observer.observe(root, { childList: true, subtree: true })

    embedWatchTimer = window.setInterval(() => {
      if (hasIframe()) done(true)
    }, 150)

    pluginCheckTimer = window.setTimeout(() => done(hasIframe()), timeoutMs)
  })
}

async function loadPanel() {
  apiLoading.value = true
  embedLoading.value = false
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
        embedLoading.value = true
        await nextTick()
        parseFacebookXfbml(plugin.value)
        const hasIframe = await waitForPluginIframe(plugin.value)
        if (!hasIframe) pluginFailed.value = true
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
    apiLoading.value = false
    finishEmbedLoading()
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
      clearEmbedWatch()
      apiLoading.value = false
      embedLoading.value = false
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  clearEmbedWatch()
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="auth-modal-backdrop facebook-modal-backdrop" @click.self="emit('close')">
      <section class="auth-modal facebook-modal" role="dialog" aria-modal="true" :aria-label="t('facebookPage')">
        <button class="auth-close" type="button" :aria-label="t('cancel')" @click="emit('close')" />

        <header class="facebook-modal__head">
          <p class="eyebrow">Facebook</p>
          <h2>
            <span v-if="apiLoading" class="facebook-modal__sk facebook-modal__sk--title" aria-hidden="true" />
            <template v-else>{{ stats?.name || 'HinYerevan' }}</template>
          </h2>
          <p class="facebook-modal__stats">
            <span v-if="apiLoading" class="facebook-modal__sk facebook-modal__sk--stat" aria-hidden="true" />
            <template v-else-if="stats?.followers_count != null">
              {{ t('facebookFollowers') }}:
              <strong>{{ (stats.followers_count || stats.fan_count || 0).toLocaleString() }}</strong>
            </template>
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

        <div
          class="facebook-modal__embed"
          :style="{ '--fb-plugin-height': `${PLUGIN_HEIGHT}px` }"
          :aria-busy="showSkeleton"
        >
          <div class="facebook-modal__skeleton" :class="{ 'is-hidden': !showSkeleton }" aria-hidden="true">
            <div class="facebook-modal__skeleton-cover" />
            <div class="facebook-modal__skeleton-body">
              <span class="facebook-modal__skeleton-line facebook-modal__skeleton-line--lg" />
              <span class="facebook-modal__skeleton-line" />
              <span class="facebook-modal__skeleton-line" />
              <span class="facebook-modal__skeleton-line facebook-modal__skeleton-line--sm" />
            </div>
          </div>

          <div ref="plugin" class="facebook-modal__plugin" :class="{ 'is-visible': showEmbed && !showSkeleton }">
            <div
              v-if="pluginReady && !pluginFailed"
              class="fb-page"
              :data-href="followUrl"
              data-tabs="timeline"
              data-width="500"
              :data-height="String(PLUGIN_HEIGHT)"
              data-small-header="false"
              data-adapt-container-width="true"
              data-hide-cover="false"
              data-show-facepile="true"
            />
            <div v-else-if="showEmbed" class="facebook-modal__fallback">
              <p>{{ t('facebookEmbedUnavailable') }}</p>
              <a class="button" :href="followUrl" target="_blank" rel="noopener noreferrer">
                {{ t('facebookOpenPage') }}
              </a>
            </div>
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
  flex-shrink: 0;
  padding: 22px 22px 12px;
  min-height: 168px;

  h2 {
    margin: 0;
    min-height: 34px;
    font-size: clamp(22px, 4vw, 28px);
  }
}

.facebook-modal__stats {
  margin: 0;
  min-height: 22px;
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

.facebook-modal__sk {
  display: block;
  border-radius: $radius-pill;
  background:
    linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.65), transparent),
    rgba(0, 0, 0, 0.08);
  background-size: 220px 100%, 100% 100%;
  animation: skeleton-shimmer 1.1s infinite linear;
}

.facebook-modal__sk--title {
  width: min(240px, 70%);
  height: 28px;
}

.facebook-modal__sk--stat {
  width: 140px;
  height: 18px;
}

.facebook-modal__embed {
  position: relative;
  flex: 0 0 auto;
  height: var(--fb-plugin-height, 480px);
  margin: 0 16px 20px;
  overflow: hidden;
  border-radius: $radius-lg;
  background: $surface-soft;
}

.facebook-modal__skeleton {
  position: absolute;
  inset: 0;
  z-index: 2;
  display: grid;
  grid-template-rows: 140px 1fr;
  gap: 12px;
  padding: 14px;
  opacity: 1;
  transition: opacity 0.4s ease;
  pointer-events: none;

  &.is-hidden {
    opacity: 0;
  }
}

.facebook-modal__skeleton-cover {
  border-radius: $radius-md;
  background:
    linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.55), transparent),
    rgba(0, 0, 0, 0.06);
  background-size: 220px 100%, 100% 100%;
  animation: skeleton-shimmer 1.1s infinite linear;
}

.facebook-modal__skeleton-body {
  display: grid;
  gap: 10px;
  align-content: start;
  padding: 4px 2px 0;
}

.facebook-modal__skeleton-line {
  display: block;
  height: 14px;
  border-radius: $radius-pill;
  background:
    linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.55), transparent),
    rgba(0, 0, 0, 0.06);
  background-size: 220px 100%, 100% 100%;
  animation: skeleton-shimmer 1.1s infinite linear;

  &--lg {
    width: 88%;
    height: 18px;
  }

  &--sm {
    width: 42%;
  }

  &:not(&--lg):not(&--sm) {
    width: 72%;
  }
}

.facebook-modal__plugin {
  position: absolute;
  inset: 0;
  z-index: 1;
  opacity: 0;
  transition: opacity 0.45s ease 0.08s;
  overflow: hidden;

  &.is-visible {
    opacity: 1;
  }

  :deep(iframe) {
    display: block;
    max-width: 100%;
  }
}

.facebook-modal__fallback {
  display: grid;
  gap: 14px;
  place-items: center;
  height: 100%;
  padding: 40px 20px;
  text-align: center;
  color: $muted;

  p {
    margin: 0;
    max-width: 32ch;
    line-height: 1.5;
  }
}

[data-theme='dark'] {
  .facebook-modal__sk,
  .facebook-modal__skeleton-cover,
  .facebook-modal__skeleton-line {
    background:
      linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.12), transparent),
      rgba(255, 255, 255, 0.08);
    background-size: 220px 100%, 100% 100%;
  }
}
</style>

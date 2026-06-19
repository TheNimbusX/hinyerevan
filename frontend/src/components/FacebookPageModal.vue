<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { api } from '../api'
import { useTheme } from '../composables/useTheme'
import { useI18n } from '../i18n'
import { currentLanguage } from '../i18n'
import { isInAppBrowser } from '../utils/overlayCleanup'
import { loadFacebookSdk, parseFacebookXfbml } from '../utils/facebookSdk'

const PLUGIN_HEIGHT = 520
const MOBILE_BP = 767

const props = defineProps({
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])

const { t } = useI18n()
const { theme } = useTheme()
const stats = ref(null)
const plugin = ref(null)
const embedBox = ref(null)
const apiLoading = ref(false)
const embedLoading = ref(false)
const pluginReady = ref(false)
const pluginFailed = ref(false)
const embedHref = ref('')
const embedGeneration = ref(0)
const configAppId = ref('')
const pluginWidth = ref(500)
const useIframeEmbed = ref(false)
const useMobileFeed = ref(false)

const isMobileLayout = computed(() => {
  if (typeof window === 'undefined') return false
  return window.matchMedia(`(max-width: ${MOBILE_BP}px)`).matches
})

const mobilePosts = computed(() => stats.value?.recent_posts || [])

const embedHeight = computed(() => (useMobileFeed.value ? 'auto' : `${PLUGIN_HEIGHT}px`))

const fbLocale = computed(() => {
  const lang = currentLanguage.value
  if (lang === 'hy') return 'hy_AM'
  if (lang === 'en') return 'en_US'
  return 'ru_RU'
})

const followUrl = computed(() => embedHref.value || stats.value?.page_url || 'https://www.facebook.com/HinYerevanCom/')

const mobilePageUrl = computed(() => followUrl.value.replace('://www.facebook.com', '://m.facebook.com'))

const fbColorScheme = computed(() => (theme.value === 'dark' ? 'dark' : 'light'))

// Skeleton only while API loads — hiding the plugin (opacity 0) breaks XFBML on mobile WebKit.
const showSkeleton = computed(() => apiLoading.value)

const showEmbed = computed(() => !apiLoading.value && (pluginReady.value || pluginFailed.value))

const iframeSrc = computed(() => {
  if (!followUrl.value) return ''
  const params = new URLSearchParams({
    href: followUrl.value,
    tabs: 'timeline',
    width: String(pluginWidth.value),
    height: String(PLUGIN_HEIGHT),
    small_header: 'false',
    adapt_container_width: 'true',
    hide_cover: 'false',
    show_facepile: 'true',
  })
  if (configAppId.value) params.set('appId', configAppId.value)
  return `https://www.facebook.com/plugins/page.php?${params.toString()}`
})

let pluginCheckTimer = null
let embedWatchTimer = null
let resizeObserver = null

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

function prefersMobileFeed() {
  return isMobileLayout.value
}

function prefersIframeEmbed() {
  if (typeof window === 'undefined') return false
  return isInAppBrowser()
}

function formatPostDate(iso) {
  if (!iso) return ''
  try {
    const lang = currentLanguage.value === 'hy' ? 'hy-AM' : currentLanguage.value === 'en' ? 'en-US' : 'ru-RU'
    return new Date(iso).toLocaleDateString(lang, { day: 'numeric', month: 'short', year: 'numeric' })
  } catch {
    return ''
  }
}

function postExcerpt(text, max = 160) {
  const clean = (text || '').replace(/\s+/g, ' ').trim()
  if (clean.length <= max) return clean
  return `${clean.slice(0, max).trim()}…`
}

function measurePluginWidth() {
  const el = embedBox.value || plugin.value?.parentElement
  if (!el) return 500
  const w = Math.floor(el.clientWidth) || 500
  return Math.max(280, Math.min(500, w))
}

function bindResizeObserver() {
  resizeObserver?.disconnect()
  const el = embedBox.value
  if (!el || typeof ResizeObserver === 'undefined') return
  resizeObserver = new ResizeObserver(() => {
    pluginWidth.value = measurePluginWidth()
  })
  resizeObserver.observe(el)
}

function waitForPluginIframe(root, timeoutMs = 12000) {
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

async function mountEmbed() {
  embedGeneration.value += 1
  const generation = embedGeneration.value
  pluginReady.value = false
  pluginFailed.value = false
  embedLoading.value = false
  useIframeEmbed.value = false
  useMobileFeed.value = false

  if (!embedHref.value) {
    pluginFailed.value = true
    return
  }

  try {
    const [pageStats, config] = await Promise.all([
      api('/facebook/page'),
      api('/facebook/plugin-config'),
    ])
    if (generation !== embedGeneration.value) return

    stats.value = pageStats
    embedHref.value = config?.page_url || pageStats?.page_url || embedHref.value
    configAppId.value = config?.app_id || ''

    if (!config?.app_id) {
      pluginFailed.value = true
      return
    }

    await nextTick()
    if (generation !== embedGeneration.value) return

    pluginWidth.value = measurePluginWidth()
    bindResizeObserver()

    if (prefersMobileFeed()) {
      useMobileFeed.value = true
      pluginReady.value = true
      return
    }

    if (prefersIframeEmbed()) {
      useIframeEmbed.value = true
      pluginReady.value = true
      return
    }

    const ok = await loadFacebookSdk(config.app_id, fbLocale.value)
    if (generation !== embedGeneration.value) return

    if (!ok) {
      useIframeEmbed.value = true
      pluginReady.value = true
      return
    }

    pluginReady.value = true
    embedLoading.value = true
    await nextTick()
    if (generation !== embedGeneration.value) return

    pluginWidth.value = measurePluginWidth()
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))
    if (generation !== embedGeneration.value) return

    parseFacebookXfbml(plugin.value)
    const hasIframe = await waitForPluginIframe(plugin.value, 15000)
    if (generation !== embedGeneration.value) return

    if (!hasIframe) {
      useIframeEmbed.value = true
    }
  } catch {
    if (generation === embedGeneration.value) {
      useIframeEmbed.value = true
      pluginReady.value = true
    }
  } finally {
    if (generation === embedGeneration.value) {
      finishEmbedLoading()
    }
  }
}

async function loadPanel() {
  apiLoading.value = true
  try {
    const [pageStats, config] = await Promise.all([
      api('/facebook/page'),
      api('/facebook/plugin-config'),
    ])
    stats.value = pageStats
    embedHref.value = config?.page_url || pageStats?.page_url || ''
  } catch {
    stats.value = { page_url: 'https://www.facebook.com/HinYerevanCom/', configured: false }
    embedHref.value = 'https://www.facebook.com/HinYerevanCom/'
  } finally {
    apiLoading.value = false
  }

  await mountEmbed()
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
      resizeObserver?.disconnect()
      resizeObserver = null
      apiLoading.value = false
      embedLoading.value = false
      embedGeneration.value += 1
    }
  },
  { immediate: true },
)

watch(fbColorScheme, () => {
  if (props.open && !apiLoading.value && !useIframeEmbed.value && !useMobileFeed.value) {
    mountEmbed()
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  clearEmbedWatch()
  resizeObserver?.disconnect()
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="auth-modal-backdrop facebook-modal-backdrop" @click.self="emit('close')">
      <section
        class="auth-modal facebook-modal"
        :class="{ 'facebook-modal--dark': theme === 'dark' }"
        role="dialog"
        aria-modal="true"
        :aria-label="t('facebookPage')"
      >
        <button class="auth-close" type="button" :aria-label="t('cancel')" @click="emit('close')" />

        <header class="facebook-modal__head">
          <p class="facebook-modal__eyebrow">
            <span class="facebook-modal__eyebrow-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="16" height="16">
                <path
                  fill="currentColor"
                  d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.24 2.68.24v2.95h-1.51c-1.49 0-1.96.93-1.96 1.87V12h3.33l-.53 3.47h-2.8v8.38A12 12 0 0 0 24 12z"
                />
              </svg>
            </span>
            Facebook
          </p>
          <h2>
            <span v-if="apiLoading" class="facebook-modal__sk facebook-modal__sk--title" aria-hidden="true" />
            <template v-else>{{ stats?.name || 'HinYerevan' }}</template>
          </h2>
          <p class="facebook-modal__stats">
            <span v-if="apiLoading" class="facebook-modal__sk facebook-modal__sk--stat" aria-hidden="true" />
            <template v-else-if="(stats?.followers_count || stats?.fan_count || 0) > 0">
              {{ t('facebookFollowers') }}:
              <strong>{{ (stats.followers_count || stats.fan_count || 0).toLocaleString() }}</strong>
            </template>
          </p>
          <p v-if="!isMobileLayout" class="facebook-modal__intro">{{ t('facebookPageIntro') }}</p>
          <a
            v-if="!isMobileLayout"
            class="button facebook-modal__follow"
            :href="followUrl"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ t('facebookFollow') }}
          </a>
        </header>

        <div
          ref="embedBox"
          class="facebook-modal__embed"
          :class="{ 'facebook-modal__embed--mobile': useMobileFeed }"
          :style="{ '--fb-plugin-height': embedHeight }"
          :aria-busy="showSkeleton || embedLoading"
        >
          <div class="facebook-modal__skeleton" :class="{ 'is-hidden': !showSkeleton }" aria-hidden="true">
            <div class="facebook-modal__skeleton-cover" />
            <div class="facebook-modal__skeleton-body">
              <span class="facebook-modal__skeleton-line facebook-modal__skeleton-line--lg" />
              <span class="facebook-modal__skeleton-line" />
              <span class="facebook-modal__skeleton-line" />
            </div>
          </div>

          <div
            v-if="embedLoading"
            class="facebook-modal__embed-busy"
            aria-hidden="true"
          />

          <div ref="plugin" class="facebook-modal__plugin" :class="{ 'is-visible': showEmbed, 'facebook-modal__plugin--mobile': useMobileFeed }">
            <div v-if="pluginReady && !pluginFailed && useMobileFeed" class="facebook-mobile-feed">
              <a
                v-for="(post, index) in mobilePosts"
                :key="post.permalink_url || index"
                class="facebook-mobile-post"
                :href="post.permalink_url || mobilePageUrl"
                target="_blank"
                rel="noopener noreferrer"
              >
                <img
                  v-if="post.picture_url"
                  class="facebook-mobile-post__image"
                  :src="post.picture_url"
                  alt=""
                  loading="lazy"
                  decoding="async"
                />
                <div class="facebook-mobile-post__body">
                  <time v-if="post.created_time" class="facebook-mobile-post__date">{{ formatPostDate(post.created_time) }}</time>
                  <p v-if="post.message" class="facebook-mobile-post__text">{{ postExcerpt(post.message) }}</p>
                </div>
              </a>
              <p v-if="!mobilePosts.length" class="facebook-mobile-feed__empty">{{ t('facebookMobileFeedEmpty') }}</p>
              <a class="button facebook-mobile-feed__more" :href="mobilePageUrl" target="_blank" rel="noopener noreferrer">
                {{ t('facebookOpenPage') }}
              </a>
            </div>
            <iframe
              v-else-if="pluginReady && !pluginFailed && useIframeEmbed"
              :key="`iframe-${embedGeneration}-${pluginWidth}`"
              class="facebook-modal__iframe"
              :src="iframeSrc"
              :width="pluginWidth"
              :height="PLUGIN_HEIGHT"
              style="border: none; overflow: hidden"
              scrolling="no"
              frameborder="0"
              allowfullscreen="true"
              allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"
              :title="t('facebookPage')"
            />
            <div
              v-else-if="pluginReady && !pluginFailed"
              :key="`${embedGeneration}-${fbColorScheme}-${pluginWidth}`"
              class="fb-page"
              :data-href="followUrl"
              data-tabs="timeline"
              :data-width="String(pluginWidth)"
              :data-height="String(PLUGIN_HEIGHT)"
              data-small-header="false"
              data-adapt-container-width="true"
              data-hide-cover="false"
              data-show-facepile="true"
              :data-colorscheme="fbColorScheme"
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

.facebook-modal.auth-modal {
  display: flex;
  flex-direction: column;
  width: min(540px, calc(100vw - 28px)) !important;
  max-width: calc(100vw - 28px);
  max-height: calc(100vh - 32px);
  padding: 0 !important;
  overflow-x: hidden;
  overflow-y: auto;
  border-radius: $radius-lg;
}

.facebook-modal--dark.auth-modal {
  background: #161b25;
  color: #e7ebf3;
  border-color: #2a313d;
  box-shadow: 0 20px 56px rgba(0, 0, 0, 0.55);
}

.facebook-modal__head {
  display: grid;
  gap: 8px;
  flex-shrink: 0;
  padding: 20px 20px 10px;

  h2 {
    margin: 0;
    min-height: 32px;
    font-size: clamp(20px, 4vw, 26px);
    line-height: 1.2;
  }
}

.facebook-modal__eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: 0;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: $muted;
}

.facebook-modal__eyebrow-icon {
  display: inline-flex;
  color: #1877f2;
}

.facebook-modal--dark .facebook-modal__eyebrow {
  color: #9aa3b5;
}

.facebook-modal--dark .facebook-modal__intro {
  color: #9aa3b5;
}

.facebook-modal__stats {
  margin: 0;
  min-height: 20px;
  color: $primary;
}

.facebook-modal--dark .facebook-modal__stats {
  color: #b8c4e8;
}

.facebook-modal__intro {
  margin: 0;
  color: $muted;
  line-height: 1.5;
  font-size: 13px;
}

.facebook-modal__follow {
  justify-self: start;
  margin-top: 2px;
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
  width: min(220px, 70%);
  height: 26px;
}

.facebook-modal__sk--stat {
  width: 120px;
  height: 16px;
}

.facebook-modal__embed {
  position: relative;
  flex: 0 0 auto;
  min-height: var(--fb-plugin-height, 520px);
  margin: 8px 14px 16px;
  border-radius: $radius-lg;
  background: $surface-soft;
  overflow: hidden;
}

.facebook-modal--dark .facebook-modal__embed {
  background: #0f131a;
  border: 1px solid #2a313d;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
}

.facebook-modal__skeleton {
  position: absolute;
  inset: 0;
  z-index: 2;
  display: grid;
  grid-template-rows: 120px 1fr;
  gap: 10px;
  padding: 12px;
  opacity: 1;
  transition: opacity 0.35s ease;
  pointer-events: none;

  &.is-hidden {
    opacity: 0;
  }
}

.facebook-modal__skeleton-cover,
.facebook-modal__skeleton-line {
  border-radius: $radius-md;
  background:
    linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.55), transparent),
    rgba(0, 0, 0, 0.06);
  background-size: 220px 100%, 100% 100%;
  animation: skeleton-shimmer 1.1s infinite linear;
}

.facebook-modal__skeleton-body {
  display: grid;
  gap: 8px;
  align-content: start;
}

.facebook-modal__skeleton-line {
  height: 12px;
  border-radius: $radius-pill;

  &--lg {
    width: 85%;
    height: 16px;
  }

  &:not(&--lg) {
    width: 70%;
  }
}

.facebook-modal__plugin {
  position: absolute;
  inset: 0;
  z-index: 1;
  opacity: 0;
  transition: opacity 0.4s ease 0.06s;
  overflow: hidden;

  &.is-visible {
    opacity: 1;
  }

  :deep(iframe),
  :deep(.fb-page),
  :deep(span) {
    display: block;
    max-width: 100% !important;
  }

  :deep(iframe) {
    border-radius: 0 0 $radius-lg $radius-lg;
  }
}

.facebook-modal__iframe {
  width: 100% !important;
  max-width: 100% !important;
  min-height: var(--fb-plugin-height, 520px);
}

.facebook-modal__embed--mobile {
  min-height: 0;
  overflow: visible;
}

.facebook-modal__plugin--mobile {
  position: relative;
  inset: auto;
  overflow: visible;
}

.facebook-mobile-feed {
  display: grid;
  gap: 10px;
  padding: 4px 2px 2px;
  max-height: min(58vh, 480px);
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}

.facebook-mobile-post {
  display: grid;
  gap: 8px;
  padding: 10px;
  border-radius: $radius-md;
  background: rgba(255, 255, 255, 0.92);
  color: inherit;
  text-decoration: none;
  box-shadow: 0 1px 0 rgba(0, 0, 0, 0.06);
  @include interactive((transform, box-shadow));

  &:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
  }
}

.facebook-mobile-post__image {
  display: block;
  width: 100%;
  max-height: 180px;
  border-radius: $radius-sm;
  object-fit: cover;
}

.facebook-mobile-post__body {
  display: grid;
  gap: 4px;
}

.facebook-mobile-post__date {
  color: $muted;
  font-size: 12px;
}

.facebook-mobile-post__text {
  margin: 0;
  font-size: 14px;
  line-height: 1.45;
}

.facebook-mobile-feed__empty {
  margin: 0;
  padding: 16px 8px;
  text-align: center;
  color: $muted;
  font-size: 14px;
  line-height: 1.5;
}

.facebook-mobile-feed__more {
  justify-self: center;
  margin-top: 4px;
}

.facebook-modal--dark {
  .facebook-mobile-post {
    background: #1c2128;
    box-shadow: inset 0 0 0 1px #2a313d;
  }

  .facebook-mobile-post__date,
  .facebook-mobile-feed__empty {
    color: #9aa3b5;
  }
}

.facebook-modal__embed-busy {
  position: absolute;
  inset: 0;
  z-index: 2;
  pointer-events: none;
  background: rgba(255, 255, 255, 0.35);
}

.facebook-modal--dark .facebook-modal__embed-busy {
  background: rgba(0, 0, 0, 0.2);
}

.facebook-modal__fallback {
  display: grid;
  gap: 12px;
  place-items: center;
  height: 100%;
  padding: 36px 18px;
  text-align: center;
  color: $muted;

  p {
    margin: 0;
    max-width: 32ch;
    line-height: 1.5;
    font-size: 14px;
  }
}

.facebook-modal--dark {
  .facebook-modal__sk,
  .facebook-modal__skeleton-cover,
  .facebook-modal__skeleton-line {
    background:
      linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent),
      rgba(255, 255, 255, 0.06);
    background-size: 220px 100%, 100% 100%;
  }

  .facebook-modal__fallback {
    color: #9aa3b5;
  }

  .auth-close {
    color: #e7ebf3;
    background: rgba(255, 255, 255, 0.08);

    &:hover {
      background: #f4f7ff;
      color: #14171e;
    }
  }
}
</style>

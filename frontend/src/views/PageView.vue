<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api'
import { useI18n } from '../i18n'
import FeedbackForm from '../components/FeedbackForm.vue'
import { setPageMeta } from '../utils/seo'
import { agreementFor, agreementUpdated } from '../content/agreement'
import { privacyFor, privacyUpdated } from '../content/privacy'

const route = useRoute()
const { t, currentLanguage } = useI18n()
const page = ref(null)
const loading = ref(true)
const error = ref('')

const isFeedback = computed(() => route.params.alias === 'feedback')
const isAgreement = computed(() => route.params.alias === 'agreement')
const isPrivacy = computed(() => route.params.alias === 'privacy')
const agreement = computed(() => agreementFor(currentLanguage.value))
const privacy = computed(() => privacyFor(currentLanguage.value))

const staticPage = computed(() => {
  if (isAgreement.value) {
    return { doc: agreement.value, updated: agreementUpdated, eyebrow: t('agreement') }
  }
  if (isPrivacy.value) {
    return { doc: privacy.value, updated: privacyUpdated, eyebrow: t('privacyPolicy') }
  }
  return null
})

function plainText(html) {
  if (!html) return ''
  const node = document.createElement('div')
  node.innerHTML = html
  return (node.textContent || '').trim()
}

async function load() {
  loading.value = true
  error.value = ''

  if (staticPage.value) {
    page.value = null
    error.value = ''
    loading.value = false
    setPageMeta({
      title: staticPage.value.doc.title,
      description: staticPage.value.doc.intro[0]?.slice(0, 160),
      path: route.fullPath,
    })
    return
  }

  try {
    page.value = await api(`/pages/${route.params.alias}`)
    const title = isFeedback.value ? t('pageTitleFeedback') : page.value?.title
    const snippet = plainText(page.value?.content).slice(0, 160)
    setPageMeta({
      title,
      description: snippet || t('metaDescriptionDefault'),
      path: route.fullPath,
    })
  } catch (event) {
    page.value = null
    error.value = event.message || t('pageLoadError')
    setPageMeta({ title: t('pageNotFound'), path: route.fullPath, noindex: true })
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => route.params.alias, load)
watch(currentLanguage, load)
</script>

<template>
  <article v-if="staticPage" class="panel content-card static-page agreement-page">
    <header class="agreement-head">
      <p class="eyebrow">{{ staticPage.eyebrow }}</p>
      <h1>{{ staticPage.doc.title }}</h1>
      <p class="agreement-updated">{{ staticPage.doc.updatedLabel }}: {{ staticPage.updated }}</p>
    </header>

    <div class="agreement-body">
      <p v-for="(line, index) in staticPage.doc.intro" :key="`intro-${index}`" class="agreement-lead">
        {{ line }}
      </p>

      <section v-for="(section, index) in staticPage.doc.sections" :key="`section-${index}`" class="agreement-section">
        <h2>{{ section.heading }}</h2>
        <p v-for="(para, pIndex) in section.paragraphs || []" :key="`p-${pIndex}`">{{ para }}</p>
        <ul v-if="section.list">
          <li v-for="(item, lIndex) in section.list" :key="`l-${lIndex}`">{{ item }}</li>
        </ul>
      </section>
    </div>
  </article>

  <article v-else-if="loading" class="panel content-card static-page">
    <p>{{ t('loading') }}</p>
  </article>

  <article v-else-if="error && !page" class="panel content-card static-page">
    <h1>{{ t('pageNotFound') }}</h1>
    <p class="error">{{ error }}</p>
  </article>

  <article v-else-if="page" class="panel content-card static-page">
    <p v-if="isFeedback" class="eyebrow">{{ t('feedback') }}</p>
    <h1>{{ page.title || t('feedback') }}</h1>

    <FeedbackForm v-if="isFeedback || page.type === 'feedback'" />

    <div v-else-if="page.content" class="static-page-body" v-html="page.content" />
  </article>
</template>

<style lang="scss">
.content-card {
  max-width: 100%;
}

.static-page {
  min-height: auto;
  padding: clamp(24px, 4vw, 52px);
  font-size: 16px;
  line-height: 1.72;

  h1 {
    max-width: none;
    margin-bottom: 20px;
  }

  img {
    border-radius: 18px;
  }
}

.static-page-body {
  :deep(a) {
    color: $primary;
    text-decoration: underline;
    text-underline-offset: 3px;

    &:hover {
      color: $primary-dark;
    }
  }
}

.agreement-page {
  max-width: 860px;
  margin-inline: auto;
}

.agreement-head {
  padding-bottom: 24px;
  margin-bottom: 28px;
  border-bottom: 1px solid $line;

  h1 {
    margin: 6px 0 0;
  }

  .agreement-updated {
    margin: 14px 0 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: $radius-pill;
    background: $primary-light;
    color: $primary-dark;
    font-size: 13px;
    font-weight: 600;
  }
}

.agreement-body {
  display: grid;
  gap: 30px;
  counter-reset: agreement-section;
}

.agreement-lead {
  font-size: 17px;
  line-height: 1.75;
  color: $ink;

  & + .agreement-lead {
    margin-top: -14px;
  }
}

.agreement-section {
  display: grid;
  gap: 10px;

  h2 {
    font-size: 19px;
    font-weight: 700;
    line-height: 1.4;
    color: $ink;
  }

  p {
    margin: 0;
    color: $muted;
    line-height: 1.72;
  }

  ul {
    margin: 4px 0 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 10px;
  }

  li {
    position: relative;
    padding-left: 22px;
    color: $muted;
    line-height: 1.65;

    &::before {
      content: '';
      position: absolute;
      left: 2px;
      top: 9px;
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: $accent;
    }
  }
}
</style>

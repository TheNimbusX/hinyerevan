<script setup>
import { computed, onMounted, ref } from 'vue'
import { api, imageUrl } from '../api'
import { useI18n } from '../i18n'
import { formatDateTime } from '../utils/locale'

const { t, currentLanguage } = useI18n()

function when(value) {
  return value ? formatDateTime(value, currentLanguage.value) : '—'
}

const loading = ref(true)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const enabled = ref(false)
const maxPublic = ref(4)
const banners = ref([])

const newForm = ref(emptyForm())
const newFileInput = ref(null)

const activeCount = computed(() => banners.value.filter((b) => b.active).length)

function emptyForm() {
  return { title: '', link_url: '', active: true, file: null }
}

function toDraft(banner) {
  return { ...banner, file: null, previewUrl: '' }
}

function applyPayload(payload) {
  enabled.value = Boolean(payload?.enabled)
  maxPublic.value = Number(payload?.max_public) || 4
  banners.value = (payload?.banners || []).map(toDraft)
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    applyPayload(await api('/admin/ads'))
  } catch (event) {
    error.value = event.message
  } finally {
    loading.value = false
  }
}

function flash(message) {
  notice.value = message
  setTimeout(() => {
    if (notice.value === message) notice.value = ''
  }, 2500)
}

async function run(action) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await action()
  } catch (event) {
    error.value = event.message
  } finally {
    busy.value = false
  }
}

function toggleEnabled() {
  const next = !enabled.value
  run(async () => {
    applyPayload(await api('/admin/ads/settings', { method: 'PUT', body: { enabled: next } }))
    flash(next ? t('adminAdsEnabledOn') : t('adminAdsEnabledOff'))
  })
}

function pickNewFile(event) {
  newForm.value.file = event.target.files?.[0] || null
}

function bannerBody(source, file) {
  const body = new FormData()
  body.append('title', source.title || '')
  body.append('link_url', (source.link_url || '').trim())
  body.append('active', source.active ? '1' : '0')
  if (file) body.append('image', file)
  return body
}

function addBanner() {
  if (!newForm.value.file) {
    error.value = t('adminAdsImageRequired')
    return
  }
  run(async () => {
    await api('/admin/ads', { method: 'POST', body: bannerBody(newForm.value, newForm.value.file) })
    newForm.value = emptyForm()
    if (newFileInput.value) newFileInput.value.value = ''
    applyPayload(await api('/admin/ads'))
    flash(t('adminAdsSaved'))
  })
}

function pickReplaceFile(banner, event) {
  const file = event.target.files?.[0] || null
  banner.file = file
  banner.previewUrl = file ? URL.createObjectURL(file) : ''
}

function saveBanner(banner) {
  run(async () => {
    const updated = await api(`/admin/ads/${banner.id}`, { method: 'POST', body: bannerBody(banner, banner.file) })
    const index = banners.value.findIndex((b) => b.id === banner.id)
    if (index >= 0) banners.value[index] = toDraft(updated)
    flash(t('adminAdsSaved'))
  })
}

function deleteBanner(banner) {
  if (!window.confirm(t('adminAdsDeleteConfirm'))) return
  run(async () => {
    await api(`/admin/ads/${banner.id}`, { method: 'DELETE' })
    banners.value = banners.value.filter((b) => b.id !== banner.id)
  })
}

function move(banner, delta) {
  const list = [...banners.value]
  const from = list.findIndex((b) => b.id === banner.id)
  const to = from + delta
  if (from < 0 || to < 0 || to >= list.length) return
  ;[list[from], list[to]] = [list[to], list[from]]
  banners.value = list
  run(async () => {
    applyPayload(await api('/admin/ads/reorder', { method: 'POST', body: { ids: list.map((b) => b.id) } }))
  })
}

onMounted(load)
</script>

<template>
  <section class="admin__box admin-ads">
    <p v-if="loading" class="admin__empty">{{ t('loading') }}</p>

    <template v-else>
      <div class="admin-ads__switch-row">
        <label class="admin-ads__switch">
          <input type="checkbox" :checked="enabled" :disabled="busy" @change="toggleEnabled" />
          <span class="admin-ads__switch-ui" aria-hidden="true"></span>
          <strong>{{ t('adminAdsEnabled') }}</strong>
        </label>
        <span class="admin-ads__status" :class="{ on: enabled }">
          {{ enabled ? t('adminAdsStatusOn') : t('adminAdsStatusOff') }}
        </span>
      </div>
      <p class="admin-ads__hint">
        {{ t('adminAdsHint', { max: maxPublic }) }}
        <template v-if="activeCount > maxPublic"> {{ t('adminAdsTooMany', { count: activeCount, max: maxPublic }) }}</template>
      </p>

      <p v-if="error" class="admin__msg admin__msg--err">{{ error }}</p>
      <p v-if="notice" class="admin__msg admin-ads__notice">{{ notice }}</p>

      <div class="admin-ads__list">
        <article
          v-for="(banner, index) in banners"
          :key="banner.id"
          class="admin-ads__card"
          :class="{ 'is-off': !banner.active, 'is-hidden': banner.active && index >= maxPublic }"
        >
          <div class="admin-ads__media">
            <img :src="banner.previewUrl || imageUrl(banner.image_url)" :alt="banner.title || ''" />
            <span class="admin-ads__pos">{{ index + 1 }}</span>
          </div>
          <div class="admin-ads__fields">
            <dl class="admin-ads__stats">
              <div>
                <dt>{{ t('adminAdsClicks') }}</dt>
                <dd class="admin-ads__clicks">{{ banner.clicks ?? 0 }}</dd>
              </div>
              <div>
                <dt>{{ t('adminAdsLastClick') }}</dt>
                <dd>{{ when(banner.last_clicked_at) }}</dd>
              </div>
              <div>
                <dt>{{ t('adminAdsCreated') }}</dt>
                <dd>{{ when(banner.created_at) }}</dd>
              </div>
              <div>
                <dt>{{ t('adminAdsUpdated') }}</dt>
                <dd>{{ when(banner.updated_at) }}</dd>
              </div>
            </dl>
            <label class="admin__field">
              <span>{{ t('adminAdsTitle') }}</span>
              <input v-model="banner.title" class="admin__input" maxlength="160" />
            </label>
            <label class="admin__field">
              <span>{{ t('adminAdsLink') }}</span>
              <input v-model="banner.link_url" class="admin__input" type="url" placeholder="https://" />
            </label>
            <label class="admin__field admin__field--row">
              <input v-model="banner.active" type="checkbox" />
              <span>{{ t('adminAdsActive') }}</span>
            </label>
            <label class="admin__field">
              <span>{{ t('adminAdsReplaceImage') }}</span>
              <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" @change="pickReplaceFile(banner, $event)" />
            </label>
            <div class="admin__act-group">
              <button type="button" class="admin__btn" :disabled="busy" @click="saveBanner(banner)">{{ t('save') }}</button>
              <button type="button" class="admin__act" :disabled="busy || index === 0" :aria-label="t('adminAdsUp')" @click="move(banner, -1)">↑</button>
              <button type="button" class="admin__act" :disabled="busy || index === banners.length - 1" :aria-label="t('adminAdsDown')" @click="move(banner, 1)">↓</button>
              <button type="button" class="admin__act admin__act--danger" :disabled="busy" @click="deleteBanner(banner)">{{ t('adminDelete') }}</button>
            </div>
          </div>
        </article>

        <p v-if="!banners.length" class="admin__empty">{{ t('adminAdsEmpty') }}</p>
      </div>

      <form class="admin-ads__new" @submit.prevent="addBanner">
        <h3>{{ t('adminAdsAdd') }}</h3>
        <label class="admin__field">
          <span>{{ t('adminAdsImage') }}</span>
          <input ref="newFileInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" @change="pickNewFile" />
          <small>{{ t('adminAdsImageHint') }}</small>
        </label>
        <label class="admin__field">
          <span>{{ t('adminAdsTitle') }}</span>
          <input v-model="newForm.title" class="admin__input" maxlength="160" />
        </label>
        <label class="admin__field">
          <span>{{ t('adminAdsLink') }}</span>
          <input v-model="newForm.link_url" class="admin__input" type="url" placeholder="https://" />
        </label>
        <label class="admin__field admin__field--row">
          <input v-model="newForm.active" type="checkbox" />
          <span>{{ t('adminAdsActive') }}</span>
        </label>
        <button type="submit" class="admin__btn" :disabled="busy">{{ t('adminAdsAdd') }}</button>
      </form>
    </template>
  </section>
</template>

<style lang="scss">
.admin-ads {
  display: grid;
  gap: 14px;
}

.admin-ads__switch-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}

.admin-ads__switch {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;

  input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
  }
}

.admin-ads__switch-ui {
  position: relative;
  width: 44px;
  height: 24px;
  border-radius: 999px;
  background: #c8cfdb;
  transition: background 0.2s ease;

  &::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
    transition: transform 0.2s ease;
  }
}

.admin-ads__switch input:checked + .admin-ads__switch-ui {
  background: $success;

  &::after {
    transform: translateX(20px);
  }
}

.admin-ads__switch input:focus-visible + .admin-ads__switch-ui {
  outline: 2px solid rgba($primary, 0.5);
  outline-offset: 2px;
}

.admin-ads__status {
  padding: 3px 10px;
  border-radius: 999px;
  background: rgba($ink, 0.06);
  color: $muted;
  font-size: 0.8571rem;
  font-weight: 600;

  &.on {
    background: rgba($success, 0.14);
    color: $success;
  }
}

.admin-ads__hint {
  margin: 0;
  color: $muted;
  font-size: 0.9286rem;
}

.admin-ads__notice {
  color: $success;
}

.admin-ads__list {
  display: grid;
  gap: 12px;
}

.admin-ads__card {
  display: grid;
  grid-template-columns: 240px minmax(0, 1fr);
  align-items: start;
  gap: 18px;
  padding: 14px;
  border: 1px solid $line;
  border-radius: $radius-md;
  background: $surface;

  &.is-off {
    opacity: 0.6;
  }

  &.is-hidden {
    border-style: dashed;
  }

  @include mq-down($bp-sm) {
    grid-template-columns: minmax(0, 1fr);
  }
}

// Same 16:9 frame as on the site.
.admin-ads__media {
  position: relative;
  align-self: start;
  aspect-ratio: 16 / 9;
  min-height: 0;
  overflow: hidden;
  border-radius: $radius-sm;
  background: $surface-soft;

  img {
    position: absolute;
    inset: 0;
    display: block;
    width: 100%;
    height: 100%;
    max-width: none;
    object-fit: contain;
  }
}

.admin-ads__pos {
  position: absolute;
  top: 6px;
  left: 6px;
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.6);
  color: #fff;
  font-size: 0.8571rem;
  font-weight: 700;
}

.admin-ads__fields {
  display: grid;
  gap: 8px;
  align-content: start;
}

.admin-ads__stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 6px 14px;
  margin: 0 0 4px;
  padding: 8px 10px;
  background: $surface-soft;

  div {
    min-width: 0;
  }

  dt {
    color: $muted;
    font-size: 0.7857rem;
  }

  dd {
    margin: 0;
    font-size: 0.9286rem;
    font-weight: 600;
  }
}

.admin-ads__clicks {
  color: $primary;
  font-size: 1.1429rem !important;
}

// Admin pages are dark-themed too; white cards looked out of place.
[data-theme='dark'] {
  .admin-ads__card {
    border-color: #2a313d;
    background: #1b212c;
  }

  .admin-ads__media {
    background: #0f131a;
  }

  .admin-ads__stats {
    background: #1e2330;

    dt {
      color: #98a0ae;
    }

    dd {
      color: #e7ebf3;
    }
  }

  .admin-ads__clicks {
    color: #8fb0ff !important;
  }

  .admin-ads__new {
    border-color: #3a4250;
  }

  .admin-ads__switch-ui {
    background: #3a4250;
  }

  .admin-ads__status {
    background: rgba(255, 255, 255, 0.06);
    color: #98a0ae;

    &.on {
      background: rgba(76, 175, 120, 0.18);
      color: #7fd1a3;
    }
  }

  .admin-ads input[type='file'] {
    color: #c3c9d4;
  }
}

.admin-ads__new {
  display: grid;
  gap: 8px;
  max-width: 520px;
  padding: 14px;
  border: 1px dashed $line;
  border-radius: $radius-md;

  h3 {
    margin: 0;
    font-size: 1.0714rem;
  }

  small {
    color: $muted;
  }
}
</style>

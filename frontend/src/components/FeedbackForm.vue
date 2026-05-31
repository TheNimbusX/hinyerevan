<script setup>
import { onMounted, ref } from 'vue'
import { api, getToken } from '../api'
import { useI18n } from '../i18n'

const { t } = useI18n()

const form = ref({
  name: '',
  email: '',
  content: '',
})
const locked = ref(false)
const sending = ref(false)
const error = ref('')
const success = ref(false)

onMounted(async () => {
  if (!getToken()) return

  try {
    const user = await api('/auth/me')
    const name = [user.first_name, user.last_name].filter(Boolean).join(' ').trim()
    form.value.name = name
    form.value.email = user.email || ''
    locked.value = Boolean(name && user.email)
  } catch {
    locked.value = false
  }
})

async function submit() {
  error.value = ''
  success.value = false
  sending.value = true

  try {
    await api('/feedback', {
      method: 'POST',
      body: form.value,
    })
    success.value = true
    if (!locked.value) {
      form.value.content = ''
    }
  } catch (event) {
    error.value = event.message
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <form class="feedback-form stack-form" @submit.prevent="submit">
    <p class="feedback-intro">{{ t('feedbackIntro') }}</p>

    <label class="feedback-field">
      <span>{{ t('feedbackName') }}</span>
      <input v-model="form.name" type="text" name="name" required :readonly="locked" />
    </label>

    <label class="feedback-field">
      <span>{{ t('feedbackEmail') }}</span>
      <input v-model="form.email" type="email" name="email" required :readonly="locked" />
    </label>

    <label class="feedback-field feedback-field--message">
      <span>{{ t('feedbackMessage') }}</span>
      <textarea v-model="form.content" name="content" rows="8" required />
    </label>

    <button class="button" type="submit" :disabled="sending">
      {{ sending ? t('loading') : t('feedbackSend') }}
    </button>

    <p v-if="success" class="feedback-success">{{ t('feedbackSent') }}</p>
    <p v-if="error" class="error">{{ error }}</p>
  </form>
</template>

<style lang="scss">
.feedback-form {
  max-width: 640px;
}

.feedback-intro {
  margin: 0 0 18px;
  color: $muted;
  line-height: 1.6;
}

.feedback-field {
  display: grid;
  gap: 6px;

  span {
    font-size: 13px;
    font-weight: 600;
    color: $ink;
  }

  input,
  textarea {
    width: 100%;
    border: 1px solid $line;
    border-radius: $radius-sm;
    padding: 12px 14px;
    font: inherit;
    background: $surface;
    color: $ink;
    @include interactive((border-color, box-shadow));

    &:focus {
      outline: none;
      border-color: rgba($primary, 0.45);
      box-shadow: 0 0 0 3px rgba($primary, 0.12);
    }

    &:read-only {
      background: $surface-soft;
      color: $muted;
    }
  }

  textarea {
    min-height: 160px;
    resize: vertical;
  }
}

.feedback-success {
  margin: 0;
  color: darken($accent, 8%);
  font-weight: 600;
}
</style>

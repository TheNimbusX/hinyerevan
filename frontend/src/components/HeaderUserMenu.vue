<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, safeAvatarUrl, setToken } from '../api'
import { useI18n } from '../i18n'
import { isAdminUser } from '../utils/user'
import siteLogo from '../assets/logos/Logo2026.png'

const props = defineProps({
  user: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['close'])

const { t } = useI18n()
const router = useRouter()
const open = ref(false)
const root = ref(null)
const showAdminLink = computed(() => isAdminUser(props.user))

function avatarUrl(user) {
  return safeAvatarUrl(user?.photo, siteLogo)
}

function toggle() {
  open.value = !open.value
}

function close() {
  open.value = false
  emit('close')
}

function goProfile() {
  close()
  router.push('/profile')
}

function goAdmin() {
  close()
  router.push('/admin')
}

async function logout() {
  try {
    await api('/auth/logout', { method: 'POST' })
  } catch {
    // still clear local session
  }
  setToken(null)
  close()
  window.dispatchEvent(new CustomEvent('hinyerevan:auth-changed'))
  router.push('/')
}

function onDocClick(event) {
  if (!root.value || !open.value) return
  if (!root.value.contains(event.target)) {
    open.value = false
  }
}

function onKey(event) {
  if (event.key === 'Escape' && open.value) {
    open.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', onDocClick)
  window.addEventListener('keydown', onKey)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
  window.removeEventListener('keydown', onKey)
})
</script>

<template>
  <div ref="root" class="header-user-menu" :class="{ open }">
    <button
      type="button"
      class="header-user-trigger"
      :aria-expanded="open"
      :aria-haspopup="true"
      @click="toggle"
    >
      <img :src="avatarUrl(user)" :alt="user.name" />
      <span class="header-user-name">{{ user.name }}</span>
      <svg class="header-user-caret" viewBox="0 0 12 12" aria-hidden="true">
        <path d="M3 4.5 6 7.5l3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </button>

    <transition name="user-menu-pop">
      <div v-if="open" class="header-user-dropdown" role="menu">
        <button type="button" role="menuitem" @click="goProfile">{{ t('profile') }}</button>
        <button v-if="showAdminLink" type="button" role="menuitem" @click="goAdmin">{{ t('admin') }}</button>
        <button type="button" role="menuitem" class="is-danger" @click="logout">{{ t('logout') }}</button>
      </div>
    </transition>
  </div>
</template>

<style lang="scss">
.header-user-menu {
  position: relative;
}

.header-user-trigger {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 4px 10px 4px 4px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface;
  color: $ink;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  @include interactive((background, border-color, transform));

  &:hover {
    border-color: $ink;
    transform: translateY(-1px);
  }

  img {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    object-fit: cover;
    background: $bg;
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.header-user-name {
  max-width: 140px;
  @include truncate;
}

.header-user-caret {
  width: 12px;
  height: 12px;
  color: $muted;
  flex-shrink: 0;
  transition: transform $duration $ease;
}

.header-user-menu.open .header-user-caret {
  transform: rotate(180deg);
}

.header-user-dropdown {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 920;
  display: grid;
  gap: 4px;
  min-width: 180px;
  padding: 6px;
  border: 1px solid $line;
  border-radius: $radius-md;
  background: $surface;
  box-shadow: $shadow-lg;

  button {
    display: flex;
    align-items: center;
    width: 100%;
    padding: 10px 12px;
    border: 0;
    border-radius: $radius-sm;
    background: transparent;
    color: $ink;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    text-align: left;
    @include interactive((background, color));

    &:hover {
      background: rgba($ink, 0.06);
    }

    &.is-danger {
      color: $danger;

      &:hover {
        background: rgba($danger, 0.08);
      }
    }

    @include focus-ring(rgba($primary, 0.4), 2px);
  }
}

.user-menu-pop-enter-active,
.user-menu-pop-leave-active {
  transition:
    opacity $duration-fast ease,
    transform $duration-fast ease;
  transform-origin: top right;
}

.user-menu-pop-enter-from,
.user-menu-pop-leave-to {
  opacity: 0;
  transform: translateY(-4px) scale(0.98);
}

@include mq-down($bp-md) {
  .header-user-menu {
    width: 100%;
  }

  .header-user-trigger {
    width: 100%;
    justify-content: flex-start;
    padding: 10px 12px;
  }

  .header-user-name {
    flex: 1;
    max-width: none;
    text-align: left;
  }

  .header-user-dropdown {
    position: static;
    width: 100%;
    margin-top: 4px;
    box-shadow: none;
    border: 1px solid $line;
  }
}

[data-theme='dark'] {
  .header-user-trigger {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(255, 255, 255, 0.1);
    color: #e7ebf3;
  }

  .header-user-dropdown {
    background: #161b25;
    border-color: #2a313d;
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.5);

    button {
      color: #e7ebf3;

      &:hover {
        background: rgba(255, 255, 255, 0.06);
      }

      &.is-danger {
        color: #ff8a8a;
      }
    }
  }
}
</style>

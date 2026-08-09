<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from '../i18n'
import LanguageSwitcher from './LanguageSwitcher.vue'
import ThemeToggle from './ThemeToggle.vue'

const emit = defineEmits(['add-photo'])

const { t } = useI18n()
const open = ref(false)
const root = ref(null)

function toggle() {
  open.value = !open.value
}

function close() {
  open.value = false
}

function onAddPhoto() {
  close()
  emit('add-photo')
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
  <div ref="root" class="header-tools-menu" :class="{ open }">
    <button
      type="button"
      class="header-tools-menu__trigger"
      :aria-expanded="open"
      :aria-haspopup="true"
      :aria-label="t('moreActions')"
      @click="toggle"
    >
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
        <path d="M4 8h7M15 8h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        <path d="M4 16h5M13 16h7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        <circle cx="13" cy="8" r="2.4" fill="none" stroke="currentColor" stroke-width="2" />
        <circle cx="11" cy="16" r="2.4" fill="none" stroke="currentColor" stroke-width="2" />
      </svg>
    </button>

    <transition name="tools-menu-pop">
      <div v-if="open" class="header-tools-menu__panel" role="menu">
        <div class="header-tools-menu__group">
          <span class="header-tools-menu__label">{{ t('language') }}</span>
          <LanguageSwitcher layout="segmented" />
        </div>
        <div class="header-tools-menu__group header-tools-menu__group--row">
          <span class="header-tools-menu__label">{{ t('theme') }}</span>
          <ThemeToggle />
        </div>
        <button
          type="button"
          class="header-tools-menu__action"
          role="menuitem"
          @click="onAddPhoto"
        >
          {{ t('addPhoto') }}
        </button>
      </div>
    </transition>
  </div>
</template>

<style lang="scss">
.header-tools-menu {
  position: relative;
  display: none;
}

.header-tools-menu__trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  padding: 0;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface;
  color: $ink;
  cursor: pointer;
  @include interactive((background, border-color, color, transform));

  &:hover {
    color: #fff;
    border-color: $ink;
    background: $ink;
    transform: translateY(-1px);
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.header-tools-menu.open .header-tools-menu__trigger {
  color: #fff;
  border-color: $ink;
  background: $ink;
}

.header-tools-menu__panel {
  position: absolute;
  top: calc(100% + 10px);
  right: 0;
  z-index: 920;
  display: grid;
  gap: 14px;
  width: min(280px, calc(100vw - 24px));
  padding: 16px;
  border: 1px solid $line;
  border-radius: $radius-lg;
  background: $surface;
  box-shadow: 0 20px 48px rgba(20, 24, 34, 0.18);
}

.header-tools-menu__group {
  display: grid;
  gap: 8px;
}

.header-tools-menu__group--row {
  grid-template-columns: 1fr auto;
  align-items: center;
  gap: 10px;
  padding-top: 14px;
  border-top: 1px solid $line;
}

.header-tools-menu__label {
  color: $muted;
  font-size: 0.7143rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.header-tools-menu__action {
  width: 100%;
  margin-top: 2px;
  padding: 11px 14px;
  border: 0;
  border-radius: $radius-pill;
  background: $ink;
  color: #fff;
  cursor: pointer;
  font-size: 0.9286rem;
  font-weight: 600;
  text-align: center;
  @include interactive((background, transform, box-shadow));

  &:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 22px rgba(20, 24, 34, 0.22);
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.tools-menu-pop-enter-active,
.tools-menu-pop-leave-active {
  transition:
    opacity $duration-fast ease,
    transform $duration-fast ease;
  transform-origin: top right;
}

.tools-menu-pop-enter-from,
.tools-menu-pop-leave-to {
  opacity: 0;
  transform: translateY(-4px) scale(0.98);
}

[data-theme='dark'] {
  .header-tools-menu__trigger {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(255, 255, 255, 0.1);
    color: #e7ebf3;

    &:hover {
      border-color: #f4f7ff;
      background: #f4f7ff;
      color: #14171e;
    }
  }

  .header-tools-menu.open .header-tools-menu__trigger {
    border-color: #f4f7ff;
    background: #f4f7ff;
    color: #14171e;
  }

  .header-tools-menu__panel {
    background: #161b25;
    border-color: #2a313d;
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.5);
  }

  .header-tools-menu__group--row {
    border-top-color: #2a313d;
  }

  .header-tools-menu__label {
    color: #98a0ae;
  }

  .header-tools-menu__action {
    background: #f4f7ff;
    color: #14171e;

    &:hover {
      box-shadow: 0 10px 22px rgba(0, 0, 0, 0.45);
    }
  }
}
</style>

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
        <circle cx="12" cy="5.5" r="1.6" fill="currentColor" />
        <circle cx="12" cy="12" r="1.6" fill="currentColor" />
        <circle cx="12" cy="18.5" r="1.6" fill="currentColor" />
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
  width: 36px;
  height: 36px;
  padding: 0;
  border: 1px solid $line;
  border-radius: 10px;
  background: $surface;
  color: $ink;
  cursor: pointer;
  @include interactive((background, border-color, transform));

  &:hover {
    border-color: $ink;
    transform: translateY(-1px);
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.header-tools-menu__panel {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 920;
  display: grid;
  gap: 12px;
  width: min(280px, calc(100vw - 24px));
  padding: 12px;
  border: 1px solid $line;
  border-radius: $radius-md;
  background: $surface;
  box-shadow: $shadow-lg;
}

.header-tools-menu__group {
  display: grid;
  gap: 8px;
}

.header-tools-menu__group--row {
  grid-template-columns: 1fr auto;
  align-items: center;
  gap: 10px;
}

.header-tools-menu__label {
  color: $muted;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.header-tools-menu__action {
  width: 100%;
  padding: 10px 14px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface-soft;
  color: $ink;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  text-align: center;
  @include interactive((background, color, border-color, transform));

  &:hover {
    color: #fff;
    border-color: $ink;
    background: $ink;
    transform: translateY(-1px);
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
    }
  }

  .header-tools-menu__panel {
    background: #161b25;
    border-color: #2a313d;
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.5);
  }

  .header-tools-menu__label {
    color: #98a0ae;
  }

  .header-tools-menu__action {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(255, 255, 255, 0.1);
    color: #e7ebf3;

    &:hover {
      border-color: #f4f7ff;
      background: #f4f7ff;
      color: #14171e;
    }
  }
}
</style>

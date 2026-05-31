<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from '../i18n'
import armenianFlag from '../assets/flags/am.svg'
import russianFlag from '../assets/flags/ru.svg'
import englishFlag from '../assets/flags/en.svg'

const { currentLanguage, languages, setLanguage, t } = useI18n()

const open = ref(false)
const root = ref(null)

const flagIcons = {
  hy: armenianFlag,
  ru: russianFlag,
  en: englishFlag,
}

const active = computed(
  () => languages.find((l) => l.code === currentLanguage.value) || languages[0],
)

function toggle() {
  open.value = !open.value
}

function pick(code) {
  setLanguage(code)
  open.value = false
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
  <div ref="root" class="language-switcher">
    <!-- Mobile drawer: fixed-height segmented control, no dropdown -->
    <div class="language-segmented" role="listbox" :aria-label="t('language')">
      <button
        v-for="language in languages"
        :key="language.code"
        type="button"
        role="option"
        class="language-segment"
        :class="{ active: currentLanguage === language.code }"
        :aria-selected="currentLanguage === language.code"
        @click="pick(language.code)"
      >
        <img class="flag" :src="flagIcons[language.code]" alt="" />
        <span>{{ language.label }}</span>
      </button>
    </div>

    <!-- Desktop header: compact dropdown -->
    <div class="language-dropdown" :class="{ open }">
      <button
        type="button"
        class="language-trigger"
        :aria-haspopup="true"
        :aria-expanded="open"
        :aria-label="t('language')"
        @click="toggle"
      >
        <img class="flag" :src="flagIcons[active.code]" alt="" />
        <span>{{ active.label }}</span>
        <svg class="language-caret" viewBox="0 0 12 12" aria-hidden="true">
          <path d="M3 4.5 6 7.5l3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>

      <transition name="lang-pop">
        <div v-if="open" class="language-menu" role="listbox">
          <button
            v-for="language in languages"
            :key="language.code"
            type="button"
            role="option"
            :class="{ active: currentLanguage === language.code }"
            :aria-selected="currentLanguage === language.code"
            @click="pick(language.code)"
          >
            <img class="flag" :src="flagIcons[language.code]" alt="" />
            <span>{{ language.label }}</span>
          </button>
        </div>
      </transition>
    </div>
  </div>
</template>

<style lang="scss">
.language-switcher {
  position: relative;
}

// ---------- Mobile: always-visible 3-way switch (inside burger drawer) ----
.language-segmented {
  display: none;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 6px;
  width: 100%;

  @include mq-down($bp-md) {
    .header-menu & {
      display: grid;
    }
  }
}

.language-segment {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 40px;
  padding: 8px 6px;
  border: 1px solid $line;
  border-radius: $radius-md;
  background: $surface-soft;
  color: $ink;
  cursor: pointer;
  font-size: 12px;
  font-weight: 500;
  @include interactive((background, border-color, color, transform));

  &:hover {
    border-color: $ink;
    background: $surface;
  }

  &.active {
    border-color: $ink;
    background: $ink;
    color: #fff;
    box-shadow: 0 6px 16px rgba($ink, 0.2);
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

// ---------- Desktop: dropdown ------------------------------------------------
.language-dropdown {
  position: relative;

  @include mq-down($bp-md) {
    .header-menu & {
      display: none;
    }
  }
}

.language-trigger {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px 6px 8px;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface;
  color: $ink;
  cursor: pointer;
  font-size: 12px;
  font-weight: 500;
  @include interactive((background, border-color, transform));

  &:hover {
    border-color: $ink;
    transform: translateY(-1px);
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.language-caret {
  width: 12px;
  height: 12px;
  color: $muted;
  transition: transform $duration $ease;
}

.language-dropdown.open .language-caret {
  transform: rotate(180deg);
}

.language-menu {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  z-index: 920;
  display: grid;
  gap: 2px;
  min-width: 130px;
  padding: 4px;
  border: 1px solid $line;
  border-radius: $radius-md;
  background: $surface;
  box-shadow: $shadow-lg;

  button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
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

    &.active {
      background: $ink;
      color: #fff;
    }

    @include focus-ring(rgba($primary, 0.4), 2px);
  }
}

.lang-pop-enter-active,
.lang-pop-leave-active {
  transition:
    opacity $duration-fast ease,
    transform $duration-fast ease;
  transform-origin: top right;
}

.lang-pop-enter-from,
.lang-pop-leave-to {
  opacity: 0;
  transform: translateY(-4px) scale(0.96);
}

.flag {
  width: 16px;
  height: 11px;
  border-radius: 2px;
  object-fit: cover;
  flex-shrink: 0;
}

[data-theme='dark'] {
  .language-segment {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(255, 255, 255, 0.1);
    color: #e7ebf3;

    &:hover {
      border-color: #f4f7ff;
      background: rgba(255, 255, 255, 0.08);
    }

    &.active {
      background: #f4f7ff;
      color: #14171e;
      border-color: #f4f7ff;
    }
  }

  .language-trigger {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(255, 255, 255, 0.1);
    color: #e7ebf3;

    &:hover {
      border-color: #f4f7ff;
    }
  }

  .language-menu {
    background: #161b25;
    border-color: #2a313d;
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.5);

    button {
      color: #e7ebf3;

      &:hover {
        background: rgba(255, 255, 255, 0.06);
      }

      &.active {
        background: #f4f7ff;
        color: #14171e;
      }
    }
  }
}
</style>

<script setup>
import { useTheme } from '../composables/useTheme'

const { theme, toggleTheme } = useTheme()
</script>

<template>
  <button
    type="button"
    class="theme-toggle"
    :class="{ 'is-dark': theme === 'dark' }"
    :aria-label="theme === 'dark' ? 'Light mode' : 'Dark mode'"
    :aria-pressed="theme === 'dark'"
    @click="toggleTheme"
  >
    <span class="theme-toggle-track">
      <span class="theme-toggle-thumb">
        <!-- Sun -->
        <svg
          v-if="theme !== 'dark'"
          class="theme-toggle-icon"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <circle cx="12" cy="12" r="4" fill="currentColor" />
          <g stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
            <line x1="12" y1="3" x2="12" y2="5.5" />
            <line x1="12" y1="18.5" x2="12" y2="21" />
            <line x1="3" y1="12" x2="5.5" y2="12" />
            <line x1="18.5" y1="12" x2="21" y2="12" />
            <line x1="5.6" y1="5.6" x2="7.4" y2="7.4" />
            <line x1="16.6" y1="16.6" x2="18.4" y2="18.4" />
            <line x1="5.6" y1="18.4" x2="7.4" y2="16.6" />
            <line x1="16.6" y1="7.4" x2="18.4" y2="5.6" />
          </g>
        </svg>
        <!-- Moon -->
        <svg
          v-else
          class="theme-toggle-icon"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <path
            d="M20.7 14.6a8.5 8.5 0 0 1-11.3-11.3 1 1 0 0 0-1.3-1.2 10 10 0 1 0 13.7 13.7 1 1 0 0 0-1.1-1.2Z"
            fill="currentColor"
          />
        </svg>
      </span>
    </span>
  </button>
</template>

<style lang="scss">
.theme-toggle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 28px;
  padding: 0;
  border: 1px solid $line;
  border-radius: $radius-pill;
  background: $surface;
  cursor: pointer;
  @include interactive((background, border-color, transform));

  &:hover {
    border-color: $ink;
    transform: translateY(-1px);
  }

  @include focus-ring(rgba($primary, 0.4), 2px);
}

.theme-toggle-track {
  position: relative;
  display: block;
  width: 100%;
  height: 100%;
}

.theme-toggle-thumb {
  position: absolute;
  top: 50%;
  left: 2px;
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: linear-gradient(135deg, #fff, #f1efe7);
  color: $accent-dark;
  box-shadow: 0 4px 8px rgba(20, 24, 34, 0.16);
  transform: translateY(-50%);
  transition:
    left $duration $ease,
    background $duration $ease,
    color $duration $ease;
}

.theme-toggle.is-dark .theme-toggle-thumb {
  left: calc(100% - 24px);
  background: linear-gradient(135deg, #1f2531, #11141b);
  color: #f4f7ff;
}

.theme-toggle-icon {
  width: 14px;
  height: 14px;
}

[data-theme='dark'] .theme-toggle {
  background: rgba(255, 255, 255, 0.04);
  border-color: rgba(255, 255, 255, 0.1);

  &:hover {
    border-color: #f4f7ff;
  }
}
</style>

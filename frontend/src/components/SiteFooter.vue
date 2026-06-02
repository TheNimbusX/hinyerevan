<script setup>
import { useI18n } from '../i18n'
import siteLogo from '../assets/logos/Logo2026.png'

const { t } = useI18n()
const year = new Date().getFullYear()
</script>

<template>
  <footer class="site-footer notranslate">
    <div class="site-footer-inner">
      <div class="footer-brand">
        <img class="footer-logo" :src="siteLogo" alt="HinYerevan" />
        <div>
          <strong>HinYerevan<em>.com</em></strong>
          <p>{{ t('tagline') }}</p>
        </div>
      </div>

      <div class="footer-columns">
        <section class="footer-col">
          <h4>{{ t('about') }}</h4>
          <RouterLink to="/pages/aboutus">{{ t('about') }}</RouterLink>
          <RouterLink to="/pages/faq">{{ t('faq') }}</RouterLink>
          <RouterLink to="/news">{{ t('news') }}</RouterLink>
        </section>

        <section class="footer-col">
          <h4>{{ t('contact') }}</h4>
          <RouterLink to="/pages/feedback">{{ t('feedback') }}</RouterLink>
          <RouterLink to="/pages/privacy">{{ t('privacyPolicy') }}</RouterLink>
          <RouterLink to="/pages/agreement">{{ t('agreement') }}</RouterLink>
        </section>

        <section class="footer-col">
          <h4>{{ t('partners') }}</h4>
          <a href="https://www.haystory.com/" target="_blank" rel="noopener noreferrer">
            HayStory
            <svg viewBox="0 0 12 12" aria-hidden="true"><path d="M4 2h6v6M10 2 3 9" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" /></svg>
          </a>
        </section>
      </div>
    </div>

    <div class="footer-bottom">
      <span>© {{ year }} HinYerevan.com</span>
      <span class="footer-rule"></span>
      <RouterLink to="/pages/privacy">{{ t('privacyPolicy') }}</RouterLink>
      <RouterLink to="/pages/agreement">{{ t('agreement') }}</RouterLink>
    </div>
  </footer>
</template>

<style lang="scss">
.site-footer {
  margin-top: 40px;
  padding: 36px 28px 24px;
  border-top: 1px solid $line;
  background: $surface-soft;

  @include mq-down($bp-md) {
    padding: 28px 16px 20px;
  }
}

.site-footer-inner {
  display: grid;
  grid-template-columns: minmax(220px, 1fr) 2fr;
  gap: 28px;
  max-width: 1280px;
  margin: 0 auto;

  @include mq-down($bp-md) {
    grid-template-columns: 1fr;
    gap: 22px;
  }
}

.footer-brand {
  display: flex;
  align-items: center;
  gap: 14px;

  strong {
    display: block;
    font-family: $font-serif;
    font-size: 20px;
    font-weight: 500;
    letter-spacing: -0.01em;
    color: $ink;

    em {
      font-style: normal;
      font-weight: 400;
      color: inherit;
    }
  }

  p {
    margin: 2px 0 0;
    color: $muted;
    font-size: 12px;
  }
}

.footer-logo {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  object-fit: cover;
  background: #fff;
  box-shadow: $shadow-xs;
}

.footer-columns {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 24px;

  @include mq-down($bp-md) {
    grid-template-columns: 1fr 1fr;
    gap: 18px;
  }

  @include mq-down(420px) {
    grid-template-columns: 1fr;
    gap: 14px;
  }
}

.footer-col {
  display: grid;
  gap: 8px;

  h4 {
    margin: 0 0 4px;
    color: $muted;
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.16em;
    text-transform: uppercase;
  }

  a {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: $ink;
    font-size: 13px;
    text-decoration: none;
    @include interactive((color));

    &:hover {
      color: $primary;
    }

    svg {
      width: 11px;
      height: 11px;
      color: $muted;
      transition: transform $duration $ease;
    }

    &:hover svg {
      color: $primary;
      transform: translate(1px, -1px);
    }
  }
}

.footer-bottom {
  display: flex;
  align-items: center;
  gap: 12px;
  max-width: 1280px;
  margin: 28px auto 0;
  padding-top: 16px;
  border-top: 1px solid $line;
  color: $muted;
  font-size: 12px;

  @include mq-down($bp-sm) {
    flex-wrap: wrap;
    justify-content: center;
    text-align: center;
  }

  a {
    color: $muted;
    text-decoration: none;

    &:hover {
      color: $ink;
    }
  }
}

.footer-rule {
  flex: 1;
  height: 1px;
  background: $line;
}

[data-theme='dark'] .site-footer {
  background: #11151c;
  border-top-color: #2a313d;

  .footer-brand strong {
    color: #f4f7ff;
  }

  .footer-col a {
    color: #d6dae3;

    &:hover {
      color: #fff;
    }
  }

  .footer-col h4 {
    color: #98a0ae;
  }

  .footer-bottom {
    border-top-color: #2a313d;
  }

  .footer-rule {
    background: #2a313d;
  }
}
</style>

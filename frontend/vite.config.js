import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const siteUrl = (env.VITE_SITE_URL || 'https://hinyerevan.com').replace(/\/$/, '')
  const facebookAppId = env.VITE_FACEBOOK_APP_ID || ''

  return {
    plugins: [
      vue(),
      {
        name: 'html-site-url',
        transformIndexHtml(html) {
          let out = html.replaceAll('__SITE_URL__', siteUrl)
          if (facebookAppId) {
            out = out.replaceAll('__FACEBOOK_APP_ID__', facebookAppId)
          } else {
            out = out.replace(/<meta property="fb:app_id" content="__FACEBOOK_APP_ID__" \/>\n?/, '')
          }
          return out
        },
      },
    ],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    css: {
      preprocessorOptions: {
        scss: {
          additionalData: `@use "@/styles/tokens" as *;\n`,
        },
      },
    },
  }
})

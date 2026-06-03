<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()

onMounted(() => {
  router.replace({ path: '/', query: { facebook: '1' } })
})
</script>

<template>
  <p class="muted" style="padding: 24px; text-align: center">…</p>
</template>

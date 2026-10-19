<template>
  <div class="auth-page">
    <div class="tools">
      <LangSwitch />
      <n-button quaternary size="small" circle @click="ui.toggleTheme()">
        <template #icon><n-icon><MoonOutline v-if="!ui.dark" /><SunnyOutline v-else /></n-icon></template>
      </n-button>
    </div>
    <div class="box">
      <div class="brand">
        <img src="/favicon.svg" alt="" />
        <div>
          <div class="title">{{ auth.meta?.site_name || t('app.name') }}</div>
          <div class="muted">{{ t('app.slogan') }}</div>
        </div>
      </div>
      <n-card :bordered="true">
        <slot />
      </n-card>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { MoonOutline, SunnyOutline } from '@vicons/ionicons5'
import LangSwitch from './LangSwitch.vue'
import { useUi } from '../stores/ui'
import { useAuth } from '../stores/auth'

const { t } = useI18n()
const ui = useUi()
const auth = useAuth()
</script>

<style scoped>
.auth-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; position: relative; }
.tools { position: absolute; top: 16px; right: 16px; display: flex; gap: 6px; }
.box { width: 100%; max-width: 380px; }
.brand { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
.brand img { width: 40px; height: 40px; }
.title { font-size: 22px; font-weight: 600; }
</style>

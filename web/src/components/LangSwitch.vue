<template>
  <n-dropdown :options="options" @select="change">
    <n-button quaternary size="small">
      <template #icon><n-icon><LanguageOutline /></n-icon></template>
      {{ current }}
    </n-button>
  </n-dropdown>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { LanguageOutline } from '@vicons/ionicons5'
import { LOCALES, setLocale } from '../i18n'
import { useAuth } from '../stores/auth'
import { api } from '../utils/api'

const { locale } = useI18n()
const auth = useAuth()
const options = LOCALES.map((l) => ({ key: l.value, label: l.label }))
const current = computed(() => LOCALES.find((l) => l.value === locale.value)?.label)

async function change(key) {
  setLocale(key)
  if (auth.user) {
    const u = auth.user
    await api.put('/api/auth/profile', { name: u.name, locale: key, timezone: u.timezone, notify_time: u.notify_time, notify_mode: u.notify_mode || 'smart' }, { silent: true })
    u.locale = key
  }
}
</script>

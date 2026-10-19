<template>
  <n-config-provider :theme="ui.dark ? darkTheme : null" :theme-overrides="overrides" :locale="nLocale" :date-locale="nDateLocale">
    <n-global-style />
    <n-message-provider>
      <n-dialog-provider>
        <router-view />
      </n-dialog-provider>
    </n-message-provider>
  </n-config-provider>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { darkTheme, zhCN, dateZhCN, enUS, dateEnUS } from 'naive-ui'
import { useUi } from './stores/ui'

const ui = useUi()
ui.init()
const { locale } = useI18n()

const nLocale = computed(() => (locale.value === 'zh-cn' ? zhCN : enUS))
const nDateLocale = computed(() => (locale.value === 'zh-cn' ? dateZhCN : dateEnUS))

const overrides = {
  common: { borderRadius: '6px', primaryColor: '#18a058', primaryColorHover: '#36ad6a', primaryColorPressed: '#0c7a43' },
  Card: { borderRadius: '10px' },
}
</script>

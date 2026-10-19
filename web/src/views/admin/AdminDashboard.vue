<template>
  <div class="page">
    <div class="page-header"><h2>{{ t('nav.adminOverview') }}</h2></div>
    <div class="grid-stats">
      <n-card v-for="k in keys" :key="k[0]" size="small">
        <n-statistic :label="t('admin.' + k[1])" :value="data?.[k[0]] ?? 0" />
      </n-card>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../../utils/api'

const { t } = useI18n()
const data = ref(null)
const keys = [
  ['users', 'users'], ['users_7d', 'newUsers'], ['tenants', 'tenants'], ['assets', 'assets'],
  ['tasks', 'tasks'], ['channels', 'channels'], ['sent_24h', 'sent24h'], ['failed_24h', 'failed24h'],
]
onMounted(async () => { data.value = await api.get('/adminapi/stats') })
</script>

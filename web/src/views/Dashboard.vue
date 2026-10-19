<template>
  <div class="page">
    <div class="page-header"><h2>{{ t('nav.dashboard') }}</h2>
      <n-button quaternary size="small" @click="load"><template #icon><n-icon><RefreshOutline /></n-icon></template></n-button>
    </div>

    <n-alert v-if="data && !data.channels" type="warning" :bordered="false" style="margin-bottom:16px">
      {{ t('dashboard.noChannel') }}
      <n-button text type="primary" @click="router.push({ name: 'channels' })">{{ t('dashboard.bindNow') }} →</n-button>
    </n-alert>

    <n-spin :show="loading">
      <div class="grid-stats">
        <n-card size="small" v-for="s in stats" :key="s.key">
          <n-statistic :label="s.label">
            <span :style="{ color: s.color }">{{ data?.stats?.[s.key] ?? 0 }}</span>
          </n-statistic>
        </n-card>
        <n-card size="small">
          <n-statistic :label="t('dashboard.costs')">
            <span v-if="!costList.length">-</span>
            <div v-for="c in costList" :key="c[0]" class="cost">{{ c[1] }} <small>{{ c[0] }}</small></div>
          </n-statistic>
        </n-card>
      </div>

      <div class="grid-2">
        <n-card :title="t('dashboard.upcomingAssets')" size="small">
          <template #header-extra><n-button text type="primary" @click="router.push({ name: 'assets' })">{{ t('common.more') }}</n-button></template>
          <n-empty v-if="!data?.assets?.length" :description="t('dashboard.empty')" />
          <n-list v-else hoverable>
            <n-list-item v-for="a in data.assets" :key="a.id">
              <div class="row">
                <div class="main">
                  <div class="name">{{ a.name }}</div>
                  <div class="muted">{{ t('asset.types.' + a.type) }} · {{ fmtDate(a.expire_time) }}</div>
                </div>
                <DaysTag :days="a.days_left" :muted="a.acked" />
                <n-button size="tiny" secondary @click="renew(a)" :title="t('asset.renewTip')">{{ t('asset.renew') }}</n-button>
              </div>
            </n-list-item>
          </n-list>
        </n-card>

        <n-card :title="t('dashboard.dueTasks')" size="small">
          <template #header-extra><n-button text type="primary" @click="router.push({ name: 'tasks' })">{{ t('common.more') }}</n-button></template>
          <n-empty v-if="!data?.tasks?.length" :description="t('dashboard.empty')" />
          <n-list v-else hoverable>
            <n-list-item v-for="task in data.tasks" :key="task.id">
              <div class="row">
                <div class="main">
                  <div class="name">{{ task.title }}</div>
                  <div class="muted">{{ fmt(task.next_due_time) }}</div>
                </div>
                <n-tag v-if="task.overdue" type="error" size="small" :bordered="false" round>{{ t('task.overdue') }}</n-tag>
                <DaysTag v-else :days="task.days_left" />
                <n-button size="tiny" type="primary" secondary @click="done(task)">{{ t('task.done') }}</n-button>
              </div>
            </n-list-item>
          </n-list>
        </n-card>
      </div>
    </n-spin>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { RefreshOutline } from '@vicons/ionicons5'
import { api, message } from '../utils/api'
import { fmt, fmtDate } from '../utils/time'
import DaysTag from '../components/DaysTag.vue'

const { t } = useI18n()
const router = useRouter()
const data = ref(null)
const loading = ref(false)

const stats = computed(() => [
  { key: 'overdue', label: t('dashboard.overdue'), color: '#d03050' },
  { key: 'in7', label: t('dashboard.in7'), color: '#f0a020' },
  { key: 'in30', label: t('dashboard.in30'), color: '#2080f0' },
  { key: 'tasks_due', label: t('dashboard.tasksDue'), color: '#18a058' },
])
const costList = computed(() => Object.entries(data.value?.costs || {}))

async function load() {
  loading.value = true
  try { data.value = await api.get('/api/dashboard') } finally { loading.value = false }
}
async function renew(a) {
  await api.post(`/api/assets/${a.id}/renew`)
  message.success(t('common.save'))
  load()
}
async function done(task) {
  await api.post(`/api/tasks/${task.id}/done`)
  message.success('👍')
  load()
}
onMounted(load)
</script>

<style scoped>
.row { display: flex; align-items: center; gap: 10px; }
.main { flex: 1; min-width: 0; }
.name { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cost { font-size: 18px; line-height: 1.3; }
.cost small { font-size: 12px; opacity: .6; }
</style>

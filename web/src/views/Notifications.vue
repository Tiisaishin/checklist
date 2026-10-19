<template>
  <div class="page">
    <div class="page-header"><h2>{{ t('notification.title') }}</h2></div>
    <div class="toolbar">
      <n-radio-group v-model:value="status" size="small" @update:value="reload">
        <n-radio-button value="">{{ t('common.all') }}</n-radio-button>
        <n-radio-button value="sent">{{ t('notification.sent') }}</n-radio-button>
        <n-radio-button value="failed">{{ t('notification.failed') }}</n-radio-button>
      </n-radio-group>
    </div>
    <n-data-table remote :columns="columns" :data="rows" :loading="loading" :pagination="pagination" size="small" :scroll-x="760" @update:page="changePage" />
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { NTag, NTooltip } from 'naive-ui'
import { api } from '../utils/api'
import { fmt } from '../utils/time'

const { t } = useI18n()
const rows = ref([])
const loading = ref(false)
const status = ref('')
const pagination = reactive({ page: 1, pageSize: 20, itemCount: 0 })

const columns = computed(() => [
  { title: t('notification.sentAt'), key: 'create_time', width: 150, render: (r) => fmt(r.create_time) },
  { title: t('notification.target'), key: 'target_type', width: 100, render: (r) => ({ asset: t('nav.assets'), task: t('nav.tasks'), digest: t('notification.digest') }[r.target_type] || r.target_type) },
  { title: t('notification.content'), key: 'content', ellipsis: { tooltip: true }, render: (r) => r.content.replace(/<[^>]+>/g, '').replace(/\n+/g, ' · ') },
  {
    title: t('common.status'), key: 'status', width: 100,
    render: (r) => {
      const tag = h(NTag, { size: 'small', bordered: false, type: { sent: 'success', failed: 'error', pending: 'warning', merged: 'default' }[r.status] }, () => t('notification.' + r.status))
      return r.error ? h(NTooltip, null, { trigger: () => tag, default: () => r.error }) : tag
    },
  },
])

async function load() {
  loading.value = true
  try {
    const res = await api.get('/api/notifications', { status: status.value, page: pagination.page, page_size: pagination.pageSize })
    rows.value = res.items
    pagination.itemCount = res.total
  } finally { loading.value = false }
}
function reload() { pagination.page = 1; load() }
function changePage(p) { pagination.page = p; load() }
onMounted(load)
</script>

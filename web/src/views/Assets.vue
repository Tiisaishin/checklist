<template>
  <div class="page">
    <div class="page-header">
      <h2>{{ t('asset.title') }}</h2>
      <n-button type="primary" @click="openForm()" :disabled="!auth.canWrite">
        <template #icon><n-icon><AddOutline /></n-icon></template>{{ t('asset.add') }}
      </n-button>
    </div>

    <div class="toolbar">
      <n-radio-group v-model:value="filters.type" size="small" @update:value="reload">
        <n-radio-button value="">{{ t('common.all') }}</n-radio-button>
        <n-radio-button v-for="tp in types" :key="tp" :value="tp">{{ t('asset.types.' + tp) }}</n-radio-button>
      </n-radio-group>
      <n-select v-model:value="filters.project_id" :options="projectOptions" clearable size="small" style="width: 160px" :placeholder="t('asset.project')" @update:value="reload" />
      <n-input v-model:value="filters.keyword" size="small" clearable style="width: 220px" :placeholder="t('asset.keyword')" @keyup.enter="reload" @clear="reload" />
      <n-select v-model:value="filters.status" size="small" style="width: 110px" :options="statusOptions" @update:value="reload" />
    </div>

    <n-data-table
      remote :columns="columns" :data="rows" :loading="loading" :pagination="pagination" :row-key="(r) => r.id"
      :scroll-x="900" size="small" @update:page="changePage"
    />

    <AssetForm v-model:show="formVisible" :asset="editing" :projects="projects" :channels="channels" @saved="onSaved" @projects-changed="loadProjects" />

    <n-modal v-model:show="detailVisible" preset="card" :title="t('asset.detectInfo')" style="max-width: 560px">
      <n-descriptions v-if="detail" :column="1" label-placement="left" size="small" bordered>
        <n-descriptions-item :label="t('asset.lastDetect')">{{ fmt(detail.last_detect_time) }}</n-descriptions-item>
        <n-descriptions-item v-if="detail.detect_error" :label="t('asset.detectFailed')"><span style="color:#d03050">{{ detail.detect_error }}</span></n-descriptions-item>
        <n-descriptions-item v-for="(v, k) in detail.detect_meta || {}" :key="k" :label="k">
          <span class="mono">{{ Array.isArray(v) ? v.join(', ') : String(v) }}</span>
        </n-descriptions-item>
      </n-descriptions>
    </n-modal>
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { NButton, NDropdown, NIcon, NTag, NTooltip } from 'naive-ui'
import { AddOutline, EllipsisHorizontal, CheckmarkCircleOutline, AlertCircleOutline } from '@vicons/ionicons5'
import { api, confirmDelete, message } from '../utils/api'
import { fmt, fmtDate } from '../utils/time'
import { useAuth } from '../stores/auth'
import DaysTag from '../components/DaysTag.vue'
import AssetForm from '../components/AssetForm.vue'

const { t } = useI18n()
const auth = useAuth()
const types = ['domain', 'ssl', 'server', 'subscription', 'license', 'account', 'other']

const filters = reactive({ type: '', project_id: null, keyword: '', status: 'active' })
const rows = ref([])
const loading = ref(false)
const pagination = reactive({ page: 1, pageSize: 20, itemCount: 0 })
const projects = ref([])
const channels = ref([])
const formVisible = ref(false)
const editing = ref(null)
const detailVisible = ref(false)
const detail = ref(null)

const projectOptions = computed(() => projects.value.map((p) => ({ label: p.name, value: p.id })))
const statusOptions = computed(() => [
  { label: t('common.active'), value: 'active' },
  { label: t('common.archived'), value: 'archived' },
])

const columns = computed(() => [
  {
    title: t('asset.name'), key: 'name', minWidth: 220,
    render: (r) => h('div', [
      h('div', { style: 'font-weight:500' }, r.name),
      r.identifier && r.identifier !== r.name ? h('div', { class: 'muted mono' }, r.identifier) : null,
    ]),
  },
  {
    title: t('asset.type'), key: 'type', width: 120,
    render: (r) => h(NTag, { size: 'small', bordered: false }, () => t('asset.types.' + r.type)),
  },
  {
    title: t('asset.expire'), key: 'expire_time', width: 190,
    render: (r) => h('div', { style: 'display:flex;align-items:center;gap:6px' }, [
      h('span', fmtDate(r.expire_time)),
      h(DaysTag, { days: r.days_left, muted: r.acked }),
      r.auto_detect ? detectIcon(r) : null,
    ]),
  },
  { title: t('asset.provider'), key: 'provider', width: 140, ellipsis: { tooltip: true } },
  {
    title: t('asset.price'), key: 'price', width: 110,
    render: (r) => (r.price ? `${r.price} ${r.currency}` : '-'),
  },
  {
    title: t('common.actions'), key: 'actions', width: 170, fixed: 'right',
    render: (r) => h('div', { style: 'display:flex;gap:6px' }, [
      h(NButton, { size: 'tiny', secondary: true, type: 'primary', onClick: () => renew(r), title: t('asset.renewTip'), disabled: !auth.canWrite }, () => t('asset.renew')),
      h(NButton, { size: 'tiny', secondary: true, onClick: () => openForm(r), disabled: !auth.canWrite }, () => t('common.edit')),
      h(NDropdown, { options: moreOptions(r), onSelect: (k) => onMore(k, r), trigger: 'click' }, () =>
        h(NButton, { size: 'tiny', quaternary: true }, () => h(NIcon, null, () => h(EllipsisHorizontal)))),
    ]),
  },
])

function detectIcon(r) {
  const ok = r.detect_status === 'ok'
  return h(NTooltip, null, {
    trigger: () => h(NIcon, { color: ok ? '#18a058' : r.detect_status ? '#d03050' : '#999', style: 'cursor:pointer', onClick: () => showDetail(r) },
      () => h(ok || !r.detect_status ? CheckmarkCircleOutline : AlertCircleOutline)),
    default: () => (r.detect_error ? r.detect_error : `${t('asset.lastDetect')}: ${fmt(r.last_detect_time)}`),
  })
}

function moreOptions(r) {
  const list = []
  if (['domain', 'ssl'].includes(r.type)) list.push({ key: 'detect', label: t('asset.detect') })
  list.push(
    { key: 'ack', label: t('asset.ack'), disabled: r.acked },
    { key: 'snooze', label: t('task.snooze1d') },
    { key: 'archive', label: r.status === 'active' ? t('common.archived') : t('common.active') },
    { type: 'divider' },
    { key: 'delete', label: t('common.delete') },
  )
  return list
}

async function onMore(key, r) {
  if (key === 'detect') {
    const res = await api.post(`/api/assets/${r.id}/detect`)
    res.detect_status === 'ok' ? message.success(fmtDate(res.expire_time)) : message.error(res.detect_error)
  } else if (key === 'ack') {
    await api.post(`/api/assets/${r.id}/ack`)
  } else if (key === 'snooze') {
    await api.post(`/api/assets/${r.id}/snooze`, { period: '1d' })
  } else if (key === 'archive') {
    await api.put(`/api/assets/${r.id}`, { ...pick(r), status: r.status === 'active' ? 'archived' : 'active' })
  } else if (key === 'delete') {
    return confirmDelete(async () => { await api.del(`/api/assets/${r.id}`); load() })
  }
  load()
}

function pick(r) {
  const { type, name, identifier, url, provider, project_id, expire_time, auto_detect, price, currency, renew_cycle, auto_renew, remind_offsets, remind_overdue, remind_channels, notes } = r
  return { type, name, identifier, url, provider, project_id, expire_time, auto_detect, price, currency, renew_cycle, auto_renew, remind_offsets, remind_overdue, remind_channels: remind_channels || [], notes: notes || '' }
}

async function renew(r) {
  await api.post(`/api/assets/${r.id}/renew`)
  message.success(t('asset.renewTip'))
  load()
}

function showDetail(r) {
  detail.value = r
  detailVisible.value = true
}

function openForm(r = null) {
  editing.value = r
  formVisible.value = true
}

function onSaved() {
  formVisible.value = false
  load()
}

async function load() {
  loading.value = true
  try {
    const res = await api.get('/api/assets', { ...filters, page: pagination.page, page_size: pagination.pageSize })
    rows.value = res.items
    pagination.itemCount = res.total
  } finally {
    loading.value = false
  }
}
function reload() { pagination.page = 1; load() }
function changePage(p) { pagination.page = p; load() }
async function loadProjects() { projects.value = await api.get('/api/projects') }

onMounted(async () => {
  load()
  loadProjects()
  channels.value = (await api.get('/api/channels')).items
})
</script>

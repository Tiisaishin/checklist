<template>
  <div class="page">
    <div class="page-header">
      <h2>{{ t('task.title') }}</h2>
      <n-button type="primary" @click="openForm()" :disabled="!auth.canWrite">
        <template #icon><n-icon><AddOutline /></n-icon></template>{{ t('task.add') }}
      </n-button>
    </div>

    <div class="toolbar">
      <n-radio-group v-model:value="filters.freq" size="small" @update:value="reload">
        <n-radio-button value="">{{ t('common.all') }}</n-radio-button>
        <n-radio-button v-for="f in freqs" :key="f" :value="f">{{ t('task.freqText.' + f) }}</n-radio-button>
      </n-radio-group>
      <n-select v-model:value="filters.project_id" :options="projectOptions" clearable size="small" style="width: 160px" :placeholder="t('asset.project')" @update:value="reload" />
      <n-input v-model:value="filters.keyword" size="small" clearable style="width: 200px" :placeholder="t('common.search')" @keyup.enter="reload" @clear="reload" />
      <n-select v-model:value="filters.status" size="small" style="width: 110px" :options="statusOptions" @update:value="reload" />
    </div>

    <n-data-table remote :columns="columns" :data="rows" :loading="loading" :pagination="pagination" :row-key="(r) => r.id"
      :scroll-x="860" size="small" @update:page="changePage" />

    <TaskForm v-model:show="formVisible" :task="editing" :projects="projects" :channels="channels" @saved="onSaved" @projects-changed="loadProjects" />

    <n-modal v-model:show="doneVisible" preset="dialog" :title="doneTask?.title" :positive-text="t('task.done')" :negative-text="t('common.cancel')" @positive-click="confirmDone">
      <n-checkbox-group v-if="doneTask?.steps?.length" v-model:value="checkedSteps" style="margin: 8px 0 12px">
        <n-space vertical><n-checkbox v-for="(s, i) in doneTask.steps" :key="i" :value="i" :label="s" /></n-space>
      </n-checkbox-group>
      <n-input v-model:value="doneNote" type="textarea" :placeholder="t('task.doneNote')" :autosize="{ minRows: 2 }" />
    </n-modal>

    <n-modal v-model:show="runsVisible" preset="card" :title="t('task.runs')" style="max-width: 640px">
      <n-data-table :columns="runColumns" :data="runs" size="small" :max-height="420" />
    </n-modal>
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { NButton, NDropdown, NIcon, NTag } from 'naive-ui'
import { AddOutline, EllipsisHorizontal } from '@vicons/ionicons5'
import { api, confirmDelete, message } from '../utils/api'
import { fmt } from '../utils/time'
import { useAuth } from '../stores/auth'
import DaysTag from '../components/DaysTag.vue'
import TaskForm from '../components/TaskForm.vue'
import { frequencyText } from '../utils/task'

const { t } = useI18n()
const auth = useAuth()
const freqs = ['daily', 'weekly', 'monthly', 'yearly']
const filters = reactive({ freq: '', project_id: null, keyword: '', status: 'active' })
const rows = ref([])
const loading = ref(false)
const pagination = reactive({ page: 1, pageSize: 20, itemCount: 0 })
const projects = ref([])
const channels = ref([])
const formVisible = ref(false)
const editing = ref(null)
const doneVisible = ref(false)
const doneTask = ref(null)
const doneNote = ref('')
const checkedSteps = ref([])
const runsVisible = ref(false)
const runs = ref([])

const projectOptions = computed(() => projects.value.map((p) => ({ label: p.name, value: p.id })))
const statusOptions = computed(() => [
  { label: t('common.active'), value: 'active' },
  { label: t('common.archived'), value: 'archived' },
])

const columns = computed(() => [
  {
    title: t('task.name'), key: 'title', minWidth: 220,
    render: (r) => h('div', [
      h('div', { style: 'font-weight:500' }, r.title),
      r.steps?.length ? h('div', { class: 'muted' }, `${r.steps.length} ${t('task.steps')}`) : null,
    ]),
  },
  { title: t('task.freq'), key: 'freq', width: 170, render: (r) => frequencyText(r, t) },
  {
    title: t('task.next'), key: 'next_due_time', width: 210,
    render: (r) => h('div', { style: 'display:flex;gap:6px;align-items:center' }, [
      h('span', fmt(r.next_due_time)),
      r.overdue ? h(NTag, { type: 'error', size: 'small', bordered: false, round: true }, () => t('task.overdue')) : h(DaysTag, { days: r.days_left }),
      r.snoozed ? h(NTag, { size: 'small', bordered: false, round: true }, () => t('task.snoozed')) : null,
    ]),
  },
  { title: t('task.lastDone'), key: 'last_done_time', width: 150, render: (r) => fmt(r.last_done_time) },
  {
    title: t('common.actions'), key: 'actions', width: 160, fixed: 'right',
    render: (r) => h('div', { style: 'display:flex;gap:6px' }, [
      h(NButton, { size: 'tiny', type: 'primary', secondary: true, onClick: () => openDone(r), disabled: !auth.canWrite }, () => t('task.done')),
      h(NButton, { size: 'tiny', secondary: true, onClick: () => openForm(r), disabled: !auth.canWrite }, () => t('common.edit')),
      h(NDropdown, { options: moreOptions(r), onSelect: (k) => onMore(k, r), trigger: 'click' }, () =>
        h(NButton, { size: 'tiny', quaternary: true }, () => h(NIcon, null, () => h(EllipsisHorizontal)))),
    ]),
  },
])

const runColumns = computed(() => [
  { title: t('task.lastDone'), key: 'done_time', width: 150, render: (r) => fmt(r.done_time) },
  { title: t('task.next'), key: 'due_time', width: 150, render: (r) => fmt(r.due_time) },
  { title: t('auth.name'), key: 'user', render: (r) => r.user?.name || r.user?.email || '-' },
  { title: t('task.source'), key: 'source', width: 90 },
  { title: t('common.notes'), key: 'note', ellipsis: { tooltip: true } },
])

function moreOptions(r) {
  return [
    { key: 'runs', label: t('task.runs') },
    { key: 'snooze1h', label: t('task.snooze1h') },
    { key: 'snooze1d', label: t('task.snooze1d') },
    { key: 'archive', label: r.status === 'active' ? t('common.archived') : t('common.active') },
    { type: 'divider' },
    { key: 'delete', label: t('common.delete') },
  ]
}

async function onMore(key, r) {
  if (key === 'runs') {
    runs.value = (await api.get(`/api/tasks/${r.id}/runs`, { page_size: 100 })).items
    runsVisible.value = true
    return
  }
  if (key === 'snooze1h' || key === 'snooze1d') await api.post(`/api/tasks/${r.id}/snooze`, { period: key === 'snooze1h' ? '1h' : '1d' })
  else if (key === 'archive') await api.put(`/api/tasks/${r.id}`, { title: r.title, freq: r.freq, status: r.status === 'active' ? 'archived' : 'active' })
  else if (key === 'delete') return confirmDelete(async () => { await api.del(`/api/tasks/${r.id}`); load() })
  load()
}

function openDone(r) {
  doneTask.value = r
  doneNote.value = ''
  checkedSteps.value = []
  doneVisible.value = true
}

async function confirmDone() {
  const r = doneTask.value
  let note = doneNote.value
  if (r.steps?.length) {
    const lines = r.steps.map((s, i) => `${checkedSteps.value.includes(i) ? '☑' : '☐'} ${s}`)
    note = [lines.join('\n'), note].filter(Boolean).join('\n')
  }
  await api.post(`/api/tasks/${r.id}/done`, { note })
  message.success('👍')
  load()
}

function openForm(r = null) { editing.value = r; formVisible.value = true }
function onSaved() { formVisible.value = false; load() }

async function load() {
  loading.value = true
  try {
    const res = await api.get('/api/tasks', { ...filters, page: pagination.page, page_size: pagination.pageSize })
    rows.value = res.items
    pagination.itemCount = res.total
  } finally { loading.value = false }
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

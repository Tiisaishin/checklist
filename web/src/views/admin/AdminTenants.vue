<template>
  <div class="page">
    <div class="page-header"><h2>{{ t('nav.adminTenants') }}</h2></div>
    <div class="toolbar">
      <n-input v-model:value="keyword" size="small" clearable style="width: 240px" :placeholder="t('common.search')" @keyup.enter="reload" @clear="reload" />
    </div>
    <n-data-table remote :columns="columns" :data="rows" :loading="loading" :pagination="pagination" size="small" :scroll-x="900" @update:page="changePage" />

    <n-modal v-model:show="editVisible" preset="card" :title="editing?.name" style="max-width: 420px">
      <n-form label-placement="top" size="small">
        <n-form-item :label="t('admin.plan')"><n-select v-model:value="form.plan_id" :options="planOptions" /></n-form-item>
        <n-form-item :label="t('admin.planExpire')">
          <n-date-picker v-model:formatted-value="form.plan_expire_local" value-format="yyyy-MM-dd HH:mm" type="datetime" clearable style="width:100%" />
        </n-form-item>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="editVisible = false">{{ t('common.cancel') }}</n-button>
          <n-button type="primary" @click="save">{{ t('common.save') }}</n-button>
        </n-space>
      </template>
    </n-modal>
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { NButton, NSwitch } from 'naive-ui'
import { api } from '../../utils/api'
import { fmt, toLocalInput, toServer } from '../../utils/time'

const { t } = useI18n()
const rows = ref([])
const plans = ref([])
const loading = ref(false)
const keyword = ref('')
const pagination = reactive({ page: 1, pageSize: 20, itemCount: 0 })
const editVisible = ref(false)
const editing = ref(null)
const form = reactive({ plan_id: null, plan_expire_local: null })

const planOptions = computed(() => plans.value.map((p) => ({ label: p.name, value: p.id })))
const planName = (id) => plans.value.find((p) => p.id === id)?.name || '-'

const columns = computed(() => [
  { title: 'ID', key: 'id', width: 70 },
  { title: t('common.name'), key: 'name', minWidth: 180 },
  { title: t('admin.owner'), key: 'owner', width: 200, render: (r) => r.owner?.email || '-' },
  { title: t('admin.plan'), key: 'plan_id', width: 100, render: (r) => planName(r.plan_id) },
  { title: t('admin.planExpire'), key: 'plan_expire_time', width: 150, render: (r) => fmt(r.plan_expire_time) },
  {
    title: t('settings.usage'), key: 'usage', width: 240,
    render: (r) => `${t('settings.domains')} ${r.usage.domains} · SSL ${r.usage.ssl} · ${t('settings.assets')} ${r.usage.assets}`,
  },
  {
    title: t('common.status'), key: 'status', width: 80,
    render: (r) => h(NSwitch, { size: 'small', value: r.status, onUpdateValue: async (v) => { await api.put(`/adminapi/tenants/${r.id}`, { status: v }); load() } }),
  },
  {
    title: t('common.actions'), key: 'actions', width: 90,
    render: (r) => h(NButton, { size: 'tiny', secondary: true, onClick: () => edit(r) }, () => t('common.edit')),
  },
])

function edit(r) {
  editing.value = r
  form.plan_id = r.plan_id
  form.plan_expire_local = toLocalInput(r.plan_expire_time)
  editVisible.value = true
}
async function save() {
  await api.put(`/adminapi/tenants/${editing.value.id}`, { plan_id: form.plan_id, plan_expire_time: toServer(form.plan_expire_local) || '' })
  editVisible.value = false
  load()
}
async function load() {
  loading.value = true
  try {
    const res = await api.get('/adminapi/tenants', { keyword: keyword.value, page: pagination.page, page_size: pagination.pageSize })
    rows.value = res.items
    pagination.itemCount = res.total
  } finally { loading.value = false }
}
function reload() { pagination.page = 1; load() }
function changePage(p) { pagination.page = p; load() }
onMounted(async () => { plans.value = await api.get('/adminapi/plans'); load() })
</script>

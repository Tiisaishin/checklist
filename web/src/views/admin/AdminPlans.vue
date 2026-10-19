<template>
  <div class="page">
    <div class="page-header">
      <h2>{{ t('nav.adminPlans') }}</h2>
      <n-button type="primary" @click="edit()">{{ t('common.create') }}</n-button>
    </div>
    <n-data-table :columns="columns" :data="rows" :loading="loading" size="small" :scroll-x="900" />

    <n-modal v-model:show="visible" preset="card" :title="form.id ? t('common.edit') : t('common.create')" style="max-width: 560px">
      <n-form :model="form" label-placement="top" size="small">
        <n-grid :cols="2" :x-gap="12">
          <n-gi><n-form-item :label="t('admin.code')"><n-input v-model:value="form.code" /></n-form-item></n-gi>
          <n-gi><n-form-item :label="t('common.name')"><n-input v-model:value="form.name" /></n-form-item></n-gi>
          <n-gi v-for="k in limits" :key="k[0]">
            <n-form-item :label="t('admin.' + k[1])"><n-input-number v-model:value="form[k[0]]" :min="-1" style="width:100%" /></n-form-item>
          </n-gi>
          <n-gi><n-form-item :label="t('admin.detectInterval')"><n-input-number v-model:value="form.detect_interval" :min="30" style="width:100%" /></n-form-item></n-gi>
          <n-gi><n-form-item :label="t('admin.sort')"><n-input-number v-model:value="form.sort" style="width:100%" /></n-form-item></n-gi>
          <n-gi><n-form-item :label="t('admin.priceMonth')"><n-input-number v-model:value="form.price_month" :min="0" :precision="2" style="width:100%" /></n-form-item></n-gi>
          <n-gi><n-form-item :label="t('admin.priceYear')"><n-input-number v-model:value="form.price_year" :min="0" :precision="2" style="width:100%" /></n-form-item></n-gi>
          <n-gi><n-form-item :label="t('asset.currency')"><n-input v-model:value="form.currency" /></n-form-item></n-gi>
        </n-grid>
        <n-space>
          <n-checkbox v-model:checked="form.is_default">{{ t('admin.isDefault') }}</n-checkbox>
          <n-checkbox v-model:checked="form.status">{{ t('common.enabled') }}</n-checkbox>
        </n-space>
        <div class="muted" style="margin-top: 8px">{{ t('admin.unlimitedHint') }}</div>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="visible = false">{{ t('common.cancel') }}</n-button>
          <n-button type="primary" @click="save">{{ t('common.save') }}</n-button>
        </n-space>
      </template>
    </n-modal>
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { NButton, NTag } from 'naive-ui'
import { api, confirmDelete } from '../../utils/api'

const { t } = useI18n()
const rows = ref([])
const loading = ref(false)
const visible = ref(false)
const limits = [['max_assets', 'maxAssets'], ['max_tasks', 'maxTasks'], ['max_channels', 'maxChannels'], ['max_members', 'maxMembers']]
const empty = () => ({ id: null, code: '', name: '', max_assets: 20, max_tasks: 20, max_channels: 2, max_members: 1, detect_interval: 1440, price_month: 0, price_year: 0, currency: 'USD', is_default: false, status: true, sort: 0 })
const form = reactive(empty())
const lim = (v) => (v < 0 ? '∞' : v)

const columns = computed(() => [
  { title: t('admin.code'), key: 'code', width: 90 },
  { title: t('common.name'), key: 'name', width: 120, render: (r) => [r.name, r.is_default ? h(NTag, { size: 'tiny', type: 'success', bordered: false, style: 'margin-left:6px' }, () => t('common.default')) : null] },
  ...limits.map(([k, l]) => ({ title: t('admin.' + l), key: k, width: 100, render: (r) => lim(r[k]) })),
  { title: t('admin.detectInterval'), key: 'detect_interval', width: 130 },
  { title: t('admin.priceMonth'), key: 'price_month', width: 90, render: (r) => `${r.price_month} ${r.currency}` },
  { title: t('admin.priceYear'), key: 'price_year', width: 90, render: (r) => `${r.price_year} ${r.currency}` },
  {
    title: t('common.actions'), key: 'actions', width: 130,
    render: (r) => h('div', { style: 'display:flex;gap:6px' }, [
      h(NButton, { size: 'tiny', secondary: true, onClick: () => edit(r) }, () => t('common.edit')),
      h(NButton, { size: 'tiny', quaternary: true, type: 'error', disabled: r.is_default, onClick: () => confirmDelete(async () => { await api.del(`/adminapi/plans/${r.id}`); load() }) }, () => t('common.delete')),
    ]),
  },
])

function edit(r = null) {
  Object.assign(form, empty(), r || {})
  visible.value = true
}
async function save() {
  const { id, ...payload } = form
  if (id) await api.put(`/adminapi/plans/${id}`, payload)
  else await api.post('/adminapi/plans', payload)
  visible.value = false
  load()
}
async function load() {
  loading.value = true
  try { rows.value = await api.get('/adminapi/plans') } finally { loading.value = false }
}
onMounted(load)
</script>

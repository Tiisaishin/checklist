<template>
  <div class="page">
    <div class="page-header"><h2>{{ t('nav.adminUsers') }}</h2></div>
    <div class="toolbar">
      <n-input v-model:value="keyword" size="small" clearable style="width: 240px" :placeholder="t('common.search')" @keyup.enter="reload" @clear="reload" />
    </div>
    <n-data-table remote :columns="columns" :data="rows" :loading="loading" :pagination="pagination" size="small" :scroll-x="820" @update:page="changePage" />

    <n-modal v-model:show="pwdVisible" preset="dialog" :title="t('admin.resetPassword')" :positive-text="t('common.save')" :negative-text="t('common.cancel')" @positive-click="savePassword">
      <n-input v-model:value="newPassword" type="password" show-password-on="click" :placeholder="t('auth.passwordHint')" />
    </n-modal>
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { NButton, NSwitch, NTag } from 'naive-ui'
import { api, message } from '../../utils/api'
import { fmt } from '../../utils/time'
import { useAuth } from '../../stores/auth'

const { t } = useI18n()
const auth = useAuth()
const rows = ref([])
const loading = ref(false)
const keyword = ref('')
const pagination = reactive({ page: 1, pageSize: 20, itemCount: 0 })
const pwdVisible = ref(false)
const newPassword = ref('')
const pwdUser = ref(null)

const columns = computed(() => [
  { title: 'ID', key: 'id', width: 70 },
  { title: t('admin.email'), key: 'email', minWidth: 200 },
  { title: t('auth.name'), key: 'name', width: 140 },
  { title: t('settings.locale'), key: 'locale', width: 80 },
  { title: t('admin.lastLogin'), key: 'last_login_time', width: 150, render: (r) => fmt(r.last_login_time) },
  { title: t('admin.createdAt'), key: 'create_time', width: 150, render: (r) => fmt(r.create_time) },
  {
    title: t('admin.super'), key: 'is_super', width: 80,
    render: (r) => (r.is_super ? h(NTag, { size: 'small', type: 'warning', bordered: false }, () => 'Super') : ''),
  },
  {
    title: t('common.status'), key: 'status', width: 80,
    render: (r) => h(NSwitch, { size: 'small', value: r.status, disabled: r.id === auth.user?.id, onUpdateValue: (v) => update(r, { status: v }) }),
  },
  {
    title: t('common.actions'), key: 'actions', width: 110,
    render: (r) => h(NButton, { size: 'tiny', secondary: true, onClick: () => { pwdUser.value = r; newPassword.value = ''; pwdVisible.value = true } }, () => t('admin.resetPassword')),
  },
])

async function update(r, data) {
  await api.put(`/adminapi/users/${r.id}`, data)
  load()
}
async function savePassword() {
  await api.put(`/adminapi/users/${pwdUser.value.id}`, { password: newPassword.value })
  message.success(t('common.save'))
}
async function load() {
  loading.value = true
  try {
    const res = await api.get('/adminapi/users', { keyword: keyword.value, page: pagination.page, page_size: pagination.pageSize })
    rows.value = res.items
    pagination.itemCount = res.total
  } finally { loading.value = false }
}
function reload() { pagination.page = 1; load() }
function changePage(p) { pagination.page = p; load() }
onMounted(load)
</script>

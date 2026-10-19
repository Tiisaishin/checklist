<template>
  <div class="page">
    <div class="page-header">
      <h2>{{ t('vault.title') }}</h2>
      <n-space>
        <n-button v-if="unlockRemaining" secondary type="success" @click="lock">
          <template #icon><n-icon><LockOpenOutline /></n-icon></template>
          {{ t('vault.unlocked', { time: fmtRemaining(unlockRemaining) }) }} · {{ t('vault.lock') }}
        </n-button>
        <n-button v-else-if="canReveal" secondary @click="unlockVisible = true">
          <template #icon><n-icon><LockClosedOutline /></n-icon></template>{{ t('vault.unlock') }}
        </n-button>
        <n-button type="primary" :disabled="!auth.canWrite" @click="openForm()">
          <template #icon><n-icon><AddOutline /></n-icon></template>{{ t('vault.add') }}
        </n-button>
      </n-space>
    </div>
    <n-alert type="info" :bordered="false" style="margin-bottom: 16px">{{ t('vault.desc') }}</n-alert>

    <div class="toolbar">
      <n-radio-group v-model:value="filters.type" size="small" @update:value="reload">
        <n-radio-button value="">{{ t('common.all') }}</n-radio-button>
        <n-radio-button v-for="tp in types" :key="tp" :value="tp">{{ t('vault.types.' + tp) }}</n-radio-button>
      </n-radio-group>
      <n-select v-model:value="filters.group_id" :options="groupOptions" clearable size="small" style="width: 160px" :placeholder="t('asset.group')" @update:value="reload" />
      <n-input v-model:value="filters.keyword" size="small" clearable style="width: 220px" :placeholder="t('common.search')" @keyup.enter="reload" @clear="reload" />
    </div>

    <n-data-table remote :columns="columns" :data="rows" :loading="loading" :pagination="pagination" :row-key="(r) => r.id"
      :scroll-x="980" size="small" :row-props="rowProps" @update:page="changePage" />

    <VaultForm v-model:show="formVisible" :item="editing" :groups="groups" @saved="onSaved" />
    <VaultDetail v-model:show="detailVisible" :item="viewing" :can-reveal="canReveal" @edit="openForm" />

    <n-modal :show="unlockVisible" preset="card" :title="t('vault.unlockTitle')" style="max-width: 400px" @update:show="(v) => !v && cancelUnlock()">
      <p class="muted" style="margin-top: 0">{{ t('vault.unlockTip') }}</p>
      <n-input ref="pwdRef" v-model:value="password" type="password" show-password-on="click" :placeholder="t('auth.password')" @keyup.enter="doUnlock" />
      <template #action>
        <n-space justify="end">
          <n-button @click="cancelUnlock">{{ t('common.cancel') }}</n-button>
          <n-button type="primary" :loading="unlocking" @click="doUnlock">{{ t('vault.unlock') }}</n-button>
        </n-space>
      </template>
    </n-modal>
  </div>
</template>

<script setup>
import { computed, h, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { NButton, NDropdown, NIcon, NTag, NTooltip } from 'naive-ui'
import { AddOutline, LockClosedOutline, LockOpenOutline, CopyOutline, KeyOutline, EyeOffOutline, EllipsisHorizontal } from '@vicons/ionicons5'
import { api, confirmDelete } from '../utils/api'
import { fmt } from '../utils/time'
import { useAuth } from '../stores/auth'
import { unlockRemaining, unlockVisible, setUnlocked, unlock, cancelUnlock, lock, copySecret, fmtRemaining } from '../utils/vault'
import GroupTag from '../components/GroupTag.vue'
import VaultForm from '../components/VaultForm.vue'
import VaultDetail from '../components/VaultDetail.vue'

const { t } = useI18n()
const auth = useAuth()
const types = ['login', 'api_key', 'server', 'note', 'other']
const filters = reactive({ type: '', group_id: null, keyword: '' })
const rows = ref([])
const groups = ref([])
const loading = ref(false)
const canReveal = ref(true)
const pagination = reactive({ page: 1, pageSize: 20, itemCount: 0 })
const formVisible = ref(false)
const editing = ref(null)
const detailVisible = ref(false)
const viewing = ref(null)
const password = ref('')
const unlocking = ref(false)
const pwdRef = ref()

const groupOptions = computed(() => groups.value.map((g) => ({ label: g.name, value: g.id })))

watch(unlockVisible, async (v) => {
  if (v) { password.value = ''; await nextTick(); pwdRef.value?.focus() }
})

const columns = computed(() => [
  {
    title: t('vault.name'), key: 'title', minWidth: 220,
    render: (r) => h('div', { style: 'display:flex;align-items:center;gap:8px' }, [
      h('div', { style: 'min-width:0' }, [
        h('div', { style: 'font-weight:500' }, [
          r.title,
          r.visibility === 'private' ? h(NIcon, { style: 'margin-left:6px;opacity:.6', title: t('vault.private') }, () => h(EyeOffOutline)) : null,
        ]),
        r.url ? h('div', { class: 'muted', style: 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:280px' }, r.url) : null,
      ]),
    ]),
  },
  { title: t('vault.type'), key: 'type', width: 110, render: (r) => h(NTag, { size: 'small', bordered: false }, () => t('vault.types.' + r.type)) },
  { title: t('asset.group'), key: 'group_id', width: 130, render: (r) => h(GroupTag, { group: groups.value.find((g) => g.id === r.group_id) }) },
  {
    title: t('vault.username'), key: 'username', width: 200,
    render: (r) => (r.username
      ? h('div', { style: 'display:flex;align-items:center;gap:4px' }, [
        h('span', { class: 'mono', style: 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px' }, r.username),
        h(NButton, { size: 'tiny', quaternary: true, onClick: (e) => { e.stopPropagation(); copySecret(r.id, 'username', r.username) } }, () => h(NIcon, null, () => h(CopyOutline))),
      ])
      : '-'),
  },
  {
    title: '2FA', key: 'has_totp', width: 60,
    render: (r) => (r.has_totp ? h(NTooltip, null, { trigger: () => h(NIcon, { color: '#18a058' }, () => h(KeyOutline)), default: () => t('vault.totp') }) : ''),
  },
  { title: t('vault.updated'), key: 'update_time', width: 150, render: (r) => fmt(r.update_time) },
  {
    title: t('common.actions'), key: 'actions', width: 150, fixed: 'right',
    render: (r) => h('div', { style: 'display:flex;gap:6px', onClick: (e) => e.stopPropagation() }, [
      h(NButton, { size: 'tiny', secondary: true, type: 'primary', onClick: () => openDetail(r) }, () => t('vault.detail')),
      h(NButton, { size: 'tiny', secondary: true, disabled: !r.can_edit, onClick: () => openForm(r) }, () => t('common.edit')),
      h(NDropdown, {
        trigger: 'click',
        options: [{ key: 'delete', label: t('common.delete'), disabled: !r.can_edit }],
        onSelect: () => confirmDelete(async () => { await api.del(`/api/vault/${r.id}`); load() }),
      }, () => h(NButton, { size: 'tiny', quaternary: true }, () => h(NIcon, null, () => h(EllipsisHorizontal)))),
    ]),
  },
])

const rowProps = (r) => ({ style: 'cursor:pointer', onClick: () => openDetail(r) })

async function load() {
  loading.value = true
  try {
    const res = await api.get('/api/vault', { ...filters, page: pagination.page, page_size: pagination.pageSize })
    rows.value = res.items
    pagination.itemCount = res.total
    canReveal.value = res.can_reveal
    setUnlocked(res.unlocked)
  } finally {
    loading.value = false
  }
}
function reload() { pagination.page = 1; load() }
function changePage(p) { pagination.page = p; load() }

function openForm(r = null) {
  detailVisible.value = false
  editing.value = r
  formVisible.value = true
}
function openDetail(r) {
  viewing.value = r
  detailVisible.value = true
}
function onSaved() {
  formVisible.value = false
  load()
}

async function doUnlock() {
  if (!password.value) return
  unlocking.value = true
  try { await unlock(password.value) } finally { unlocking.value = false }
}

onMounted(async () => {
  load()
  groups.value = await api.get('/api/groups')
})
</script>

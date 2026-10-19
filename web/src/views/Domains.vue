<template>
  <div class="page">
    <div class="page-header">
      <h2>{{ t('domain.title') }}</h2>
      <n-space>
        <n-button @click="exportCsv"><template #icon><n-icon><DownloadOutline /></n-icon></template>{{ t('importer.export') }}</n-button>
        <n-button type="primary" :disabled="!auth.canWrite" @click="importVisible = true">
          <template #icon><n-icon><CloudUploadOutline /></n-icon></template>{{ t('importer.button') }}
        </n-button>
      </n-space>
    </div>

    <div class="grid-stats">
      <n-card v-for="s in statCards" :key="s.key" size="small" hoverable class="stat" @click="applyStat(s)">
        <n-statistic :label="t('domain.stats.' + s.key)">
          <span :style="{ color: s.color }">{{ stats[s.key] ?? 0 }}</span>
        </n-statistic>
      </n-card>
    </div>

    <n-tabs v-model:value="kind" type="line" @update:value="reload">
      <n-tab name="domain">{{ t('domain.tabs.domain') }}</n-tab>
      <n-tab name="ssl">{{ t('domain.tabs.ssl') }} <n-badge v-if="stats.orphan_certs" :value="stats.orphan_certs" :max="999" type="info" style="margin-left: 6px" /></n-tab>
    </n-tabs>

    <div class="toolbar" style="margin-top: 12px">
      <n-radio-group v-model:value="filters.status" size="small" @update:value="reload">
        <n-radio-button v-for="f in ['all', 'soon', 'expired', 'abnormal']" :key="f" :value="f">{{ t('domain.filters.' + f) }}</n-radio-button>
      </n-radio-group>
      <n-select v-model:value="filters.group_id" :options="groupOptions" clearable size="small" style="width: 160px" :placeholder="t('asset.group')" @update:value="reload" />
      <n-input v-model:value="filters.keyword" size="small" clearable style="width: 220px" :placeholder="t('common.search')" @keyup.enter="reload" @clear="reload" />
      <n-checkbox v-model:checked="filters.archived" size="small" @update:checked="reload">{{ t('domain.archived') }}</n-checkbox>
    </div>

    <!-- 批量操作栏 -->
    <div v-if="checked.length" class="batch">
      <span>{{ t('domain.selected', { n: checked.length }) }}</span>
      <n-checkbox v-if="kind === 'domain'" v-model:checked="withCerts" size="small">{{ t('domain.withCerts') }}</n-checkbox>
      <span class="spacer" />
      <n-button size="small" @click="batch('detect')">{{ t('domain.batch.detect') }}</n-button>
      <n-button size="small" @click="openBatch('group')">{{ t('domain.batch.group') }}</n-button>
      <n-button size="small" @click="openBatch('offsets')">{{ t('domain.batch.offsets') }}</n-button>
      <n-dropdown :options="moreBatch" @select="(k) => batch(k)">
        <n-button size="small">{{ t('common.more') }}</n-button>
      </n-dropdown>
    </div>

    <n-data-table
      remote :columns="columns" :data="rows" :loading="loading" :pagination="pagination" :row-key="(r) => r.id"
      v-model:checked-row-keys="checked" :scroll-x="1100" size="small" @update:page="changePage"
    />

    <ImportWizard v-model:show="importVisible" :groups="groups" @done="reloadAll" />
    <AssetDetail v-model:show="detailVisible" :asset="viewing" @edit="edit" @changed="reloadAll" />
    <AssetForm v-model:show="formVisible" :asset="editing" :groups="groups" :channels="channels" @saved="onSaved" @groups-changed="loadGroups" />

    <n-modal v-model:show="batchVisible" preset="dialog" :title="t('domain.batch.' + batchAction)" :positive-text="t('common.save')" :negative-text="t('common.cancel')" @positive-click="submitBatch">
      <n-select v-if="batchAction === 'group'" v-model:value="batchValue" :options="groupOptions" />
      <n-select v-else v-model:value="batchOffsets" multiple tag filterable :options="offsetOptions" />
    </n-modal>

    <n-modal v-model:show="certVisible" preset="dialog" :title="t('domain.addCert')" :positive-text="t('common.save')" :negative-text="t('common.cancel')" @positive-click="addCert">
      <n-input v-model:value="certHost" :placeholder="t('domain.addCertHint')" class="mono" />
    </n-modal>
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { NButton, NDropdown, NIcon, NTag, NTooltip } from 'naive-ui'
import { DownloadOutline, CloudUploadOutline, EllipsisHorizontal, AlertCircleOutline, ShieldCheckmarkOutline } from '@vicons/ionicons5'
import { api, confirmDelete, download, message } from '../utils/api'
import { fmt, fmtDate } from '../utils/time'
import { useAuth } from '../stores/auth'
import DaysTag from '../components/DaysTag.vue'
import GroupTag from '../components/GroupTag.vue'
import ImportWizard from '../components/ImportWizard.vue'
import AssetDetail from '../components/AssetDetail.vue'
import AssetForm from '../components/AssetForm.vue'

const { t } = useI18n()
const auth = useAuth()
const route = useRoute()

const kind = ref('domain')
const filters = reactive({ status: route.query.status || 'all', group_id: Number(route.query.group_id) || null, keyword: '', archived: false })
const rows = ref([])
const stats = ref({})
const groups = ref([])
const channels = ref([])
const loading = ref(false)
const pagination = reactive({ page: 1, pageSize: 20, itemCount: 0 })
const checked = ref([])
const withCerts = ref(true)
const importVisible = ref(false)
const detailVisible = ref(false)
const viewing = ref(null)
const formVisible = ref(false)
const editing = ref(null)
const batchVisible = ref(false)
const batchAction = ref('group')
const batchValue = ref(null)
const batchOffsets = ref([])
const certVisible = ref(false)
const certHost = ref('')
const certParent = ref(null)

const groupOptions = computed(() => groups.value.map((g) => ({ label: g.name, value: g.id })))
const offsetOptions = ['90', '60', '30', '14', '7', '3', '1', '0'].map((v) => ({ value: v, label: v }))
const statCards = [
  { key: 'domains', color: '', kind: 'domain', status: 'all' },
  { key: 'domains_soon', color: '#f0a020', kind: 'domain', status: 'soon' },
  { key: 'domains_expired', color: '#d03050', kind: 'domain', status: 'expired' },
  { key: 'certs', color: '', kind: 'ssl', status: 'all' },
  { key: 'certs_soon', color: '#f0a020', kind: 'domain', status: 'all' },
  { key: 'abnormal', color: '#d03050', kind: 'domain', status: 'abnormal' },
]
const moreBatch = computed(() => [
  { key: 'enableDetect', label: t('domain.batch.enableDetect') },
  { key: 'disableDetect', label: t('domain.batch.disableDetect') },
  filters.archived ? { key: 'activate', label: t('domain.batch.activate') } : { key: 'archive', label: t('domain.batch.archive') },
  { type: 'divider' },
  { key: 'delete', label: t('domain.batch.delete') },
])

function certTag(c) {
  const days = c.days_left
  const type = c.issue || c.detect_status === 'failed' || (days !== null && days < 0) ? 'error' : days !== null && days <= 14 ? 'warning' : c.expire_time ? 'success' : 'default'
  return h(NTooltip, null, {
    trigger: () => h(NTag, { size: 'small', type, bordered: false, style: 'cursor:pointer', onClick: (e) => { e.stopPropagation(); open(c) } }, () => [
      c.name, days !== null ? ` · ${days}d` : ' · …',
    ]),
    default: () => [
      `${c.provider || ''} ${c.expire_time ? '→ ' + fmtDate(c.expire_time) : ''}`,
      c.issue ? ` · ${t('asset.issues.' + c.issue)}` : '',
      c.detect_status === 'failed' ? ` · ${c.detect_error}` : '',
    ].join(''),
  })
}

function statusIcon(r) {
  if (r.detect_status === 'failed') {
    return h(NTooltip, null, { trigger: () => h(NIcon, { color: '#d03050' }, () => h(AlertCircleOutline)), default: () => r.detect_error })
  }
  if (r.detect_status === 'ok') {
    return h(NTooltip, null, { trigger: () => h(NIcon, { color: '#18a058' }, () => h(ShieldCheckmarkOutline)), default: () => `${t('asset.lastDetect')}: ${fmt(r.last_detect_time)}` })
  }
  return h('span', { class: 'muted' }, r.auto_detect ? '…' : '-')
}

const columns = computed(() => {
  const list = [
    { type: 'selection', fixed: 'left' },
    {
      title: kind.value === 'domain' ? t('importer.fields.domain') : t('vault.url'), key: 'name', minWidth: 200, fixed: 'left',
      render: (r) => h('div', { style: 'cursor:pointer', onClick: () => open(r) }, [
        h('div', { style: 'font-weight:500' }, r.name),
        r.provider ? h('div', { class: 'muted' }, r.provider) : null,
      ]),
    },
    {
      title: t('asset.expire'), key: 'expire_time', width: 230,
      render: (r) => h('div', { style: 'display:flex;gap:6px;align-items:center;flex-wrap:wrap' }, [
        h('span', fmtDate(r.expire_time)),
        h(DaysTag, { days: r.days_left, muted: r.acked }),
        r.phase && r.phase !== 'active' ? h(NTag, { size: 'small', type: 'error', round: true, bordered: false }, () => t('asset.phases.' + r.phase)) : null,
        r.issue ? h(NTag, { size: 'small', type: 'error', round: true, bordered: false }, () => t('asset.issues.' + r.issue)) : null,
      ]),
    },
  ]
  if (kind.value === 'domain') {
    list.push(
      {
        title: t('domain.certs'), key: 'certs', minWidth: 260,
        render: (r) => h('div', { style: 'display:flex;gap:4px;flex-wrap:wrap;align-items:center' }, [
          ...r.certs.map(certTag),
          auth.canWrite ? h(NButton, { size: 'tiny', quaternary: true, onClick: () => { certParent.value = r; certHost.value = ''; certVisible.value = true } }, () => '+') : null,
        ]),
      },
      {
        title: t('domain.dns'), key: 'dns', width: 170, ellipsis: { tooltip: true },
        render: (r) => h('span', { class: 'mono muted' }, ((r.detect_meta || {}).nameservers || []).slice(0, 2).join(', ') || '-'),
      },
    )
  } else {
    list.push({ title: t('domain.issuer'), key: 'provider', width: 180, ellipsis: { tooltip: true } })
  }
  list.push(
    { title: t('asset.group'), key: 'group_id', width: 120, render: (r) => h(GroupTag, { group: groups.value.find((g) => g.id === r.group_id) }) },
    { title: t('domain.lastCheck'), key: 'detect_status', width: 70, render: statusIcon },
    {
      title: t('common.actions'), key: 'actions', width: 150, fixed: 'right',
      render: (r) => h('div', { style: 'display:flex;gap:6px' }, [
        h(NButton, { size: 'tiny', secondary: true, type: 'primary', disabled: !auth.canWrite, onClick: () => renew(r) }, () => t('asset.renew')),
        h(NButton, { size: 'tiny', secondary: true, disabled: !auth.canWrite, onClick: () => edit(r) }, () => t('common.edit')),
        h(NDropdown, {
          trigger: 'click',
          options: [{ key: 'detail', label: t('vault.detail') }, { key: 'detect', label: t('asset.detect') }, { key: 'ack', label: t('asset.ack') }, { type: 'divider' }, { key: 'delete', label: t('common.delete') }],
          onSelect: (k) => rowAction(k, r),
        }, () => h(NButton, { size: 'tiny', quaternary: true }, () => h(NIcon, null, () => h(EllipsisHorizontal)))),
      ]),
    },
  )
  return list
})

async function load() {
  loading.value = true
  try {
    const res = await api.get('/api/domains', {
      kind: kind.value, status: filters.status, group_id: filters.group_id, keyword: filters.keyword,
      archived: filters.archived ? 1 : '', page: pagination.page, page_size: pagination.pageSize,
    })
    rows.value = res.items
    pagination.itemCount = res.total
    checked.value = checked.value.filter((id) => res.items.some((r) => r.id === id))
  } finally {
    loading.value = false
  }
}
async function loadStats() { stats.value = await api.get('/api/domains/stats', null, { silent: true }).catch(() => ({})) }
async function loadGroups() { groups.value = await api.get('/api/groups') }
function reload() { pagination.page = 1; checked.value = []; load() }
function reloadAll() { load(); loadStats() }
function changePage(p) { pagination.page = p; load() }

function applyStat(s) {
  kind.value = s.kind
  filters.status = s.status
  reload()
}

function open(r) { viewing.value = r; detailVisible.value = true }
function edit(r) { detailVisible.value = false; editing.value = r; formVisible.value = true }
function onSaved() { formVisible.value = false; reloadAll() }

async function renew(r) {
  await api.post(`/api/assets/${r.id}/renew`)
  message.success(t('asset.renewTip'))
  reloadAll()
}

async function rowAction(key, r) {
  if (key === 'detail') return open(r)
  if (key === 'detect') {
    const res = await api.post(`/api/assets/${r.id}/detect`)
    res.detect_status === 'ok' ? message.success(fmtDate(res.expire_time)) : message.error(res.detect_error)
  }
  if (key === 'ack') await api.post(`/api/assets/${r.id}/ack`)
  if (key === 'delete') return confirmDelete(async () => { await api.post('/api/assets/batch', { ids: [r.id], action: 'delete', with_certs: true }); reloadAll() })
  reloadAll()
}

function openBatch(action) {
  batchAction.value = action
  batchValue.value = groups.value.find((g) => g.is_default)?.id ?? null
  batchOffsets.value = kind.value === 'ssl' ? ['14', '7', '3', '1'] : ['30', '7', '3', '1', '0']
  batchVisible.value = true
}

async function submitBatch() {
  await batch(batchAction.value, batchAction.value === 'group' ? batchValue.value : batchOffsets.value.join(','))
}

async function batch(action, value = '') {
  const map = { enableDetect: ['auto_detect', '1'], disableDetect: ['auto_detect', '0'] }
  const [act, val] = map[action] || [action, value]
  const run = async () => {
    const res = await api.post('/api/assets/batch', { ids: checked.value, action: act, value: val, with_certs: kind.value === 'domain' && withCerts.value })
    message.success(act === 'detect' ? t('domain.detectQueued') : t('domain.affected', { n: res.affected }))
    checked.value = []
    reloadAll()
  }
  if (act === 'delete') return confirmDelete(run)
  return run()
}

async function addCert() {
  const host = certHost.value.trim()
  if (!host) return false
  const p = certParent.value
  await api.post('/api/assets', {
    type: 'ssl', name: host, identifier: host, auto_detect: true, group_id: p.group_id, parent_id: p.id, remind_offsets: '14,7,3,1',
  })
  reloadAll()
}

function exportCsv() {
  const qs = new URLSearchParams({ type: kind.value === 'domain' ? 'domain,ssl' : 'ssl', group_id: filters.group_id || '', status: filters.archived ? 'archived' : 'active' })
  download(`/api/assets/export?${qs}`, `domains-${new Date().toISOString().slice(0, 10)}.csv`)
}

onMounted(async () => {
  load()
  loadStats()
  loadGroups()
  channels.value = (await api.get('/api/channels')).items
})
</script>

<style scoped>
.stat { cursor: pointer; }
.batch { display: flex; align-items: center; gap: 8px; padding: 8px 12px; margin-bottom: 10px; border-radius: 8px; background: rgba(24, 160, 88, .08); flex-wrap: wrap; }
.spacer { flex: 1; }
</style>

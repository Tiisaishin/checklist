<template>
  <n-modal :show="show" preset="card" :title="mode === 'ssl' ? t('importer.titleSsl') : t('importer.title')" style="max-width: 920px" :mask-closable="false" @update:show="close">
    <n-steps :current="step" size="small" style="margin-bottom: 20px">
      <n-step :title="t('importer.steps.source')" />
      <n-step :title="t('importer.steps.mapping')" />
      <n-step :title="t('importer.steps.options')" />
      <n-step :title="t('importer.steps.result')" />
    </n-steps>

    <!-- 1. 选择数据 -->
    <div v-if="step === 1">
      <n-upload :show-file-list="false" accept=".xlsx,.xls,.csv,.txt,.tsv" :custom-request="() => {}" @change="onFile">
        <n-upload-dragger>
          <n-icon size="40" :depth="3"><CloudUploadOutline /></n-icon>
          <div style="margin-top: 8px">{{ t('importer.upload') }}</div>
          <div class="muted" style="margin-top: 4px">{{ t('importer.uploadHint') }}</div>
        </n-upload-dragger>
      </n-upload>
      <div class="muted" style="margin: 16px 0 6px">{{ t('importer.paste') }}</div>
      <n-input v-model:value="pasted" type="textarea" :autosize="{ minRows: 5, maxRows: 12 }" :placeholder="t('importer.pastePlaceholder')" class="mono" />
    </div>

    <!-- 2. 确认列 -->
    <div v-else-if="step === 2">
      <n-space align="center" style="margin-bottom: 12px" :size="8">
        <n-tag :bordered="false">{{ source }}</n-tag>
        <span class="muted">{{ t('importer.detected', { n: rows.length - (headerIndex + 1) }) }}</span>
        <n-tag v-if="preset" type="success" size="small" :bordered="false">{{ t('importer.preset', { name: preset }) }}</n-tag>
      </n-space>

      <div class="mapping">
        <div v-for="f in fields" :key="f">
          <div class="muted">{{ f === 'domain' && mode === 'ssl' ? t('importer.fields.host') : t('importer.fields.' + f) }}</div>
          <n-select v-model:value="mapping[f]" :options="columnOptions" size="small" clearable :placeholder="t('importer.notMapped')" />
        </div>
        <div v-if="mode === 'domain' && mapping.expire !== null">
          <div class="muted">{{ t('importer.dateOrder') }}</div>
          <n-select v-model:value="dateOrder" :options="dateOrderOptions" size="small" />
        </div>
      </div>
      <n-alert v-if="mode === 'domain' && ambiguous && dateOrder === 'auto'" type="warning" :bordered="false" style="margin: 12px 0 0">{{ t('importer.ambiguous') }}</n-alert>

      <n-space style="margin: 14px 0 8px" :size="12">
        <n-tag type="success" :bordered="false">{{ t('importer.valid', { n: built.items.length }) }}</n-tag>
        <n-tag v-if="built.invalid.length" type="error" :bordered="false">{{ t('importer.invalid', { n: built.invalid.length }) }}</n-tag>
        <n-tag v-if="mode === 'domain' && noExpire" :bordered="false">{{ t('importer.noExpire', { n: noExpire }) }}</n-tag>
      </n-space>
      <div class="muted" style="margin-bottom: 6px">{{ t('importer.preview') }}</div>
      <n-data-table :columns="previewColumns" :data="built.items.slice(0, 20)" size="small" :max-height="300" />
    </div>

    <!-- 3. 导入选项 -->
    <div v-else-if="step === 3">
      <n-form label-placement="top" size="small">
        <n-grid :cols="2" :x-gap="24" :y-gap="4" responsive="screen" item-responsive>
          <n-gi span="2 m:1">
            <!-- 域名到期 -->
            <template v-if="mode === 'domain'">
              <n-form-item :show-label="false">
                <n-checkbox v-model:checked="options.auto_detect">{{ t('importer.autoDetect') }}</n-checkbox>
              </n-form-item>
              <n-form-item :label="t('importer.existing')">
                <n-radio-group v-model:value="options.existing">
                  <n-space vertical :size="4">
                    <n-radio value="skip">{{ t('importer.existingSkip') }}</n-radio>
                    <n-radio value="update">{{ t('importer.existingUpdate') }}</n-radio>
                  </n-space>
                </n-radio-group>
              </n-form-item>
            </template>
            <!-- SSL 证书监控 -->
            <n-form-item v-else :label="t('importer.sslSource')">
              <n-radio-group v-model:value="options.ssl_from">
                <n-space vertical :size="6">
                  <n-radio value="direct">{{ t('importer.sslDirect') }}</n-radio>
                  <n-radio value="prefix">{{ t('importer.sslPrefix') }}</n-radio>
                  <template v-if="options.ssl_from === 'prefix'">
                    <n-space :size="12" style="padding-left: 24px">
                      <n-checkbox v-model:checked="options.ssl_root">{{ t('importer.sslRoot') }}</n-checkbox>
                      <n-checkbox v-model:checked="options.ssl_www">{{ t('importer.sslWww') }}</n-checkbox>
                    </n-space>
                    <n-input v-model:value="options.ssl_custom" style="margin-left: 24px; width: calc(100% - 24px)" :placeholder="t('importer.sslCustomPlaceholder')" />
                  </template>
                </n-space>
              </n-radio-group>
            </n-form-item>
          </n-gi>
          <n-gi span="2 m:1">
            <n-form-item :label="t('asset.group')">
              <n-select v-model:value="options.group_id" :options="groupOptions" />
            </n-form-item>
            <n-form-item v-if="mode === 'domain'" :label="t('importer.domainOffsets')">
              <n-select v-model:value="options.domain_offsets" multiple tag filterable :options="offsetOptions" />
            </n-form-item>
            <n-form-item v-else :label="t('importer.sslOffsets')">
              <n-select v-model:value="options.ssl_offsets" multiple tag filterable :options="offsetOptions" />
            </n-form-item>
            <n-form-item v-if="mode === 'domain'" :label="t('asset.currency')">
              <n-select v-model:value="options.currency" :options="currencyOptions" filterable tag />
            </n-form-item>
          </n-gi>
        </n-grid>
      </n-form>
    </div>

    <!-- 4. 结果 -->
    <div v-else-if="step === 4 && result">
      <n-result status="success" size="small" :title="t('importer.summary', { ...result, created: result.domains + result.ssl })">
        <template #footer>
          <div v-if="progress.total" style="max-width: 420px; margin: 0 auto; text-align: left">
            <div class="muted" style="margin-bottom: 6px">{{ t('importer.detecting', progress) }}</div>
            <n-progress type="line" :percentage="Math.round((progress.done / progress.total) * 100)" :status="progress.failed ? 'warning' : 'success'" :show-indicator="false" />
            <div v-if="progress.failed" class="muted" style="margin-top: 6px">{{ t('importer.detectFailed', { n: progress.failed }) }}</div>
            <div class="muted" style="margin-top: 6px">{{ t('importer.detectHint') }}</div>
          </div>
          <n-collapse v-if="result.invalid.length" style="margin-top: 16px; text-align: left">
            <n-collapse-item :title="`${t('importer.invalidList')} (${result.invalid.length})`">
              <div class="mono muted" v-for="(r, i) in result.invalid.slice(0, 100)" :key="i">{{ r.value }}</div>
            </n-collapse-item>
          </n-collapse>
        </template>
      </n-result>
    </div>

    <template #action>
      <div class="footer">
        <n-button v-if="step === 4 && result && !undone" quaternary type="error" @click="undo">{{ t('importer.undo') }}</n-button>
        <span class="spacer" />
        <n-button v-if="step > 1 && step < 4" @click="step--">{{ t('common.back') }}</n-button>
        <n-button v-if="step === 1" type="primary" :disabled="!pasted.trim()" @click="parsePasted">{{ t('importer.parse') }}</n-button>
        <n-button v-if="step === 2" type="primary" :disabled="!built.items.length" @click="step = 3">{{ t('importer.steps.options') }}</n-button>
        <n-button v-if="step === 3" type="primary" :loading="importing" :disabled="mode === 'ssl' && options.ssl_from === 'prefix' && !options.ssl_root && !options.ssl_www && !options.ssl_custom.trim()" @click="submit">{{ t('importer.start') }}</n-button>
        <n-button v-if="step === 4" @click="reset">{{ t('importer.again') }}</n-button>
        <n-button v-if="step === 4" type="primary" @click="close(false)">{{ t('importer.viewList') }}</n-button>
      </div>
    </template>
  </n-modal>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { CloudUploadOutline } from '@vicons/ionicons5'
import { api, message } from '../utils/api'
import { FIELDS, readFile, parseText, detectColumns, buildItems, hasAmbiguousDates } from '../utils/importer'

const props = defineProps({ show: Boolean, groups: Array, mode: { type: String, default: 'domain' } })
const emit = defineEmits(['update:show', 'done'])
const { t } = useI18n()

const step = ref(1)
const pasted = ref('')
const source = ref('')
const rows = ref([])
const headerIndex = ref(-1)
const headers = ref([])
const preset = ref(null)
const mapping = reactive(Object.fromEntries(FIELDS.map((f) => [f, null])))
const dateOrder = ref('auto')
const importing = ref(false)
const result = ref(null)
const undone = ref(false)
const progress = reactive({ total: 0, done: 0, failed: 0 })
let timer = null

const defaultOptions = () => ({
  ssl_from: 'direct', ssl_root: true, ssl_www: true, ssl_custom: '', auto_detect: true, existing: 'skip',
  group_id: (props.groups || []).find((g) => g.is_default)?.id ?? null, domain_offsets: ['30', '7', '3', '1', '0'],
  ssl_offsets: ['14', '7', '3', '1'], currency: 'USD',
})
const options = reactive(defaultOptions())

watch(() => props.show, (v) => { if (v) reset() })

const columnOptions = computed(() => headers.value.map((h, i) => {
  const sample = rows.value.slice(headerIndex.value + 1, headerIndex.value + 4).map((r) => String(r[i] ?? '')).filter(Boolean).join(', ')
  return { value: i, label: `${h || '#' + (i + 1)}${sample ? ' — ' + sample.slice(0, 40) : ''}` }
}))
const dateOrderOptions = computed(() => ['auto', 'mdy', 'dmy'].map((v) => ({ value: v, label: t('importer.dateOrders.' + v) })))
const groupOptions = computed(() => (props.groups || []).map((g) => ({ value: g.id, label: g.name })))
const offsetOptions = ['90', '60', '30', '14', '7', '3', '1', '0'].map((v) => ({ value: v, label: v }))
const currencyOptions = ['USD', 'CNY', 'EUR', 'HKD', 'JPY', 'GBP'].map((v) => ({ value: v, label: v }))

// SSL 直接按主机导入时保留端口与子域
const keepHost = computed(() => props.mode === 'ssl' && options.ssl_from === 'direct')
const built = computed(() => (rows.value.length ? buildItems(rows.value, headerIndex.value, mapping, dateOrder.value, { keepHost: keepHost.value }) : { items: [], invalid: [] }))
const noExpire = computed(() => built.value.items.filter((i) => !i.expire).length)
const ambiguous = computed(() => hasAmbiguousDates(rows.value.slice(headerIndex.value + 1), mapping.expire))

const fields = computed(() => (props.mode === 'ssl' ? ['domain', 'notes'] : FIELDS))
const previewColumns = computed(() => props.mode === 'ssl' ? [
  { title: t('importer.fields.host'), key: 'domain', minWidth: 240 },
  { title: t('importer.fields.notes'), key: 'notes', ellipsis: { tooltip: true } },
] : [
  { title: t('importer.fields.domain'), key: 'domain', minWidth: 200 },
  { title: t('importer.fields.expire'), key: 'expire', width: 160, render: (r) => r.expire || '-' },
  { title: t('importer.fields.registrar'), key: 'registrar', width: 140, ellipsis: { tooltip: true } },
  { title: t('importer.fields.price'), key: 'price', width: 80, render: (r) => r.price ?? '' },
  { title: t('importer.fields.notes'), key: 'notes', ellipsis: { tooltip: true } },
])

function load(data, name) {
  if (!data.length) { message.warning(t('importer.noDomain')); return }
  const det = detectColumns(data)
  if (det.mapping.domain === null) { message.warning(t('importer.noDomain')); return }
  rows.value = data
  headerIndex.value = det.headerIndex
  headers.value = det.headers.length ? det.headers : data[0].map((_, i) => `#${i + 1}`)
  preset.value = det.preset
  Object.assign(mapping, det.mapping)
  dateOrder.value = 'auto'
  source.value = name
  step.value = 2
}

async function onFile({ file }) {
  if (!file?.file) return
  try {
    load(await readFile(file.file), file.name)
  } catch (e) {
    message.error(e.message === 'XLS' ? t('importer.xlsUnsupported') : e.message)
  }
}

function parsePasted() {
  load(parseText(pasted.value), t('importer.paste'))
}

async function submit() {
  importing.value = true
  try {
    const ssl = props.mode === 'ssl'
    const hosts = []
    if (ssl && options.ssl_from === 'prefix') {
      if (options.ssl_root) hosts.push('@')
      if (options.ssl_www) hosts.push('www')
      if (options.ssl_custom.trim()) hosts.push(options.ssl_custom)
    }
    result.value = await api.post('/api/imports', {
      items: built.value.items,
      options: {
        as_domain: !ssl, ssl_direct: ssl && options.ssl_from === 'direct', ssl_hosts: hosts,
        auto_detect: ssl || options.auto_detect, existing: options.existing, group_id: options.group_id,
        domain_offsets: options.domain_offsets.join(','), ssl_offsets: options.ssl_offsets.join(','),
        currency: options.currency, source: source.value,
      },
    })
    step.value = 4
    emit('done')
    poll()
  } finally {
    importing.value = false
  }
}

async function poll() {
  clearTimeout(timer)
  if (!result.value || undone.value || !props.show) return
  const p = await api.get(`/api/imports/${result.value.batch_id}`, null, { silent: true }).catch(() => null)
  if (p) Object.assign(progress, p)
  if (p && p.done < p.total) timer = setTimeout(poll, 5000)
}

async function undo() {
  const res = await api.del(`/api/imports/${result.value.batch_id}`)
  undone.value = true
  clearTimeout(timer)
  message.success(t('importer.undone', { n: res.deleted }))
  emit('done')
}

function reset() {
  clearTimeout(timer)
  step.value = 1
  pasted.value = ''
  rows.value = []
  result.value = null
  undone.value = false
  Object.assign(progress, { total: 0, done: 0, failed: 0 })
  Object.assign(options, defaultOptions())
}

function close(v = false) {
  if (!v) {
    clearTimeout(timer)
    emit('update:show', false)
  }
}

onBeforeUnmount(() => clearTimeout(timer))
</script>

<style scoped>
.footer { display: flex; align-items: center; gap: 8px; }
.spacer { flex: 1; }
.mapping { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }
</style>

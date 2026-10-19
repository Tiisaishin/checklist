<template>
  <n-drawer :show="show" :width="drawerWidth" @update:show="(v) => emit('update:show', v)">
    <n-drawer-content :title="asset ? t('common.edit') : (isSsl ? t('monitor.add.ssl') : form.type === 'domain' && types.length === 1 ? t('monitor.add.domain') : t('asset.add'))" closable>
      <n-form ref="formRef" :model="form" :rules="rules" label-placement="top" size="small">
        <n-form-item v-if="types.length > 1" :label="t('asset.type')" path="type">
          <n-select v-model:value="form.type" :options="typeOptions" />
        </n-form-item>
        <!-- 域名 / SSL：只需要填写域名或主机，名称自动取同值 -->
        <n-form-item v-if="detectable" :label="isSsl ? t('importer.fields.host') : t('importer.fields.domain')" path="identifier">
          <n-input v-model:value="form.identifier" :placeholder="identifierHint" class="mono" @update:value="onIdentifier" />
        </n-form-item>
        <n-form-item :label="t('asset.name')" path="name">
          <n-input v-model:value="form.name" :placeholder="detectable ? 'example.com' : ''" />
        </n-form-item>
        <n-form-item v-if="!detectable" :label="t('asset.identifier')">
          <n-input v-model:value="form.identifier" :placeholder="identifierHint" class="mono" />
        </n-form-item>
        <n-form-item v-if="form.type === 'domain'" :show-label="false">
          <n-space vertical :size="2">
            <n-checkbox v-model:checked="form.auto_detect">{{ t('asset.autoDetect') }}</n-checkbox>
            <span class="muted">{{ t('asset.autoDetectHint') }}</span>
          </n-space>
        </n-form-item>
        <n-form-item v-if="!isSsl" :label="t('asset.expire')" path="expire_local">
          <n-date-picker
            v-model:formatted-value="form.expire_local" value-format="yyyy-MM-dd HH:mm" type="datetime" clearable
            style="width: 100%" :placeholder="detectable && form.auto_detect ? t('asset.autoDetect') : ''"
          />
        </n-form-item>

        <n-grid :cols="2" :x-gap="12">
          <n-gi>
            <n-form-item :label="t('asset.group')">
              <n-select v-model:value="form.group_id" :options="groupOptions" filterable tag @create="createGroupOption" />
            </n-form-item>
          </n-gi>
          <n-gi v-if="!isSsl">
            <n-form-item :label="form.type === 'domain' ? t('domain.registrar') : t('asset.provider')">
              <n-input v-model:value="form.provider" />
            </n-form-item>
          </n-gi>
        </n-grid>

        <n-form-item v-if="!isSsl" :label="t('asset.url')">
          <n-input v-model:value="form.url" placeholder="https://" />
        </n-form-item>

        <n-grid v-if="!isSsl" :cols="3" :x-gap="12">
          <n-gi>
            <n-form-item :label="t('asset.price')">
              <n-input-number v-model:value="form.price" :min="0" :precision="2" :show-button="false" style="width:100%" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item :label="t('asset.currency')">
              <n-select v-model:value="form.currency" :options="currencyOptions" filterable tag />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item :label="t('asset.cycle')">
              <n-select v-model:value="form.renew_cycle" :options="cycleOptions" />
            </n-form-item>
          </n-gi>
        </n-grid>
        <n-form-item v-if="!isSsl" :show-label="false">
          <n-checkbox v-model:checked="form.auto_renew">{{ t('asset.autoRenew') }}</n-checkbox>
        </n-form-item>

        <n-divider style="margin: 4px 0 12px" />

        <n-form-item :label="t('asset.offsets')">
          <n-select v-model:value="form.offsets" multiple filterable tag :options="offsetOptions" :placeholder="t('asset.offsetsHint')" />
        </n-form-item>
        <n-form-item :show-label="false">
          <n-checkbox v-model:checked="form.remind_overdue">{{ t('asset.overdueRemind') }}</n-checkbox>
        </n-form-item>
        <n-form-item :label="t('asset.channels')">
          <n-select v-model:value="form.remind_channels" multiple :options="channelOptions" :placeholder="t('asset.channelsHint')" clearable />
        </n-form-item>
        <n-form-item :label="t('common.notes')">
          <n-input v-model:value="form.notes" type="textarea" :autosize="{ minRows: 2, maxRows: 6 }" />
        </n-form-item>
      </n-form>
      <template #footer>
        <n-space>
          <n-button @click="emit('update:show', false)">{{ t('common.cancel') }}</n-button>
          <n-button type="primary" :loading="saving" @click="submit">{{ t('common.save') }}</n-button>
        </n-space>
      </template>
    </n-drawer-content>
  </n-drawer>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, message } from '../utils/api'
import { toLocalInput, toServer } from '../utils/time'

const props = defineProps({
  show: Boolean, asset: Object, groups: Array, channels: Array,
  // 可选的类型；只有一个时隐藏类型选择（域名到期 / SSL 监控页面）
  types: { type: Array, default: () => ['domain', 'ssl', 'server', 'subscription', 'license', 'account', 'other'] },
})
const emit = defineEmits(['update:show', 'saved', 'groups-changed'])
const { t } = useI18n()

const lastIdentifier = ref('')
// 名称为空或仍等于上一次的标识时，跟随标识自动填写
function onIdentifier(v) {
  if (!form.name || form.name === lastIdentifier.value) form.name = v
  lastIdentifier.value = v
}
const drawerWidth = Math.min(520, window.innerWidth)
const formRef = ref()
const saving = ref(false)

const empty = () => ({
  type: 'domain', name: '', identifier: '', auto_detect: true, expire_local: null, group_id: null, provider: '', url: '',
  price: null, currency: 'USD', renew_cycle: 'yearly', auto_renew: false, offsets: ['30', '7', '3', '1', '0'],
  remind_overdue: true, remind_channels: [], notes: '',
})
const form = reactive(empty())

watch(() => props.show, (v) => {
  if (!v) return
  Object.assign(form, empty())
  form.type = props.types[0]
  if (form.type === 'ssl') form.offsets = ['14', '7', '3', '1']
  form.group_id = (props.groups || []).find((g) => g.is_default)?.id ?? null
  const a = props.asset
  lastIdentifier.value = a?.identifier || ''
  if (a) {
    Object.assign(form, {
      type: a.type, name: a.name, identifier: a.identifier, auto_detect: a.auto_detect, expire_local: toLocalInput(a.expire_time),
      group_id: a.group_id || form.group_id, provider: a.provider, url: a.url, price: a.price || null, currency: a.currency,
      renew_cycle: a.renew_cycle, auto_renew: a.auto_renew, offsets: (a.remind_offsets || '').split(',').filter((x) => x !== ''),
      remind_overdue: a.remind_overdue, remind_channels: a.remind_channels || [], notes: a.notes || '',
    })
  }
})

const detectable = computed(() => ['domain', 'ssl'].includes(form.type))
const identifierHint = computed(() => t('asset.identifierHint.' + (detectable.value ? form.type : 'default')))
const isSsl = computed(() => form.type === 'ssl')
const typeOptions = computed(() => props.types.map((v) => ({ value: v, label: t('asset.types.' + v) })))
const cycleOptions = computed(() => ['monthly', 'quarterly', 'yearly', 'once'].map((v) => ({ value: v, label: t('asset.cycles.' + v) })))
const currencyOptions = ['CNY', 'USD', 'EUR', 'HKD', 'JPY', 'GBP'].map((v) => ({ value: v, label: v }))
const offsetOptions = ['90', '60', '30', '14', '7', '3', '1', '0'].map((v) => ({ value: v, label: v }))
const groupOptions = computed(() => (props.groups || []).map((p) => ({ value: p.id, label: p.name })))
const channelOptions = computed(() => (props.channels || []).map((c) => ({ value: c.id, label: c.name + (c.chat_title ? ` · ${c.chat_title}` : '') })))

const rules = computed(() => ({
  type: { required: true },
  identifier: { required: detectable.value, trigger: 'blur', message: t('asset.identifier') },
  name: { required: true, trigger: 'blur' },
  expire_local: {
    validator: () => !!form.expire_local || (detectable.value && form.auto_detect),
    message: t('asset.expire'), trigger: 'change',
  },
}))

async function createGroupOption(label) {
  const p = await api.post('/api/groups', { name: label })
  emit('groups-changed')
  form.group_id = p.id
  return { label: p.name, value: p.id }
}

async function submit() {
  await formRef.value.validate()
  saving.value = true
  try {
    const payload = {
      type: form.type, name: form.name.trim(), identifier: form.identifier.trim(), auto_detect: isSsl.value || (detectable.value && form.auto_detect),
      expire_time: toServer(form.expire_local), group_id: typeof form.group_id === 'number' ? form.group_id : 0,
      provider: form.provider, url: form.url, price: form.price || 0, currency: form.currency, renew_cycle: form.renew_cycle,
      auto_renew: form.auto_renew, remind_offsets: form.offsets.join(','), remind_overdue: form.remind_overdue,
      remind_channels: form.remind_channels, notes: form.notes,
    }
    const res = props.asset ? await api.put(`/api/assets/${props.asset.id}`, payload) : await api.post('/api/assets', payload)
    if (res.detect_status === 'failed') message.warning(`${t('asset.detectFailed')}: ${res.detect_error}`)
    else message.success(t('common.save'))
    emit('saved', res)
  } finally {
    saving.value = false
  }
}
</script>

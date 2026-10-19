<template>
  <n-drawer :show="show" :width="drawerWidth" @update:show="(v) => emit('update:show', v)">
    <n-drawer-content :title="asset ? t('common.edit') : t('asset.add')" closable>
      <n-form ref="formRef" :model="form" :rules="rules" label-placement="top" size="small">
        <n-form-item :label="t('asset.type')" path="type">
          <n-select v-model:value="form.type" :options="typeOptions" />
        </n-form-item>
        <n-form-item :label="t('asset.name')" path="name">
          <n-input v-model:value="form.name" :placeholder="detectable ? 'example.com' : ''" />
        </n-form-item>
        <n-form-item :label="t('asset.identifier')">
          <n-input v-model:value="form.identifier" :placeholder="identifierHint" class="mono" />
        </n-form-item>
        <n-form-item v-if="detectable" :show-label="false">
          <n-space vertical :size="2">
            <n-checkbox v-model:checked="form.auto_detect">{{ t('asset.autoDetect') }}</n-checkbox>
            <span class="muted">{{ t('asset.autoDetectHint') }}</span>
          </n-space>
        </n-form-item>
        <n-form-item :label="t('asset.expire')" path="expire_local">
          <n-date-picker
            v-model:formatted-value="form.expire_local" value-format="yyyy-MM-dd HH:mm" type="datetime" clearable
            style="width: 100%" :placeholder="detectable && form.auto_detect ? t('asset.autoDetect') : ''"
          />
        </n-form-item>

        <n-grid :cols="2" :x-gap="12">
          <n-gi>
            <n-form-item :label="t('asset.project')">
              <n-select v-model:value="form.project_id" :options="projectOptions" clearable filterable tag
                :placeholder="t('common.optional')" @create="createProjectOption" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item :label="t('asset.provider')">
              <n-input v-model:value="form.provider" />
            </n-form-item>
          </n-gi>
        </n-grid>

        <n-form-item :label="t('asset.url')">
          <n-input v-model:value="form.url" placeholder="https://" />
        </n-form-item>

        <n-grid :cols="3" :x-gap="12">
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
        <n-form-item :show-label="false">
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

const props = defineProps({ show: Boolean, asset: Object, projects: Array, channels: Array })
const emit = defineEmits(['update:show', 'saved', 'projects-changed'])
const { t } = useI18n()

const types = ['domain', 'ssl', 'server', 'subscription', 'license', 'account', 'other']
const drawerWidth = Math.min(520, window.innerWidth)
const formRef = ref()
const saving = ref(false)

const empty = () => ({
  type: 'domain', name: '', identifier: '', auto_detect: true, expire_local: null, project_id: null, provider: '', url: '',
  price: null, currency: 'USD', renew_cycle: 'yearly', auto_renew: false, offsets: ['30', '7', '3', '1', '0'],
  remind_overdue: true, remind_channels: [], notes: '',
})
const form = reactive(empty())

watch(() => props.show, (v) => {
  if (!v) return
  Object.assign(form, empty())
  const a = props.asset
  if (a) {
    Object.assign(form, {
      type: a.type, name: a.name, identifier: a.identifier, auto_detect: a.auto_detect, expire_local: toLocalInput(a.expire_time),
      project_id: a.project_id || null, provider: a.provider, url: a.url, price: a.price || null, currency: a.currency,
      renew_cycle: a.renew_cycle, auto_renew: a.auto_renew, offsets: (a.remind_offsets || '').split(',').filter((x) => x !== ''),
      remind_overdue: a.remind_overdue, remind_channels: a.remind_channels || [], notes: a.notes || '',
    })
  }
})

const detectable = computed(() => ['domain', 'ssl'].includes(form.type))
const identifierHint = computed(() => t('asset.identifierHint.' + (detectable.value ? form.type : 'default')))
const typeOptions = computed(() => types.map((v) => ({ value: v, label: t('asset.types.' + v) })))
const cycleOptions = computed(() => ['monthly', 'quarterly', 'yearly', 'once'].map((v) => ({ value: v, label: t('asset.cycles.' + v) })))
const currencyOptions = ['CNY', 'USD', 'EUR', 'HKD', 'JPY', 'GBP'].map((v) => ({ value: v, label: v }))
const offsetOptions = ['90', '60', '30', '14', '7', '3', '1', '0'].map((v) => ({ value: v, label: v }))
const projectOptions = computed(() => (props.projects || []).map((p) => ({ value: p.id, label: p.name })))
const channelOptions = computed(() => (props.channels || []).map((c) => ({ value: c.id, label: c.name + (c.chat_title ? ` · ${c.chat_title}` : '') })))

const rules = computed(() => ({
  type: { required: true },
  name: { required: true, trigger: 'blur' },
  expire_local: {
    validator: () => !!form.expire_local || (detectable.value && form.auto_detect),
    message: t('asset.expire'), trigger: 'change',
  },
}))

async function createProjectOption(label) {
  const p = await api.post('/api/projects', { name: label })
  emit('projects-changed')
  form.project_id = p.id
  return { label: p.name, value: p.id }
}

async function submit() {
  await formRef.value.validate()
  saving.value = true
  try {
    const payload = {
      type: form.type, name: form.name.trim(), identifier: form.identifier.trim(), auto_detect: detectable.value && form.auto_detect,
      expire_time: toServer(form.expire_local), project_id: typeof form.project_id === 'number' ? form.project_id : 0,
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

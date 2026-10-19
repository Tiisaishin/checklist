<template>
  <n-drawer :show="show" :width="drawerWidth" @update:show="(v) => emit('update:show', v)">
    <n-drawer-content :title="task ? t('common.edit') : t('task.add')" closable>
      <n-form ref="formRef" :model="form" :rules="rules" label-placement="top" size="small">
        <n-form-item :label="t('task.name')" path="title">
          <n-input v-model:value="form.title" />
        </n-form-item>

        <n-form-item :label="t('task.freq')">
          <n-input-group>
            <n-input-group-label>{{ t('task.interval') }}</n-input-group-label>
            <n-input-number v-model:value="form.interval" :min="1" :max="365" style="width: 110px" />
            <n-select v-model:value="form.freq" :options="freqOptions" style="width: 120px" />
          </n-input-group>
        </n-form-item>

        <n-form-item v-if="form.freq === 'weekly'" :label="t('task.weekdays')">
          <n-checkbox-group v-model:value="form.by_weekday">
            <n-space :size="6">
              <n-checkbox v-for="d in 7" :key="d" :value="d" :label="weekdayName(d, t)" />
            </n-space>
          </n-checkbox-group>
        </n-form-item>

        <n-grid :cols="2" :x-gap="12">
          <n-gi v-if="form.freq === 'yearly'">
            <n-form-item :label="t('task.month')">
              <n-select v-model:value="form.by_month" :options="monthOptions" />
            </n-form-item>
          </n-gi>
          <n-gi v-if="form.freq === 'monthly' || form.freq === 'yearly'">
            <n-form-item :label="t('task.monthday')">
              <n-select v-model:value="form.by_monthday" :options="monthdayOptions" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item :label="t('task.time')">
              <n-time-picker v-model:formatted-value="form.time_of_day" value-format="HH:mm" format="HH:mm" style="width:100%" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item :label="t('task.start')">
              <n-date-picker v-model:formatted-value="form.start_date" value-format="yyyy-MM-dd" type="date" style="width:100%" />
            </n-form-item>
          </n-gi>
        </n-grid>

        <n-form-item :label="t('task.mode')">
          <n-radio-group v-model:value="form.mode">
            <n-space vertical :size="4">
              <n-radio value="fixed">{{ t('task.modes.fixed') }}</n-radio>
              <n-radio value="after_done">{{ t('task.modes.after_done') }}</n-radio>
            </n-space>
          </n-radio-group>
        </n-form-item>

        <n-form-item :label="t('task.steps')">
          <n-dynamic-tags v-model:value="form.steps" :max="30" />
        </n-form-item>

        <n-form-item :label="t('task.description')">
          <n-input v-model:value="form.description" type="textarea" :autosize="{ minRows: 2, maxRows: 6 }" />
        </n-form-item>

        <n-form-item :label="t('asset.project')">
          <n-select v-model:value="form.project_id" :options="projectOptions" clearable filterable tag :placeholder="t('common.optional')" @create="createProjectOption" />
        </n-form-item>

        <n-divider style="margin: 4px 0 12px" />

        <n-form-item :label="t('task.offsets')">
          <n-select v-model:value="form.offsets" multiple filterable tag :options="offsetOptions" />
        </n-form-item>
        <n-form-item :show-label="false">
          <n-checkbox v-model:checked="form.remind_overdue">{{ t('task.overdueRemind') }}</n-checkbox>
        </n-form-item>
        <n-form-item :label="t('asset.channels')">
          <n-select v-model:value="form.remind_channels" multiple :options="channelOptions" :placeholder="t('asset.channelsHint')" clearable />
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
import { today } from '../utils/time'
import { weekdayName } from '../utils/task'

const props = defineProps({ show: Boolean, task: Object, projects: Array, channels: Array })
const emit = defineEmits(['update:show', 'saved', 'projects-changed'])
const { t } = useI18n()
const drawerWidth = Math.min(520, window.innerWidth)
const formRef = ref()
const saving = ref(false)

const empty = () => ({
  title: '', description: '', freq: 'daily', interval: 1, by_weekday: [], by_monthday: 0, by_month: 0, time_of_day: '09:00',
  start_date: today(), mode: 'fixed', steps: [], project_id: null, offsets: ['0'], remind_overdue: true, remind_channels: [],
})
const form = reactive(empty())

watch(() => props.show, (v) => {
  if (!v) return
  Object.assign(form, empty())
  const x = props.task
  if (x) {
    Object.assign(form, {
      title: x.title, description: x.description || '', freq: x.freq, interval: x.interval, mode: x.mode,
      by_weekday: String(x.by_weekday || '').split(',').filter(Boolean).map(Number), by_monthday: x.by_monthday, by_month: x.by_month,
      time_of_day: x.time_of_day, start_date: x.start_date, steps: x.steps || [], project_id: x.project_id || null,
      offsets: String(x.remind_offsets || '0').split(',').filter((s) => s !== ''), remind_overdue: x.remind_overdue,
      remind_channels: x.remind_channels || [],
    })
  }
})

const freqOptions = computed(() => ['daily', 'weekly', 'monthly', 'yearly'].map((v) => ({ value: v, label: t('task.freqs.' + v) })))
const monthOptions = computed(() => [{ value: 0, label: t('task.sameAsStart') }, ...Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: String(i + 1) }))])
const monthdayOptions = computed(() => [
  { value: 0, label: t('task.sameAsStart') }, { value: -1, label: t('task.lastDay') },
  ...Array.from({ length: 31 }, (_, i) => ({ value: i + 1, label: String(i + 1) })),
])
const offsetOptions = ['30', '14', '7', '3', '1', '0'].map((v) => ({ value: v, label: v }))
const projectOptions = computed(() => (props.projects || []).map((p) => ({ value: p.id, label: p.name })))
const channelOptions = computed(() => (props.channels || []).map((c) => ({ value: c.id, label: c.name + (c.chat_title ? ` · ${c.chat_title}` : '') })))
const rules = { title: { required: true, trigger: 'blur' } }

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
      title: form.title.trim(), description: form.description, freq: form.freq, interval: form.interval || 1,
      by_weekday: form.by_weekday.join(','), by_monthday: form.by_monthday, by_month: form.by_month,
      time_of_day: form.time_of_day || '09:00', start_date: form.start_date || today(), mode: form.mode, steps: form.steps,
      project_id: typeof form.project_id === 'number' ? form.project_id : 0, remind_offsets: form.offsets.join(',') || '0',
      remind_overdue: form.remind_overdue, remind_channels: form.remind_channels,
    }
    const res = props.task ? await api.put(`/api/tasks/${props.task.id}`, payload) : await api.post('/api/tasks', payload)
    message.success(t('common.save'))
    emit('saved', res)
  } finally {
    saving.value = false
  }
}
</script>

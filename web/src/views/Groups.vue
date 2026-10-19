<template>
  <div class="page">
    <div class="page-header">
      <h2>{{ t('group.title') }}</h2>
      <n-button type="primary" @click="edit()" :disabled="!auth.canWrite">
        <template #icon><n-icon><AddOutline /></n-icon></template>{{ t('group.add') }}
      </n-button>
    </div>
    <n-alert type="info" :bordered="false" style="margin-bottom: 16px">{{ t('group.desc') }}</n-alert>

    <n-spin :show="loading">
      <div class="grid-2">
        <n-card v-for="g in groups" :key="g.id" size="small">
          <template #header>
            <n-space align="center" :size="8">
              <i class="dot" :style="{ background: g.color }" />
              <span>{{ g.name }}</span>
              <n-tooltip v-if="g.is_default">
                <template #trigger><n-tag size="small" type="success" :bordered="false" round>{{ t('group.default') }}</n-tag></template>
                {{ t('group.defaultTip') }}
              </n-tooltip>
            </n-space>
          </template>
          <template #header-extra>
            <n-space :size="4">
              <n-button size="small" quaternary @click="edit(g)" :disabled="!auth.canWrite">{{ t('common.edit') }}</n-button>
              <n-button v-if="!g.is_default" size="small" quaternary type="error" @click="remove(g)" :disabled="!auth.canWrite">{{ t('common.delete') }}</n-button>
            </n-space>
          </template>

          <div v-if="g.description" class="muted" style="margin-bottom: 10px">{{ g.description }}</div>

          <div class="label">{{ t('group.channels') }}</div>
          <n-space v-if="g.channel_list.length" :size="6">
            <n-tag v-for="c in g.channel_list" :key="c.id" size="small" :bordered="false" :type="c.status === 'active' ? 'info' : 'default'">
              <template #icon><n-icon><PaperPlane /></n-icon></template>
              {{ c.name }}<span v-if="c.chat_title" class="muted">&nbsp;· {{ c.chat_title }}</span>
            </n-tag>
          </n-space>
          <span v-else class="muted">{{ g.is_default ? t('group.allChannels') : t('group.inheritDefault') }}</span>

          <template #action>
            <n-space :size="16">
              <n-button text @click="router.push({ name: 'assets', query: { group_id: g.id } })">{{ t('group.assets') }} <b class="count">{{ g.asset_count }}</b></n-button>
              <n-button text @click="router.push({ name: 'tasks', query: { group_id: g.id } })">{{ t('group.tasks') }} <b class="count">{{ g.task_count }}</b></n-button>
            </n-space>
          </template>
        </n-card>
      </div>
    </n-spin>

    <n-modal v-model:show="visible" preset="card" :title="form.id ? t('common.edit') : t('group.add')" style="max-width: 520px">
      <n-form ref="formRef" :model="form" :rules="rules" label-placement="top" size="small">
        <n-form-item :label="t('group.name')" path="name">
          <n-input v-model:value="form.name" />
        </n-form-item>
        <n-form-item :label="t('group.color')">
          <n-space :size="8">
            <span v-for="c in colors" :key="c" class="swatch" :class="{ active: form.color === c }" :style="{ background: c }" @click="form.color = c" />
          </n-space>
        </n-form-item>
        <n-form-item :label="t('group.description')">
          <n-input v-model:value="form.description" />
        </n-form-item>
        <n-form-item :label="t('group.channels')">
          <n-select v-model:value="form.remind_channels" multiple clearable :options="channelOptions" />
          <template #feedback>
            <span class="muted">{{ t('group.channelsHint') }}</span>
            <n-button text type="primary" size="tiny" style="margin-left: 6px" @click="router.push({ name: 'channels' })">{{ t('group.manageChannels') }} →</n-button>
          </template>
        </n-form-item>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="visible = false">{{ t('common.cancel') }}</n-button>
          <n-button type="primary" :loading="saving" @click="save">{{ t('common.save') }}</n-button>
        </n-space>
      </template>
    </n-modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { AddOutline, PaperPlane } from '@vicons/ionicons5'
import { api, dialog, message } from '../utils/api'
import { useAuth } from '../stores/auth'

const { t } = useI18n()
const router = useRouter()
const auth = useAuth()
const groups = ref([])
const channels = ref([])
const loading = ref(false)
const visible = ref(false)
const saving = ref(false)
const formRef = ref()
const colors = ['#18a058', '#2080f0', '#f0a020', '#d03050', '#8a2be2', '#13c2c2', '#eb2f96', '#666666']
const form = reactive({ id: null, name: '', color: colors[0], description: '', remind_channels: [] })
const rules = { name: { required: true, trigger: 'blur' } }

const channelOptions = computed(() => {
  const opts = channels.value.map((c) => ({
    value: c.id,
    label: `${c.name}${c.chat_title ? ' · ' + c.chat_title : ''}${c.status !== 'active' ? ` (${t('channel.' + c.status)})` : ''}`,
  }))
  // 团队中其他成员的渠道（只读展示，保持已选）
  const current = groups.value.find((g) => g.id === form.id)
  for (const c of current?.channel_list || []) {
    if (!opts.some((o) => o.value === c.id)) opts.push({ value: c.id, label: `${c.name} (${t('group.notMine')})` })
  }
  return opts
})

async function load() {
  loading.value = true
  try {
    const [g, c] = await Promise.all([api.get('/api/groups'), api.get('/api/channels')])
    groups.value = g
    channels.value = c.items
  } finally {
    loading.value = false
  }
}

function edit(g = null) {
  Object.assign(form, { id: null, name: '', color: colors[0], description: '', remind_channels: [] })
  if (g) Object.assign(form, { id: g.id, name: g.name, color: g.color, description: g.description, remind_channels: g.remind_channels || [] })
  visible.value = true
}

async function save() {
  await formRef.value.validate()
  saving.value = true
  try {
    const { id, ...payload } = form
    if (id) await api.put(`/api/groups/${id}`, payload)
    else await api.post('/api/groups', payload)
    visible.value = false
    message.success(t('common.save'))
    load()
  } finally {
    saving.value = false
  }
}

function remove(g) {
  dialog.warning({
    title: `${t('common.delete')}「${g.name}」`,
    content: t('group.deleteTip'),
    positiveText: t('common.delete'),
    negativeText: t('common.cancel'),
    onPositiveClick: async () => { await api.del(`/api/groups/${g.id}`); load() },
  })
}

onMounted(load)
</script>

<style scoped>
.dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.label { font-size: 12px; opacity: .6; margin-bottom: 6px; }
.count { margin-left: 4px; }
.swatch { width: 22px; height: 22px; border-radius: 50%; display: inline-block; cursor: pointer; border: 2px solid transparent; box-shadow: 0 0 0 1px rgba(127,127,127,.25); }
.swatch.active { border-color: #fff; box-shadow: 0 0 0 2px currentColor; }
</style>

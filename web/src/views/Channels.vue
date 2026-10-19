<template>
  <div class="page">
    <div class="page-header">
      <h2>{{ t('channel.title') }}</h2>
      <n-button type="primary" @click="openCreate">
        <template #icon><n-icon><AddOutline /></n-icon></template>{{ t('channel.add') }}
      </n-button>
    </div>

    <n-spin :show="loading">
      <n-empty v-if="!loading && !items.length" :description="t('channel.empty')" style="padding: 48px 0">
        <template #extra><n-button type="primary" @click="openCreate">{{ t('channel.add') }}</n-button></template>
      </n-empty>

      <div class="grid-2">
        <n-card v-for="c in items" :key="c.id" size="small">
          <template #header>
            <n-space align="center" :size="8">
              <n-icon size="20" color="#2aabee"><PaperPlane /></n-icon>
              <span>{{ c.name }}</span>
              <n-tag size="small" :bordered="false" round :type="statusType(c.status)">{{ t('channel.' + c.status) }}</n-tag>
            </n-space>
          </template>
          <template #header-extra>
            <n-dropdown :options="moreOptions(c)" @select="(k) => onMore(k, c)" trigger="click">
              <n-button quaternary size="small"><template #icon><n-icon><EllipsisHorizontal /></n-icon></template></n-button>
            </n-dropdown>
          </template>

          <n-descriptions :column="1" label-placement="left" size="small">
            <n-descriptions-item :label="t('channel.bot')">
              <span class="mono">@{{ c.bot_username || '-' }}</span>
              <n-tag size="tiny" :bordered="false" style="margin-left: 6px">{{ c.mode === 'custom' ? t('channel.custom') : t('channel.system') }}</n-tag>
              <n-tag v-if="c.mode === 'custom'" size="tiny" :bordered="false" style="margin-left: 4px">{{ c.webhook ? t('channel.webhook') : t('channel.polling') }}</n-tag>
            </n-descriptions-item>
            <n-descriptions-item v-if="c.target" :label="t('channel.chat')">
              {{ c.chat_title || '' }} <span class="mono muted">{{ c.target }}</span>
            </n-descriptions-item>
            <n-descriptions-item v-if="c.last_error" :label="t('channel.lastError')">
              <span style="color: #d03050">{{ c.last_error }}</span>
            </n-descriptions-item>
          </n-descriptions>

          <!-- 待绑定 -->
          <div v-if="c.status === 'pending'" class="bind">
            <div class="bind-title">{{ t('channel.bindTitle') }}</div>
            <n-space v-if="c.bind_links?.private" :size="8">
              <n-button tag="a" :href="c.bind_links.private" target="_blank" type="primary" size="small">{{ t('channel.bindPrivate') }}</n-button>
              <n-button tag="a" :href="c.bind_links.group" target="_blank" size="small">{{ t('channel.bindGroup') }}</n-button>
            </n-space>
            <div v-if="c.bind_links?.command" class="muted" style="margin-top: 8px">
              {{ t('channel.bindManual') }}:
              <n-text code class="copy" @click="copy(c.bind_links.command)">{{ c.bind_links.command }}</n-text>
            </div>
            <n-input-group style="margin-top: 10px">
              <n-input v-model:value="chatInputs[c.id]" size="small" :placeholder="t('channel.manualChat')" />
              <n-button size="small" @click="bindChat(c)">{{ t('channel.bindChat') }}</n-button>
            </n-input-group>
            <div class="muted" style="margin-top: 6px">{{ t('channel.bindWaiting') }}</div>
          </div>

          <template #action v-if="c.status !== 'pending'">
            <n-space justify="space-between" align="center">
              <n-switch :value="c.status === 'active'" @update:value="(v) => toggle(c, v)" size="small" />
              <n-button size="small" secondary @click="test(c)">{{ t('channel.test') }}</n-button>
            </n-space>
          </template>
        </n-card>
      </div>
    </n-spin>

    <n-modal v-model:show="createVisible" preset="card" :title="t('channel.add')" style="max-width: 520px">
      <n-form :model="form" label-placement="top" size="small">
        <n-form-item :label="t('common.name')">
          <n-input v-model:value="form.name" placeholder="Telegram" />
        </n-form-item>
        <n-form-item :label="t('channel.mode')">
          <n-radio-group v-model:value="form.mode" style="width: 100%">
            <n-space vertical>
              <n-radio value="system" :disabled="!systemBot">
                <div>{{ t('channel.system') }}</div>
                <div class="muted">{{ systemBot ? t('channel.systemDesc', { name: systemBot }) : t('channel.noSystemBot') }}</div>
              </n-radio>
              <n-radio value="custom">
                <div>{{ t('channel.custom') }}</div>
                <div class="muted">{{ t('channel.customDesc') }}</div>
              </n-radio>
            </n-space>
          </n-radio-group>
        </n-form-item>
        <template v-if="form.mode === 'custom'">
          <n-form-item :label="t('channel.botToken')">
            <n-input v-model:value="form.bot_token" type="password" show-password-on="click" placeholder="123456789:AA..." class="mono" />
            <template #feedback><span class="muted">{{ t('channel.botTokenHint') }}</span></template>
          </n-form-item>
          <n-form-item :label="t('channel.chatId')">
            <n-input v-model:value="form.chat_id" placeholder="-1001234567890" class="mono" />
            <template #feedback><span class="muted">{{ t('channel.chatIdHint') }}</span></template>
          </n-form-item>
        </template>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="createVisible = false">{{ t('common.cancel') }}</n-button>
          <n-button type="primary" :loading="saving" @click="create">{{ t('common.save') }}</n-button>
        </n-space>
      </template>
    </n-modal>

    <n-modal v-model:show="tokenVisible" preset="dialog" :title="t('channel.changeToken')" :positive-text="t('common.save')" :negative-text="t('common.cancel')" @positive-click="saveToken">
      <n-input v-model:value="newToken" type="password" show-password-on="click" class="mono" />
    </n-modal>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { AddOutline, EllipsisHorizontal, PaperPlane } from '@vicons/ionicons5'
import { api, confirmDelete, message } from '../utils/api'

const { t } = useI18n()
const items = ref([])
const systemBot = ref(null)
const loading = ref(false)
const createVisible = ref(false)
const saving = ref(false)
const form = reactive({ name: '', mode: 'system', bot_token: '', chat_id: '' })
const chatInputs = reactive({})
const tokenVisible = ref(false)
const tokenChannel = ref(null)
const newToken = ref('')
let timer = null

const statusType = (s) => ({ active: 'success', pending: 'warning', disabled: 'default' }[s])

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const res = await api.get('/api/channels', null, { silent })
    items.value = res.items
    systemBot.value = res.system_bot
  } finally {
    loading.value = false
  }
}

function openCreate() {
  Object.assign(form, { name: '', mode: systemBot.value ? 'system' : 'custom', bot_token: '', chat_id: '' })
  createVisible.value = true
}

async function create() {
  saving.value = true
  try {
    await api.post('/api/channels', { type: 'telegram', ...form })
    createVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function bindChat(c) {
  const chatId = (chatInputs[c.id] || '').trim()
  if (!chatId) return
  await api.put(`/api/channels/${c.id}`, { chat_id: chatId })
  message.success(t('channel.testOk'))
  load()
}

async function toggle(c, v) {
  await api.put(`/api/channels/${c.id}`, { enabled: v })
  load(true)
}

async function test(c) {
  await api.post(`/api/channels/${c.id}/test`)
  message.success(t('channel.testOk'))
}

function moreOptions(c) {
  const list = [{ key: 'rebind', label: t('channel.rebind') }]
  if (c.mode === 'custom') list.push({ key: 'token', label: t('channel.changeToken') })
  list.push({ type: 'divider' }, { key: 'delete', label: t('common.delete') })
  return list
}

async function onMore(key, c) {
  if (key === 'rebind') { await api.post(`/api/channels/${c.id}/rebind`); load() }
  if (key === 'token') { tokenChannel.value = c; newToken.value = ''; tokenVisible.value = true }
  if (key === 'delete') confirmDelete(async () => { await api.del(`/api/channels/${c.id}`); load() })
}

async function saveToken() {
  await api.put(`/api/channels/${tokenChannel.value.id}`, { bot_token: newToken.value })
  load()
}

async function copy(text) {
  try { await navigator.clipboard.writeText(text); message.success(t('common.copied')) } catch { /* ignore */ }
}

onMounted(() => {
  load()
  // 有待绑定渠道时自动刷新状态
  timer = setInterval(() => { if (items.value.some((c) => c.status === 'pending') && !document.hidden) load(true) }, 4000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<style scoped>
.bind { margin-top: 12px; padding: 12px; border-radius: 8px; background: rgba(240, 160, 32, .08); }
.bind-title { font-weight: 500; margin-bottom: 8px; }
.copy { cursor: pointer; }
</style>

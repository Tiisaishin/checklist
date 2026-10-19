<template>
  <n-drawer :show="show" :width="width" @update:show="(v) => emit('update:show', v)">
    <n-drawer-content :title="item?.title" closable>
      <n-tabs v-model:value="tab" type="line" size="small">
        <n-tab-pane name="detail" :tab="t('vault.detail')">
          <n-alert v-if="!canReveal" type="warning" :bordered="false" style="margin-bottom: 12px">{{ t('vault.noReveal') }}</n-alert>

          <div v-if="item?.url" class="row">
            <div class="label">{{ t('vault.url') }}</div>
            <a :href="safeUrl(item.url)" target="_blank" rel="noopener noreferrer" class="value link">{{ item.url }}</a>
          </div>

          <n-spin :show="loading">
            <template v-if="secret">
              <SecretRow v-if="secret.username" :label="t('vault.username')" :value="secret.username" @copy="copy('username', secret.username)" />
              <SecretRow v-if="secret.password" :label="t('vault.password')" :value="secret.password" masked @copy="copy('password', secret.password)" />

              <div v-if="secret.totp" class="row">
                <div class="label">{{ t('vault.code') }}</div>
                <div class="totp">
                  <span class="code mono">{{ totp ? totp.code.replace(/(\d{3})(?=\d)/, '$1 ') : '------' }}</span>
                  <n-progress v-if="totp" type="circle" :percentage="(totp.remaining / totp.period) * 100" :show-indicator="false" :stroke-width="12" style="width: 22px" :status="totp.remaining <= 5 ? 'error' : 'success'" />
                  <span class="muted">{{ totp?.remaining }}s</span>
                  <n-button size="tiny" quaternary :disabled="!totp" @click="copy('totp', totp.code)"><template #icon><n-icon><CopyOutline /></n-icon></template></n-button>
                </div>
              </div>

              <SecretRow v-for="(f, i) in secret.fields || []" :key="i" :label="f.label" :value="f.value" :masked="f.secret" @copy="copy(f.label, f.value)" />

              <div v-if="secret.notes" class="row">
                <div class="label">{{ t('vault.notes') }}</div>
                <div class="value notes">{{ secret.notes }}</div>
              </div>
            </template>
          </n-spin>

          <n-divider />
          <n-descriptions :column="1" size="small" label-placement="left">
            <n-descriptions-item :label="t('vault.type')">{{ t('vault.types.' + item?.type) }}</n-descriptions-item>
            <n-descriptions-item :label="t('vault.visibility')">{{ item?.visibility === 'private' ? t('vault.private') : t('vault.team') }}</n-descriptions-item>
            <n-descriptions-item :label="t('vault.creator')">{{ item?.creator || '-' }}</n-descriptions-item>
            <n-descriptions-item :label="t('vault.updated')">{{ fmt(item?.update_time) }}</n-descriptions-item>
          </n-descriptions>
        </n-tab-pane>

        <n-tab-pane name="logs" :tab="t('vault.logs')">
          <n-data-table :columns="logColumns" :data="logs" size="small" :max-height="520" />
        </n-tab-pane>
      </n-tabs>

      <template #footer>
        <n-button v-if="item?.can_edit" @click="emit('edit', item)">{{ t('common.edit') }}</n-button>
      </template>
    </n-drawer-content>
  </n-drawer>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { CopyOutline } from '@vicons/ionicons5'
import { api } from '../utils/api'
import { fmt } from '../utils/time'
import { lockedCall, copySecret, unlockRemaining } from '../utils/vault'
import SecretRow from './SecretRow.vue'

const props = defineProps({ show: Boolean, item: Object, canReveal: Boolean })
const emit = defineEmits(['update:show', 'edit'])
const { t } = useI18n()
const width = Math.min(480, window.innerWidth)
const tab = ref('detail')
const secret = ref(null)
const totp = ref(null)
const loading = ref(false)
const logs = ref([])
let timer = null

const logColumns = computed(() => [
  { title: t('notification.sentAt'), key: 'create_time', width: 140, render: (r) => fmt(r.create_time) },
  { title: t('auth.name'), key: 'user_name', width: 100 },
  { title: t('common.actions'), key: 'action', width: 80, render: (r) => t('vault.actions.' + r.action) },
  { title: '', key: 'detail', ellipsis: { tooltip: true } },
  { title: 'IP', key: 'ip', width: 120 },
])

watch(() => props.show, async (v) => {
  stop()
  secret.value = null
  totp.value = null
  tab.value = 'detail'
  if (!v || !props.item) return
  if (props.canReveal) {
    loading.value = true
    try {
      const res = await lockedCall(() => api.post(`/api/vault/${props.item.id}/reveal`))
      secret.value = res.secret
      if (res.secret.totp) refreshTotp()
    } catch { /* 取消解锁 */ } finally {
      loading.value = false
    }
  }
})

watch(tab, async (v) => {
  if (v === 'logs') logs.value = await api.get(`/api/vault/${props.item.id}/logs`, null, { silent: true }).catch(() => [])
})

// 保险箱重新锁定后清除明文
watch(unlockRemaining, (v) => {
  if (!v && secret.value) { secret.value = null; stop() }
})

async function refreshTotp() {
  stop()
  try {
    totp.value = await api.get(`/api/vault/${props.item.id}/totp`, null, { silent: true })
  } catch {
    return
  }
  timer = setInterval(() => {
    if (!totp.value) return
    totp.value.remaining -= 1
    if (totp.value.remaining <= 0) refreshTotp()
  }, 1000)
}

function stop() {
  clearInterval(timer)
  timer = null
}

function copy(field, value) {
  copySecret(props.item.id, field, value)
}

function safeUrl(url) {
  return /^https?:\/\//i.test(url) ? url : `https://${url}`
}

onBeforeUnmount(stop)
</script>

<style scoped>
.row { padding: 8px 0; border-bottom: 1px dashed rgba(127, 127, 127, .2); }
.label { font-size: 12px; opacity: .6; margin-bottom: 4px; }
.value { word-break: break-all; }
.link { color: #18a058; text-decoration: none; }
.notes { white-space: pre-wrap; font-size: 13px; }
.totp { display: flex; align-items: center; gap: 10px; }
.code { font-size: 26px; font-weight: 600; letter-spacing: 2px; }
</style>

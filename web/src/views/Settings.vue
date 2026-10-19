<template>
  <div class="page">
    <div class="page-header"><h2>{{ t('nav.settings') }}</h2></div>
    <div class="grid-2">
      <n-card :title="t('settings.profile')" size="small">
        <n-form :model="profile" label-placement="top" size="small">
          <n-form-item :label="t('auth.email')"><n-input :value="auth.user?.email" disabled /></n-form-item>
          <n-form-item :label="t('auth.name')"><n-input v-model:value="profile.name" /></n-form-item>
          <n-grid :cols="2" :x-gap="12">
            <n-gi>
              <n-form-item :label="t('settings.locale')"><n-select v-model:value="profile.locale" :options="LOCALES" /></n-form-item>
            </n-gi>
            <n-gi>
              <n-form-item :label="t('settings.notifyTime')">
                <n-time-picker v-model:formatted-value="profile.notify_time" value-format="HH:mm" format="HH:mm" style="width:100%" />
              </n-form-item>
            </n-gi>
          </n-grid>
          <n-form-item :label="t('settings.timezone')">
            <n-select v-model:value="profile.timezone" :options="tzOptions" filterable />
          </n-form-item>
          <div class="muted" style="margin: -8px 0 12px">{{ t('settings.notifyTimeHint') }}</div>
          <n-form-item :label="t('settings.notifyMode')">
            <n-radio-group v-model:value="profile.notify_mode">
              <n-space vertical :size="4">
                <n-radio v-for="m in ['smart', 'single', 'digest']" :key="m" :value="m">{{ t('settings.notifyModes.' + m) }}</n-radio>
              </n-space>
            </n-radio-group>
          </n-form-item>
          <n-button type="primary" size="small" @click="saveProfile">{{ t('common.save') }}</n-button>
        </n-form>
      </n-card>

      <n-space vertical :size="16">
        <n-card :title="t('settings.workspace')" size="small">
          <n-form label-placement="top" size="small">
            <n-form-item :label="t('settings.workspaceName')">
              <n-input-group>
                <n-input v-model:value="tenantName" :disabled="!isAdmin" />
                <n-button v-if="isAdmin" @click="saveTenant">{{ t('common.save') }}</n-button>
              </n-input-group>
            </n-form-item>
          </n-form>
          <n-descriptions :column="1" size="small" label-placement="left">
            <n-descriptions-item :label="t('settings.plan')">
              {{ auth.tenant?.plan?.name }}
              <span v-if="auth.tenant?.plan_expire_time" class="muted">（{{ t('settings.expireAt') }} {{ fmtDate(auth.tenant.plan_expire_time) }}）</span>
            </n-descriptions-item>
          </n-descriptions>
          <div v-for="u in usage" :key="u.key" class="usage">
            <div class="usage-label"><span>{{ t('settings.' + u.key) }}</span><span class="muted">{{ u.used }} / {{ u.limit < 0 ? '∞' : u.limit }}</span></div>
            <n-progress type="line" :percentage="u.limit < 0 ? 0 : Math.min(100, Math.round((u.used / Math.max(u.limit, 1)) * 100))" :show-indicator="false" :height="6" />
          </div>
        </n-card>

        <n-card :title="t('settings.password')" size="small">
          <n-form :model="pwd" label-placement="top" size="small">
            <n-form-item :label="t('settings.oldPassword')"><n-input v-model:value="pwd.old_password" type="password" /></n-form-item>
            <n-form-item :label="t('settings.newPassword')"><n-input v-model:value="pwd.password" type="password" :placeholder="t('auth.passwordHint')" /></n-form-item>
            <n-button size="small" @click="savePassword">{{ t('common.save') }}</n-button>
          </n-form>
        </n-card>
      </n-space>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, message } from '../utils/api'
import { useAuth } from '../stores/auth'
import { LOCALES, setLocale } from '../i18n'
import { fmtDate, setTimezone } from '../utils/time'

const { t } = useI18n()
const auth = useAuth()
const profile = reactive({ name: '', locale: 'zh-cn', timezone: 'UTC', notify_time: '09:00', notify_mode: 'smart' })
const pwd = reactive({ old_password: '', password: '' })
const tenantName = ref('')
const isAdmin = computed(() => ['owner', 'admin'].includes(auth.tenant?.role))

const tzOptions = computed(() => (auth.meta?.timezones || []).map((z) => ({ label: z, value: z })))
const usage = computed(() => {
  const p = auth.tenant?.plan || {}
  const u = auth.tenant?.usage || {}
  return ['domains', 'ssl', 'assets', 'tasks', 'channels', 'members', 'vault'].map((k) => ({ key: k, used: u[k] || 0, limit: p['max_' + k] ?? 0 }))
})

onMounted(async () => {
  await auth.loadMeta()
  await auth.fetchMe()
  const u = auth.user
  Object.assign(profile, { name: u.name, locale: u.locale, timezone: u.timezone, notify_time: u.notify_time, notify_mode: u.notify_mode || 'smart' })
  tenantName.value = auth.tenant.name
})

async function saveProfile() {
  const user = await api.put('/api/auth/profile', profile)
  auth.user = user
  setLocale(user.locale)
  setTimezone(user.timezone)
  message.success(t('common.save'))
}

async function saveTenant() {
  await api.put('/api/tenant', { name: tenantName.value })
  await auth.fetchMe()
  message.success(t('common.save'))
}

async function savePassword() {
  await api.put('/api/auth/password', pwd)
  pwd.old_password = ''
  pwd.password = ''
  message.success(t('common.save'))
}
</script>

<style scoped>
.usage { margin-top: 10px; }
.usage-label { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 4px; }
</style>

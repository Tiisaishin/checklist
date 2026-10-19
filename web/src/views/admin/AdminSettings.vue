<template>
  <div class="page">
    <div class="page-header"><h2>{{ t('nav.adminSettings') }}</h2></div>
    <div class="grid-2">
      <n-card size="small" :title="t('nav.adminSettings')">
        <n-form label-placement="top" size="small">
          <n-form-item :label="t('admin.siteName')"><n-input v-model:value="form.site_name" /></n-form-item>
          <n-form-item :label="t('admin.registerEnabled')"><n-switch v-model:value="form.register_enabled" /></n-form-item>
          <n-button type="primary" size="small" @click="save({ site_name: form.site_name, register_enabled: form.register_enabled })">{{ t('common.save') }}</n-button>
        </n-form>
      </n-card>

      <n-card size="small" :title="t('admin.systemBot')">
        <p class="muted" style="margin-top: 0">{{ t('admin.systemBotHint') }}</p>
        <n-space vertical v-if="data">
          <n-tag v-if="data.telegram_bot_set" type="success" :bordered="false">{{ t('admin.botSet', { name: data.telegram_bot_username }) }}</n-tag>
          <n-alert v-if="data.telegram_bot_set" :type="data.telegram_webhook ? 'success' : 'warning'" :bordered="false">
            {{ data.telegram_webhook ? t('admin.webhookOn') : t('admin.webhookOff') }}
          </n-alert>
          <n-input-group>
            <n-input v-model:value="token" type="password" show-password-on="click" :placeholder="t('admin.botToken')" class="mono" />
            <n-button type="primary" :disabled="!token" @click="saveBot">{{ t('common.save') }}</n-button>
          </n-input-group>
          <n-button v-if="data.telegram_bot_set" size="small" quaternary type="error" @click="save({ telegram_bot_clear: true })">{{ t('admin.clearBot') }}</n-button>
        </n-space>
      </n-card>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, message } from '../../utils/api'
import { useAuth } from '../../stores/auth'

const { t } = useI18n()
const auth = useAuth()
const data = ref(null)
const token = ref('')
const form = reactive({ site_name: '', register_enabled: true })

function apply(d) {
  data.value = d
  form.site_name = d.site_name
  form.register_enabled = d.register_enabled
}
async function save(payload) {
  apply(await api.put('/adminapi/settings', payload))
  auth.meta = null
  auth.loadMeta()
  message.success(t('common.save'))
}
async function saveBot() {
  await save({ telegram_bot_token: token.value })
  token.value = ''
}
onMounted(async () => apply(await api.get('/adminapi/settings')))
</script>

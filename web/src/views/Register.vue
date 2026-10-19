<template>
  <AuthShell>
    <n-alert v-if="auth.meta && !auth.meta.register_enabled && !inviteToken" type="warning" :bordered="false">{{ t('auth.registerClosed') }}</n-alert>
    <n-form v-else ref="formRef" :model="form" :rules="rules" @submit.prevent="submit">
      <n-alert v-if="inviteToken" type="info" :bordered="false" style="margin-bottom: 12px">{{ t('invite.joinedHint') }}</n-alert>
      <n-form-item :label="t('auth.name')" path="name">
        <n-input v-model:value="form.name" />
      </n-form-item>
      <n-form-item :label="t('auth.email')" path="email">
        <n-input v-model:value="form.email" :disabled="!!inviteToken" :input-props="{ type: 'email', autocomplete: 'email' }" />
      </n-form-item>
      <n-form-item :label="t('auth.password')" path="password">
        <n-input v-model:value="form.password" type="password" show-password-on="click" :placeholder="t('auth.passwordHint')" :input-props="{ autocomplete: 'new-password' }" />
      </n-form-item>
      <n-button type="primary" block :loading="loading" attr-type="submit">{{ t('auth.register') }}</n-button>
    </n-form>
    <div class="switch">
      {{ t('auth.hasAccount') }} <router-link to="/login">{{ t('auth.toLogin') }}</router-link>
    </div>
  </AuthShell>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '../components/AuthShell.vue'
import { useAuth } from '../stores/auth'

const { t, locale } = useI18n()
const auth = useAuth()
const router = useRouter()
const route = useRoute()
const inviteToken = route.query.invite || ''
const formRef = ref()
const loading = ref(false)
const form = reactive({ name: '', email: route.query.email || '', password: '' })
const rules = {
  email: { required: true, trigger: 'blur' },
  password: { required: true, min: 8, trigger: 'blur' },
}
auth.loadMeta()

async function submit() {
  await formRef.value.validate()
  loading.value = true
  try {
    await auth.register({
      ...form,
      locale: locale.value,
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      invite_token: inviteToken || undefined,
    })
    router.replace({ name: inviteToken ? 'dashboard' : 'channels' })
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.switch { margin-top: 16px; text-align: center; font-size: 13px; }
</style>

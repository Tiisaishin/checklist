<template>
  <AuthShell>
    <n-form ref="formRef" :model="form" :rules="rules" @submit.prevent="submit">
      <n-form-item :label="t('auth.email')" path="email">
        <n-input v-model:value="form.email" :input-props="{ type: 'email', autocomplete: 'email' }" />
      </n-form-item>
      <n-form-item :label="t('auth.password')" path="password">
        <n-input v-model:value="form.password" type="password" show-password-on="click" :input-props="{ autocomplete: 'current-password' }" @keyup.enter="submit" />
      </n-form-item>
      <n-button type="primary" block :loading="loading" attr-type="submit">{{ t('auth.login') }}</n-button>
    </n-form>
    <div class="switch" v-if="auth.meta?.register_enabled !== false">
      {{ t('auth.noAccount') }} <router-link to="/register">{{ t('auth.toRegister') }}</router-link>
    </div>
  </AuthShell>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '../components/AuthShell.vue'
import { useAuth } from '../stores/auth'

const { t } = useI18n()
const auth = useAuth()
const router = useRouter()
const route = useRoute()
const formRef = ref()
const loading = ref(false)
const form = reactive({ email: route.query.email || '', password: '' })
const rules = {
  email: { required: true, trigger: 'blur' },
  password: { required: true, trigger: 'blur' },
}
auth.loadMeta()

async function submit() {
  await formRef.value.validate()
  loading.value = true
  try {
    await auth.login(form.email, form.password)
    router.replace(route.query.redirect || '/')
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.switch { margin-top: 16px; text-align: center; font-size: 13px; }
</style>

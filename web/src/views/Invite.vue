<template>
  <AuthShell>
    <n-spin :show="loading">
      <n-result v-if="error" status="warning" :title="t('invite.invalid')" size="small">
        <template #footer><n-button @click="router.replace('/')">{{ t('common.back') }}</n-button></template>
      </n-result>

      <div v-else-if="info">
        <h3 class="title">{{ t('invite.title') }}</h3>
        <p>{{ t('invite.text', { inviter: info.inviter, tenant: info.tenant, role: t('member.roles.' + info.role) }) }}</p>
        <p class="muted">{{ t('invite.forEmail', { email: info.email }) }}</p>

        <template v-if="auth.loggedIn && auth.user">
          <n-alert v-if="mismatch" type="warning" :bordered="false" style="margin-bottom: 12px">
            {{ t('invite.mismatch', { current: auth.user.email, email: info.email }) }}
          </n-alert>
          <n-button v-if="!mismatch" type="primary" block :loading="accepting" @click="accept">{{ t('invite.accept') }}</n-button>
          <n-button v-else block @click="switchAccount">{{ t('invite.switchAccount') }}</n-button>
        </template>
        <n-space v-else vertical>
          <n-button v-if="info.has_account" type="primary" block @click="toLogin">{{ t('invite.loginToAccept') }}</n-button>
          <n-button v-else type="primary" block @click="toRegister">{{ t('invite.registerToAccept') }}</n-button>
        </n-space>
      </div>
    </n-spin>
  </AuthShell>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '../components/AuthShell.vue'
import { api, message } from '../utils/api'
import { useAuth } from '../stores/auth'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuth()
const token = route.params.token
const info = ref(null)
const error = ref(false)
const loading = ref(true)
const accepting = ref(false)

const mismatch = computed(() => auth.user && info.value && auth.user.email.toLowerCase() !== info.value.email.toLowerCase())

onMounted(async () => {
  auth.loadMeta()
  try {
    info.value = await api.get(`/api/invitations/${token}`, null, { silent: true })
    if (auth.loggedIn && !auth.user) await auth.fetchMe().catch(() => {})
  } catch {
    error.value = true
  } finally {
    loading.value = false
  }
})

async function accept() {
  accepting.value = true
  try {
    const res = await api.post(`/api/invitations/${token}/accept`)
    localStorage.setItem('tenant', res.tenant_id)
    message.success(t('invite.joined', { tenant: res.tenant }))
    location.href = '/'
  } finally {
    accepting.value = false
  }
}

function toLogin() {
  router.push({ name: 'login', query: { redirect: route.fullPath, email: info.value.email } })
}

function toRegister() {
  router.push({ name: 'register', query: { invite: token, email: info.value.email } })
}

async function switchAccount() {
  await auth.logout()
  toLogin()
}
</script>

<style scoped>
.title { margin: 0 0 8px; font-size: 18px; }
</style>

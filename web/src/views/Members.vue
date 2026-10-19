<template>
  <div class="page">
    <div class="page-header">
      <h2>{{ t('member.title') }}</h2>
      <n-space>
        <n-button v-if="data && data.my_role !== 'owner'" @click="leave">{{ t('member.leave') }}</n-button>
        <n-button v-if="canInvite" type="primary" @click="openInvite">
          <template #icon><n-icon><PersonAddOutline /></n-icon></template>{{ t('member.invite') }}
        </n-button>
      </n-space>
    </div>
    <n-alert type="info" :bordered="false" style="margin-bottom: 16px">{{ t('member.desc') }}</n-alert>

    <n-data-table :columns="columns" :data="data?.members || []" :loading="loading" size="small" :scroll-x="760" />

    <template v-if="data?.invitations?.length">
      <h3 class="sub">{{ t('member.pending') }}</h3>
      <n-data-table :columns="inviteColumns" :data="data.invitations" size="small" :scroll-x="560" />
    </template>

    <n-modal v-model:show="inviteVisible" preset="card" :title="t('member.invite')" style="max-width: 480px">
      <template v-if="!inviteLink">
        <n-form ref="formRef" :model="form" :rules="rules" label-placement="top" size="small">
          <n-form-item :label="t('member.email')" path="email">
            <n-input v-model:value="form.email" :input-props="{ type: 'email' }" placeholder="name@example.com" />
          </n-form-item>
          <n-form-item :label="t('member.role')">
            <n-radio-group v-model:value="form.role">
              <n-space vertical :size="6">
                <n-radio v-for="r in data?.assignable || []" :key="r" :value="r">
                  {{ t('member.roles.' + r) }} <span class="muted">— {{ t('member.roleDesc.' + r) }}</span>
                </n-radio>
              </n-space>
            </n-radio-group>
          </n-form-item>
        </n-form>
      </template>
      <template v-else>
        <div class="muted" style="margin-bottom: 8px">{{ t('member.inviteLinkTip', { email: form.email }) }}</div>
        <n-input-group>
          <n-input :value="inviteLink" readonly class="mono" />
          <n-button type="primary" @click="copy(inviteLink)">{{ t('member.copyLink') }}</n-button>
        </n-input-group>
      </template>
      <template #action>
        <n-space justify="end">
          <n-button @click="inviteVisible = false">{{ inviteLink ? t('common.close') : t('common.cancel') }}</n-button>
          <n-button v-if="!inviteLink" type="primary" :loading="saving" @click="invite">{{ t('member.invite') }}</n-button>
        </n-space>
      </template>
    </n-modal>
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { NButton, NSelect, NSpace, NTag } from 'naive-ui'
import { PersonAddOutline } from '@vicons/ionicons5'
import { api, dialog, message } from '../utils/api'
import { fmt } from '../utils/time'
import { useAuth } from '../stores/auth'

const { t } = useI18n()
const auth = useAuth()
const data = ref(null)
const loading = ref(false)
const inviteVisible = ref(false)
const inviteLink = ref('')
const saving = ref(false)
const formRef = ref()
const form = reactive({ email: '', role: 'member' })
const rules = { email: { required: true, type: 'email', trigger: 'blur' } }

const assignable = computed(() => data.value?.assignable || [])
const canInvite = computed(() => assignable.value.length > 0)
const roleType = { owner: 'warning', admin: 'success', member: 'info', viewer: 'default' }

const columns = computed(() => [
  {
    title: t('auth.name'), key: 'name', minWidth: 200,
    render: (r) => h('div', [
      h('div', { style: 'font-weight:500' }, [r.name || '-', r.id === auth.user?.id ? h('span', { class: 'muted' }, ` (${t('member.you')})`) : null]),
      h('div', { class: 'muted' }, r.email),
    ]),
  },
  {
    title: t('member.role'), key: 'role', width: 150,
    render: (r) => (manageable(r)
      ? h(NSelect, { size: 'small', value: r.role, options: assignable.value.map((x) => ({ value: x, label: t('member.roles.' + x) })), onUpdateValue: (v) => changeRole(r, v) })
      : h(NTag, { size: 'small', bordered: false, type: roleType[r.role] }, () => t('member.roles.' + r.role))),
  },
  { title: t('member.joined'), key: 'join_time', width: 150, render: (r) => fmt(r.join_time) },
  { title: t('member.lastLogin'), key: 'last_login_time', width: 150, render: (r) => fmt(r.last_login_time) },
  {
    title: t('common.actions'), key: 'actions', width: 190,
    render: (r) => h(NSpace, { size: 4 }, () => [
      data.value?.my_role === 'owner' && r.id !== auth.user?.id
        ? h(NButton, { size: 'tiny', quaternary: true, onClick: () => transfer(r) }, () => t('member.transfer')) : null,
      manageable(r) ? h(NButton, { size: 'tiny', quaternary: true, type: 'error', onClick: () => remove(r) }, () => t('member.remove')) : null,
    ]),
  },
])

const inviteColumns = computed(() => [
  { title: t('member.email'), key: 'email', minWidth: 200 },
  { title: t('member.role'), key: 'role', width: 110, render: (r) => t('member.roles.' + r.role) },
  { title: t('member.expires'), key: 'expire_time', width: 150, render: (r) => fmt(r.expire_time) },
  {
    title: t('common.actions'), key: 'actions', width: 90,
    render: (r) => h(NButton, { size: 'tiny', quaternary: true, type: 'error', onClick: () => revoke(r) }, () => t('member.revoke')),
  },
])

function manageable(r) {
  return r.id !== auth.user?.id && assignable.value.includes(r.role)
}

async function load() {
  loading.value = true
  try { data.value = await api.get('/api/members') } finally { loading.value = false }
}

function openInvite() {
  Object.assign(form, { email: '', role: assignable.value.includes('member') ? 'member' : assignable.value[0] })
  inviteLink.value = ''
  inviteVisible.value = true
}

async function invite() {
  await formRef.value.validate()
  saving.value = true
  try {
    const res = await api.post('/api/members/invite', form)
    inviteLink.value = `${location.origin}/invite/${res.token}`
    load()
  } finally {
    saving.value = false
  }
}

async function changeRole(r, role) {
  await api.put(`/api/members/${r.id}`, { role })
  message.success(t('common.save'))
  load()
}

function confirm(content, onOk) {
  dialog.warning({ title: t('common.actions'), content, positiveText: t('common.yes'), negativeText: t('common.cancel'), onPositiveClick: onOk })
}

function remove(r) {
  confirm(t('member.removeConfirm', { name: r.name || r.email }), async () => { await api.del(`/api/members/${r.id}`); load() })
}

function transfer(r) {
  confirm(t('member.transferConfirm', { name: r.name || r.email }), async () => {
    await api.post(`/api/members/${r.id}/transfer`)
    await auth.fetchMe()
    load()
  })
}

function leave() {
  confirm(t('member.leaveConfirm', { name: auth.tenant?.name }), async () => {
    await api.post('/api/members/leave')
    localStorage.removeItem('tenant')
    location.href = '/'
  })
}

async function revoke(r) {
  await api.del(`/api/members/invitations/${r.id}`)
  load()
}

async function copy(text) {
  try { await navigator.clipboard.writeText(text); message.success(t('common.copied')) } catch { /* ignore */ }
}

onMounted(load)
</script>

<style scoped>
.sub { font-size: 15px; font-weight: 600; margin: 24px 0 10px; }
</style>

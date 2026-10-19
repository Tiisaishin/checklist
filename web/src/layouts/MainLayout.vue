<template>
  <n-layout has-sider style="height: 100vh">
    <n-layout-sider
      bordered collapse-mode="width" :collapsed-width="64" :width="220" :collapsed="collapsed"
      show-trigger @collapse="ui.toggleCollapsed()" @expand="ui.toggleCollapsed()" :native-scrollbar="false"
    >
      <div class="logo" @click="router.push('/')">
        <img src="/favicon.svg" alt="" />
        <span v-if="!collapsed">{{ auth.meta?.site_name || t('app.name') }}</span>
      </div>
      <n-menu :value="activeKey" :options="menuOptions" :collapsed="collapsed" :collapsed-width="64" @update:value="go" />
    </n-layout-sider>
    <n-layout>
      <n-layout-header bordered class="header">
        <div class="header-left">
          <n-button v-if="isMobile" quaternary size="small" @click="ui.toggleCollapsed()">
            <template #icon><n-icon><MenuOutline /></n-icon></template>
          </n-button>
          <n-dropdown v-if="auth.tenants.length > 1" :options="tenantOptions" @select="switchTenant">
            <n-button quaternary size="small">{{ auth.tenant?.name }} <n-icon style="margin-left:4px"><ChevronDownOutline /></n-icon></n-button>
          </n-dropdown>
          <span v-else class="tenant-name">{{ auth.tenant?.name }}</span>
          <n-tag size="small" :bordered="false" type="success">{{ auth.tenant?.plan?.name }}</n-tag>
        </div>
        <div class="header-right">
          <LangSwitch />
          <n-button quaternary size="small" circle @click="ui.toggleTheme()" :title="t('nav.theme')">
            <template #icon><n-icon><MoonOutline v-if="!ui.dark" /><SunnyOutline v-else /></n-icon></template>
          </n-button>
          <n-dropdown :options="userOptions" @select="onUser">
            <n-button quaternary size="small">
              <template #icon><n-icon><PersonCircleOutline /></n-icon></template>
              {{ auth.user?.name }}
            </n-button>
          </n-dropdown>
        </div>
      </n-layout-header>
      <n-layout-content :native-scrollbar="false" content-style="min-height: calc(100vh - 56px)">
        <router-view />
      </n-layout-content>
    </n-layout>
  </n-layout>
</template>

<script setup>
import { computed, h, onMounted, onBeforeUnmount, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { NIcon } from 'naive-ui'
import {
  GridOutline, TimeOutline, CheckboxOutline, PaperPlaneOutline, DocumentTextOutline, ListOutline,
  SettingsOutline, ShieldCheckmarkOutline, PeopleOutline, BusinessOutline, PricetagsOutline, ConstructOutline,
  MoonOutline, SunnyOutline, PersonCircleOutline, LogOutOutline, MenuOutline, ChevronDownOutline, StatsChartOutline, FolderOpenOutline, PeopleCircleOutline, LockClosedOutline, GlobeOutline,
} from '@vicons/ionicons5'
import { useAuth } from '../stores/auth'
import { useUi } from '../stores/ui'
import LangSwitch from '../components/LangSwitch.vue'
import { api } from '../utils/api'

const { t } = useI18n()
const auth = useAuth()
const ui = useUi()
const route = useRoute()
const router = useRouter()

const isMobile = ref(window.innerWidth < 768)
const onResize = () => { isMobile.value = window.innerWidth < 768 }
onMounted(() => {
  window.addEventListener('resize', onResize)
  if (isMobile.value) ui.collapsed = true
  auth.loadMeta()
})
onBeforeUnmount(() => window.removeEventListener('resize', onResize))
const collapsed = computed(() => ui.collapsed)

const icon = (c) => () => h(NIcon, null, { default: () => h(c) })

const menuOptions = computed(() => {
  const items = [
    { key: 'dashboard', label: t('nav.dashboard'), icon: icon(GridOutline) },
    { key: 'domains', label: t('nav.domains'), icon: icon(GlobeOutline) },
    { key: 'ssl', label: t('nav.ssl'), icon: icon(ShieldCheckmarkOutline) },
    { key: 'assets', label: t('nav.assets'), icon: icon(TimeOutline) },
    { key: 'tasks', label: t('nav.tasks'), icon: icon(CheckboxOutline) },
    { key: 'vault', label: t('nav.vault'), icon: icon(LockClosedOutline) },
    { key: 'groups', label: t('nav.groups'), icon: icon(FolderOpenOutline) },
    { key: 'members', label: t('nav.members'), icon: icon(PeopleCircleOutline) },
    { type: 'divider' },
    { key: 'channels', label: t('nav.channels'), icon: icon(PaperPlaneOutline) },
    { key: 'templates', label: t('nav.templates'), icon: icon(DocumentTextOutline) },
    { key: 'notifications', label: t('nav.notifications'), icon: icon(ListOutline) },
    { key: 'settings', label: t('nav.settings'), icon: icon(SettingsOutline) },
  ]
  if (auth.isSuper) {
    items.push({ type: 'divider' }, {
      key: 'admin-group', label: t('nav.admin'), icon: icon(ShieldCheckmarkOutline),
      children: [
        { key: 'admin', label: t('nav.adminOverview'), icon: icon(StatsChartOutline) },
        { key: 'admin-users', label: t('nav.adminUsers'), icon: icon(PeopleOutline) },
        { key: 'admin-tenants', label: t('nav.adminTenants'), icon: icon(BusinessOutline) },
        { key: 'admin-plans', label: t('nav.adminPlans'), icon: icon(PricetagsOutline) },
        { key: 'admin-settings', label: t('nav.adminSettings'), icon: icon(ConstructOutline) },
      ],
    })
  }
  return items
})

const activeKey = computed(() => route.name)
function go(key) {
  router.push({ name: key })
  if (isMobile.value) ui.collapsed = true
}

const userOptions = computed(() => [
  { key: 'settings', label: t('nav.settings'), icon: icon(SettingsOutline) },
  { key: 'logout', label: t('nav.logout'), icon: icon(LogOutOutline) },
])
async function onUser(key) {
  if (key === 'logout') {
    await auth.logout()
    router.replace({ name: 'login' })
  } else {
    router.push({ name: key })
  }
}

const tenantOptions = computed(() => auth.tenants.map((x) => ({ key: x.id, label: x.name })))
async function switchTenant(id) {
  await api.post('/api/tenant/switch', { tenant_id: id })
  localStorage.setItem('tenant', id)
  location.reload()
}
</script>

<style scoped>
.logo { height: 56px; display: flex; align-items: center; gap: 10px; padding: 0 20px; font-weight: 600; font-size: 16px; cursor: pointer; white-space: nowrap; overflow: hidden; }
.logo img { width: 26px; height: 26px; flex-shrink: 0; }
.header { height: 56px; display: flex; align-items: center; justify-content: space-between; padding: 0 16px; }
.header-left, .header-right { display: flex; align-items: center; gap: 8px; }
.tenant-name { font-weight: 500; }
</style>

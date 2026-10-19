import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from './stores/auth'

const routes = [
  { path: '/login', name: 'login', component: () => import('./views/Login.vue'), meta: { auth: false } },
  { path: '/register', name: 'register', component: () => import('./views/Register.vue'), meta: { auth: false } },
  { path: '/invite/:token', name: 'invite', component: () => import('./views/Invite.vue'), meta: { auth: false } },
  {
    path: '/',
    component: () => import('./layouts/MainLayout.vue'),
    children: [
      { path: '', name: 'dashboard', component: () => import('./views/Dashboard.vue') },
      { path: 'assets', name: 'assets', component: () => import('./views/Assets.vue') },
      { path: 'tasks', name: 'tasks', component: () => import('./views/Tasks.vue') },
      { path: 'groups', name: 'groups', component: () => import('./views/Groups.vue') },
      { path: 'members', name: 'members', component: () => import('./views/Members.vue') },
      { path: 'channels', name: 'channels', component: () => import('./views/Channels.vue') },
      { path: 'templates', name: 'templates', component: () => import('./views/Templates.vue') },
      { path: 'notifications', name: 'notifications', component: () => import('./views/Notifications.vue') },
      { path: 'settings', name: 'settings', component: () => import('./views/Settings.vue') },
      { path: 'admin', name: 'admin', component: () => import('./views/admin/AdminDashboard.vue'), meta: { super: true } },
      { path: 'admin/users', name: 'admin-users', component: () => import('./views/admin/AdminUsers.vue'), meta: { super: true } },
      { path: 'admin/tenants', name: 'admin-tenants', component: () => import('./views/admin/AdminTenants.vue'), meta: { super: true } },
      { path: 'admin/plans', name: 'admin-plans', component: () => import('./views/admin/AdminPlans.vue'), meta: { super: true } },
      { path: 'admin/settings', name: 'admin-settings', component: () => import('./views/admin/AdminSettings.vue'), meta: { super: true } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({ history: createWebHistory(), routes })

router.beforeEach(async (to) => {
  const auth = useAuth()
  if (to.meta.auth === false) {
    return auth.loggedIn && ['login', 'register'].includes(to.name) ? { name: 'dashboard' } : true
  }
  if (!auth.loggedIn) return { name: 'login', query: { redirect: to.fullPath } }
  if (!auth.user) {
    try { await auth.fetchMe() } catch { return { name: 'login' } }
  }
  if (to.meta.super && !auth.isSuper) return { name: 'dashboard' }
  return true
})

export default router

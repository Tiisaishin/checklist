import { defineStore } from 'pinia'
import { api, TOKEN_KEY } from '../utils/api'
import { setLocale } from '../i18n'
import { setTimezone } from '../utils/time'

export const useAuth = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem(TOKEN_KEY) || '',
    user: null,
    tenant: null,
    tenants: [],
    meta: null,
  }),
  getters: {
    loggedIn: (s) => !!s.token,
    isSuper: (s) => !!s.user?.is_super,
    canWrite: (s) => s.tenant?.role !== 'viewer',
  },
  actions: {
    async loadMeta() {
      if (!this.meta) this.meta = await api.get('/api/meta', null, { silent: true })
      return this.meta
    },
    setToken(token) {
      this.token = token
      localStorage.setItem(TOKEN_KEY, token)
    },
    async fetchMe() {
      const data = await api.get('/api/auth/me')
      this.user = data.user
      this.tenant = data.tenant
      this.tenants = data.tenants
      localStorage.setItem('tenant', data.tenant.id)
      setTimezone(data.user.timezone)
      if (data.user.locale) setLocale(data.user.locale)
      return data
    },
    async login(email, password) {
      const data = await api.post('/api/auth/login', { email, password })
      this.setToken(data.token)
      await this.fetchMe()
    },
    async register(payload) {
      const data = await api.post('/api/auth/register', payload)
      this.setToken(data.token)
      // 通过邀请注册时，后端已切换到被邀请的空间
      localStorage.removeItem('tenant')
      await this.fetchMe()
    },
    async logout() {
      try { await api.post('/api/auth/logout', {}, { silent: true }) } catch { /* ignore */ }
      this.token = ''
      this.user = null
      localStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem('tenant')
    },
  },
})

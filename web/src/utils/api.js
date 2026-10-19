import { createDiscreteApi } from 'naive-ui'
import i18n from '../i18n'
import router from '../router'

export const { message, dialog } = createDiscreteApi(['message', 'dialog'])

export const TOKEN_KEY = 'token'

async function request(method, url, data, { silent = false } = {}) {
  const headers = { Accept: 'application/json', 'Think-Lang': i18n.global.locale.value }
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) headers.Authorization = `Bearer ${token}`
  const tenant = localStorage.getItem('tenant')
  if (tenant) headers['X-Tenant-Id'] = tenant

  let fullUrl = url
  const options = { method, headers }
  if (data && method === 'GET') {
    const qs = new URLSearchParams(Object.entries(data).filter(([, v]) => v !== undefined && v !== null && v !== '')).toString()
    if (qs) fullUrl += (url.includes('?') ? '&' : '?') + qs
  } else if (data) {
    headers['Content-Type'] = 'application/json'
    options.body = JSON.stringify(data)
  }

  let res, body
  try {
    res = await fetch(fullUrl, options)
    body = await res.json()
  } catch (e) {
    if (!silent) message.error(e.message || 'Network error')
    throw e
  }

  if (res.status === 401) {
    localStorage.removeItem(TOKEN_KEY)
    if (router.currentRoute.value.meta.auth !== false) router.replace({ name: 'login' })
  }
  if (!res.ok || body.code !== 0) {
    const err = new Error(body?.message || `HTTP ${res.status}`)
    err.status = res.status
    err.data = body?.data
    if (!silent) message.error(err.message)
    throw err
  }
  return body.data
}

export const api = {
  get: (url, params, opts) => request('GET', url, params, opts),
  post: (url, data, opts) => request('POST', url, data || {}, opts),
  put: (url, data, opts) => request('PUT', url, data || {}, opts),
  del: (url, opts) => request('DELETE', url, null, opts),
}

export function confirmDelete(onOk) {
  const t = i18n.global.t
  dialog.warning({
    title: t('common.delete'),
    content: t('common.confirmDelete'),
    positiveText: t('common.delete'),
    negativeText: t('common.cancel'),
    onPositiveClick: onOk,
  })
}

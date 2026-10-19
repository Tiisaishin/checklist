import { ref } from 'vue'
import { api, message } from './api'
import i18n from '../i18n'

/** 保险箱解锁状态（剩余秒数），整个页面共享 */
export const unlockRemaining = ref(0)
export const unlockVisible = ref(false)
let pending = null
let timer = null

function tick() {
  clearInterval(timer)
  timer = setInterval(() => {
    unlockRemaining.value = Math.max(0, unlockRemaining.value - 1)
    if (!unlockRemaining.value) clearInterval(timer)
  }, 1000)
}

export function setUnlocked(seconds) {
  unlockRemaining.value = seconds || 0
  if (seconds) tick()
}

/** 需要解锁的操作：已解锁直接执行，否则弹出解锁框，解锁后继续 */
export function withUnlock(fn) {
  if (unlockRemaining.value > 5) return fn()
  return new Promise((resolve, reject) => {
    pending = { fn, resolve, reject }
    unlockVisible.value = true
  })
}

export async function unlock(password) {
  const res = await api.post('/api/vault/unlock', { password })
  setUnlocked(res.unlocked)
  unlockVisible.value = false
  if (pending) {
    const { fn, resolve, reject } = pending
    pending = null
    Promise.resolve().then(fn).then(resolve, reject)
  }
}

export function cancelUnlock() {
  pending = null
  unlockVisible.value = false
}

export async function lock() {
  await api.post('/api/vault/lock')
  setUnlocked(0)
}

/** 调用需要解锁的接口；如果服务端返回已锁定（423），重新弹出解锁框 */
export async function lockedCall(fn) {
  return withUnlock(async () => {
    try {
      return await fn()
    } catch (e) {
      if (e.status === 423) {
        setUnlocked(0)
        return withUnlock(fn)
      }
      throw e
    }
  })
}

export async function copySecret(itemId, field, value) {
  try {
    await navigator.clipboard.writeText(value)
    message.success(i18n.global.t('common.copied'))
    api.post(`/api/vault/${itemId}/log`, { field }, { silent: true })
  } catch { /* ignore */ }
}

export function generatePassword(length = 20) {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*-_=+'
  const buf = new Uint32Array(length)
  crypto.getRandomValues(buf)
  return Array.from(buf, (n) => chars[n % chars.length]).join('')
}

export function fmtRemaining(s) {
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}

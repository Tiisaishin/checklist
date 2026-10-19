import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'
import timezone from 'dayjs/plugin/timezone'

dayjs.extend(utc)
dayjs.extend(timezone)

let currentTz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'

export function setTimezone(tz) {
  if (tz) currentTz = tz
}

/** 数据库 UTC 字符串 => 用户时区格式化 */
export function fmt(value, pattern = 'YYYY-MM-DD HH:mm') {
  if (!value) return '-'
  return dayjs.utc(value).tz(currentTz).format(pattern)
}

export function fmtDate(value) {
  return fmt(value, 'YYYY-MM-DD')
}

/** 用户时区的墙上时间 "YYYY-MM-DD HH:mm" => ISO（带时区）提交给后端 */
export function toServer(local) {
  if (!local) return null
  return dayjs.tz(local, currentTz).toISOString()
}

/** UTC => 用户时区墙上时间，用于表单回填 */
export function toLocalInput(value, pattern = 'YYYY-MM-DD HH:mm') {
  if (!value) return null
  return dayjs.utc(value).tz(currentTz).format(pattern)
}

export function today() {
  return dayjs().tz(currentTz).format('YYYY-MM-DD')
}

export { dayjs }

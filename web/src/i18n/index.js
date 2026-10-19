import { createI18n } from 'vue-i18n'
import zhCN from './zh-cn'
import en from './en'

export const LOCALES = [
  { value: 'zh-cn', label: '简体中文' },
  { value: 'en', label: 'English' },
]

function initialLocale() {
  const saved = localStorage.getItem('locale')
  if (saved && LOCALES.some((l) => l.value === saved)) return saved
  return (navigator.language || '').toLowerCase().startsWith('zh') ? 'zh-cn' : 'en'
}

const i18n = createI18n({
  legacy: false,
  locale: initialLocale(),
  fallbackLocale: 'en',
  messages: { 'zh-cn': zhCN, en },
  warnHtmlMessage: false,
})

export function setLocale(locale) {
  i18n.global.locale.value = locale
  localStorage.setItem('locale', locale)
  document.documentElement.lang = locale === 'zh-cn' ? 'zh-CN' : 'en'
}

export default i18n

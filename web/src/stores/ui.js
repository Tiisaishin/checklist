import { defineStore } from 'pinia'

const media = window.matchMedia('(prefers-color-scheme: dark)')

export const useUi = defineStore('ui', {
  state: () => ({
    theme: localStorage.getItem('theme') || 'auto',
    systemDark: media.matches,
    collapsed: localStorage.getItem('collapsed') === '1',
  }),
  getters: {
    dark: (s) => (s.theme === 'auto' ? s.systemDark : s.theme === 'dark'),
  },
  actions: {
    init() {
      media.addEventListener('change', (e) => { this.systemDark = e.matches })
    },
    toggleTheme() {
      this.theme = this.dark ? 'light' : 'dark'
      localStorage.setItem('theme', this.theme)
    },
    toggleCollapsed() {
      this.collapsed = !this.collapsed
      localStorage.setItem('collapsed', this.collapsed ? '1' : '0')
    },
  },
})

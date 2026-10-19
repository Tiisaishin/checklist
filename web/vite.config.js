import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import Components from 'unplugin-vue-components/vite'
import { NaiveUiResolver } from 'unplugin-vue-components/resolvers'

const backend = process.env.BACKEND_URL || 'http://127.0.0.1:8000'

export default defineConfig({
  plugins: [
    vue(),
    // 按需自动注册 Naive UI 组件
    Components({ resolvers: [NaiveUiResolver()], dts: false }),
  ],
  server: {
    port: 5173,
    proxy: {
      '/api': backend,
      '/adminapi': backend,
      '/bot': backend,
    },
  },
  build: {
    outDir: 'dist',
    chunkSizeWarningLimit: 1500,
  },
})

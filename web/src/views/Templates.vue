<template>
  <div class="page">
    <div class="page-header"><h2>{{ t('template.title') }}</h2></div>
    <n-alert type="info" :bordered="false" style="margin-bottom: 16px">{{ t('template.desc') }}</n-alert>

    <n-spin :show="loading">
      <div class="grid-2" style="grid-template-columns: 1fr">
        <n-card v-for="tpl in list" :key="tpl.scene" size="small">
          <template #header>
            {{ t('template.scenes.' + tpl.scene) }}
            <n-tag v-if="tpl.custom" size="small" type="success" :bordered="false" style="margin-left: 8px">{{ t('template.custom') }}</n-tag>
          </template>
          <div class="editor">
            <div>
              <n-input
                :ref="(el) => (inputs[tpl.scene] = el)" v-model:value="tpl.content" type="textarea" class="mono"
                :autosize="{ minRows: 8, maxRows: 18 }" @update:value="() => schedulePreview(tpl)"
              />
              <div class="vars">
                <span class="muted">{{ t('template.variables') }}:</span>
                <n-tag v-for="v in tpl.variables" :key="v" size="small" checkable @click="insert(tpl, v)" :title="t('template.vars.' + v)">
                  {{ '{' + v + '}' }}
                </n-tag>
              </div>
            </div>
            <div>
              <div class="muted" style="margin-bottom: 6px">{{ t('common.preview') }}</div>
              <div class="tg-preview" v-html="safe(previews[tpl.scene])" />
            </div>
          </div>
          <template #action>
            <n-space justify="end">
              <n-button size="small" :disabled="!tpl.custom" @click="reset(tpl)">{{ t('common.reset') }}</n-button>
              <n-button size="small" type="primary" @click="save(tpl)">{{ t('common.save') }}</n-button>
            </n-space>
          </template>
        </n-card>
      </div>
    </n-spin>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, message } from '../utils/api'

const { t } = useI18n()
const list = ref([])
const loading = ref(false)
const previews = reactive({})
const inputs = reactive({})
const timers = {}

/** 只保留 Telegram 支持的少量标签，其他全部转义 */
function safe(html = '') {
  const escaped = html.replace(/&(?!(amp|lt|gt|quot);)/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  return escaped
    .replace(/&lt;(\/?)(b|strong|i|em|u|s|code|pre)&gt;/g, '<$1$2>')
    .replace(/&lt;a href="(https?:\/\/[^"<>]+)"&gt;/g, '<a href="$1" target="_blank" rel="noopener">')
    .replace(/&lt;\/a&gt;/g, '</a>')
}

async function load() {
  loading.value = true
  try {
    list.value = await api.get('/api/templates')
    list.value.forEach(preview)
  } finally { loading.value = false }
}

async function preview(tpl) {
  if (!tpl.content) { previews[tpl.scene] = ''; return }
  const res = await api.post('/api/templates/preview', { scene: tpl.scene, content: tpl.content }, { silent: true })
  previews[tpl.scene] = res.html
}

function schedulePreview(tpl) {
  clearTimeout(timers[tpl.scene])
  timers[tpl.scene] = setTimeout(() => preview(tpl), 400)
}

function insert(tpl, v) {
  const el = inputs[tpl.scene]?.textareaElRef
  const token = `{${v}}`
  if (el) {
    const start = el.selectionStart ?? tpl.content.length
    const end = el.selectionEnd ?? start
    tpl.content = tpl.content.slice(0, start) + token + tpl.content.slice(end)
  } else {
    tpl.content += token
  }
  schedulePreview(tpl)
}

async function save(tpl) {
  await api.put(`/api/templates/${tpl.scene}`, { content: tpl.content })
  tpl.custom = true
  message.success(t('common.save'))
}

async function reset(tpl) {
  await api.del(`/api/templates/${tpl.scene}`)
  tpl.content = tpl.default
  tpl.custom = false
  preview(tpl)
}

onMounted(load)
</script>

<style scoped>
.editor { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.vars { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; align-items: center; }
@media (max-width: 800px) { .editor { grid-template-columns: 1fr; } }
</style>

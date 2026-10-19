<template>
  <div class="page">
    <div class="page-header"><h2>{{ t('template.title') }}</h2></div>
    <n-alert type="info" :bordered="false" style="margin-bottom: 16px">
      {{ t('template.desc') }}
      <div class="muted" style="margin-top: 4px">{{ t('template.syntax', { a: '{name}', b: '{?name}…{/name}', c: '{!name}…{/name}' }) }}</div>
    </n-alert>

    <n-spin :show="loading">
      <n-tabs v-model:value="tab" type="line">
        <n-tab-pane v-for="(list, group) in groups" :key="group" :name="group" :tab="t('template.groups.' + group)">
          <div class="scenes">
            <n-menu :value="current" :options="sceneOptions(list)" @update:value="(v) => (current = v)" class="scene-menu" />
            <n-card v-if="active" size="small" class="editor-card">
              <template #header>
                {{ t('template.scenes.' + active.scene) }}
                <n-tag v-if="active.custom" size="small" type="success" :bordered="false" style="margin-left: 8px">{{ t('template.custom') }}</n-tag>
              </template>
              <template #header-extra>
                <n-dropdown :options="presetOptions(active)" @select="(k) => usePreset(active, k)">
                  <n-button size="small" quaternary>{{ t('template.usePreset') }}</n-button>
                </n-dropdown>
              </template>

              <div class="editor">
                <div>
                  <n-input
                    :ref="(el) => (inputs[active.scene] = el)" v-model:value="active.content" type="textarea" class="mono"
                    :autosize="{ minRows: 10, maxRows: 22 }" @update:value="() => schedulePreview(active)"
                  />
                  <div class="vars">
                    <span class="muted">{{ t('template.variables') }}:</span>
                    <n-tooltip v-for="v in active.variables" :key="v">
                      <template #trigger>
                        <n-tag size="small" checkable @click="insert(active, v)">{{ '{' + v + '}' }}</n-tag>
                      </template>
                      {{ t('template.vars.' + v) }}
                    </n-tooltip>
                  </div>
                </div>
                <div>
                  <div class="preview-head">
                    <span class="muted">{{ t('common.preview') }}</span>
                    <n-select
                      v-if="targetOptions(active.scene).length > 1" :value="targets[active.scene] || 'sample'" size="tiny" style="width: 200px"
                      :options="targetOptions(active.scene)" filterable @update:value="(v) => { targets[active.scene] = v; preview(active) }"
                    />
                  </div>
                  <div class="tg-preview" v-html="safe(previews[active.scene])" />
                </div>
              </div>
              <template #action>
                <n-space justify="end">
                  <n-button size="small" :disabled="!active.custom" @click="reset(active)">{{ t('common.reset') }}</n-button>
                  <n-button size="small" type="primary" @click="save(active)">{{ t('common.save') }}</n-button>
                </n-space>
              </template>
            </n-card>
          </div>
        </n-tab-pane>
      </n-tabs>
    </n-spin>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, message } from '../utils/api'

const { t } = useI18n()
const groups = ref({})
const tab = ref('expire')
const current = ref('domain_expire')
const loading = ref(false)
const previews = reactive({})
const inputs = reactive({})
const targets = reactive({})
const assets = ref([])
const tasks = ref([])
const timers = {}

const all = computed(() => Object.values(groups.value).flat())
const active = computed(() => all.value.find((x) => x.scene === current.value))

watch(tab, (g) => {
  const first = groups.value[g]?.[0]
  if (first && !groups.value[g].some((x) => x.scene === current.value)) current.value = first.scene
})
watch(current, () => { if (active.value && previews[active.value.scene] === undefined) preview(active.value) })

function sceneOptions(list) {
  return list.map((x) => ({ key: x.scene, label: t('template.scenes.' + x.scene) + (x.custom ? ' ●' : '') }))
}

function presetOptions(tpl) {
  return Object.keys(tpl.presets).map((k) => ({ key: k, label: t('template.presets.' + k) }))
}

/** 预览可选的真实数据 */
function targetOptions(scene) {
  const sample = [{ value: 'sample', label: t('template.sample') }]
  const byType = { domain_expire: 'domain', ssl_expire: 'ssl' }
  if (scene === 'task_due') return sample.concat(tasks.value.map((x) => ({ value: 't' + x.id, label: x.title })))
  if (scene === 'digest') return sample
  let list = assets.value
  if (byType[scene]) list = list.filter((a) => a.type === byType[scene])
  else if (scene === 'asset_expire') list = list.filter((a) => !['domain', 'ssl'].includes(a.type))
  else if (scene.startsWith('ssl')) list = list.filter((a) => a.type === 'ssl')
  else if (scene.startsWith('domain') || scene === 'ns_changed') list = list.filter((a) => a.type === 'domain')
  return sample.concat(list.slice(0, 50).map((a) => ({ value: 'a' + a.id, label: a.name })))
}

/** 只保留 Telegram 支持的少量标签，其他全部转义 */
function safe(html = '') {
  const escaped = String(html).replace(/&(?!(amp|lt|gt|quot);)/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  return escaped
    .replace(/&lt;(\/?)(b|strong|i|em|u|s|code|pre)&gt;/g, '<$1$2>')
    .replace(/&lt;a href="(https?:\/\/[^"<>]+)"&gt;/g, '<a href="$1" target="_blank" rel="noopener">')
    .replace(/&lt;\/a&gt;/g, '</a>')
}

async function load() {
  loading.value = true
  try {
    groups.value = await api.get('/api/templates')
    preview(active.value)
    api.get('/api/assets', { page_size: 100 }, { silent: true }).then((r) => { assets.value = r.items }).catch(() => {})
    api.get('/api/tasks', { page_size: 100 }, { silent: true }).then((r) => { tasks.value = r.items }).catch(() => {})
  } finally { loading.value = false }
}

async function preview(tpl) {
  if (!tpl) return
  if (!tpl.content) { previews[tpl.scene] = ''; return }
  const target = targets[tpl.scene] || 'sample'
  const params = { scene: tpl.scene, content: tpl.content }
  if (target.startsWith('a')) params.asset_id = target.slice(1)
  if (target.startsWith('t')) params.task_id = target.slice(1)
  const res = await api.post('/api/templates/preview', params, { silent: true }).catch(() => null)
  if (res) previews[tpl.scene] = res.html
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

function usePreset(tpl, key) {
  tpl.content = tpl.presets[key]
  preview(tpl)
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
.scenes { display: grid; grid-template-columns: 200px 1fr; gap: 16px; align-items: start; }
.scene-menu { border-right: 1px solid rgba(127, 127, 127, .15); }
.editor { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.vars { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; align-items: center; }
.preview-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; gap: 8px; }
@media (max-width: 900px) {
  .scenes { grid-template-columns: 1fr; }
  .scene-menu { border-right: none; }
  .editor { grid-template-columns: 1fr; }
}
</style>

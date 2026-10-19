<template>
  <n-drawer :show="show" :width="width" @update:show="(v) => emit('update:show', v)">
    <n-drawer-content v-if="asset" closable>
      <template #header>
        <n-space align="center" :size="8">
          <span>{{ asset.name }}</span>
          <n-tag size="small" :bordered="false">{{ t('asset.types.' + asset.type) }}</n-tag>
        </n-space>
      </template>

      <div class="headline">
        <div>
          <div class="muted">{{ t('asset.expire') }}</div>
          <div class="big">{{ fmt(asset.expire_time, 'YYYY-MM-DD') }}</div>
        </div>
        <n-space :size="6">
          <DaysTag :days="asset.days_left" :muted="asset.acked" />
          <n-tag v-if="asset.phase && asset.phase !== 'active'" size="small" type="error" round :bordered="false">{{ t('asset.phases.' + asset.phase) }}</n-tag>
          <n-tag v-if="asset.issue" size="small" type="error" round :bordered="false">{{ t('asset.issues.' + asset.issue) }}</n-tag>
        </n-space>
      </div>

      <n-alert v-if="asset.detect_status === 'failed'" type="error" :bordered="false" :title="t('domain.checkFailed')" style="margin-bottom: 12px">
        {{ asset.detect_error }}
      </n-alert>

      <n-descriptions :column="1" label-placement="left" size="small" bordered>
        <template v-if="asset.type === 'domain'">
          <n-descriptions-item :label="t('domain.registrar')">{{ asset.provider || '-' }}</n-descriptions-item>
          <n-descriptions-item v-if="meta.registered" :label="t('domain.registered')">{{ meta.registered }}</n-descriptions-item>
          <n-descriptions-item :label="t('domain.status')">
            <n-space :size="4">
              <n-tag v-for="s in abnormal" :key="s" size="small" type="error" :bordered="false">{{ t('asset.domainStatus.' + s) }}</n-tag>
              <span v-if="!abnormal.length">{{ t('domain.normal') }}</span>
            </n-space>
            <div v-if="(meta.status || []).length" class="muted mono" style="margin-top: 4px">{{ meta.status.join(', ') }}</div>
          </n-descriptions-item>
          <n-descriptions-item :label="t('domain.dns')"><span class="mono">{{ (meta.nameservers || []).join(', ') || '-' }}</span></n-descriptions-item>
        </template>
        <template v-else-if="asset.type === 'ssl'">
          <n-descriptions-item :label="t('vault.url')"><span class="mono">{{ meta.host || asset.identifier }}{{ meta.port && meta.port !== 443 ? ':' + meta.port : '' }}</span></n-descriptions-item>
          <n-descriptions-item :label="t('domain.issuer')">{{ asset.provider || '-' }}<span v-if="meta.issuer_cn" class="muted"> ({{ meta.issuer_cn }})</span></n-descriptions-item>
          <n-descriptions-item :label="t('domain.subject')"><span class="mono">{{ meta.subject || '-' }}</span></n-descriptions-item>
          <n-descriptions-item :label="t('domain.validFrom')">{{ fmt(meta.valid_from) }}</n-descriptions-item>
          <n-descriptions-item :label="t('domain.san')">
            <n-space :size="4"><n-tag v-for="n in (meta.san || []).slice(0, 30)" :key="n" size="small" :bordered="false" class="mono">{{ n }}</n-tag></n-space>
          </n-descriptions-item>
          <n-descriptions-item :label="t('domain.trusted')">
            <n-tag size="small" :type="meta.trusted ? 'success' : 'error'" :bordered="false">{{ meta.trusted ? t('common.yes') : t('common.no') }}</n-tag>
            <n-tag v-if="meta.short_lived" size="small" :bordered="false" style="margin-left: 6px">{{ t('domain.shortLived') }}</n-tag>
            <div v-if="meta.verify_error" class="muted mono" style="margin-top: 4px">{{ meta.verify_error }}</div>
          </n-descriptions-item>
        </template>
        <n-descriptions-item :label="t('asset.lastDetect')">
          {{ fmt(asset.last_detect_time) }}<span v-if="meta.source" class="muted"> · {{ meta.source.toUpperCase() }}</span>
        </n-descriptions-item>
        <n-descriptions-item v-if="asset.auto_detect" :label="t('asset.nextDetect')">{{ fmt(asset.next_detect_time) }}</n-descriptions-item>
        <n-descriptions-item v-if="asset.fail_count" :label="t('asset.failCount')">{{ asset.fail_count }}</n-descriptions-item>
        <n-descriptions-item :label="t('asset.offsets')">{{ asset.remind_offsets }}</n-descriptions-item>
        <n-descriptions-item v-if="asset.notes" :label="t('common.notes')">{{ asset.notes }}</n-descriptions-item>
      </n-descriptions>

      <h4 class="sub">{{ t('asset.events') }}</h4>
      <n-empty v-if="!events.length" size="small" :description="t('asset.noEvents')" />
      <n-timeline v-else>
        <n-timeline-item v-for="e in events" :key="e.id" :type="eventType(e.type)" :title="t('template.scenes.' + e.type)" :time="fmt(e.create_time)">
          <span class="muted mono">{{ eventText(e) }}</span>
        </n-timeline-item>
      </n-timeline>

      <template #footer>
        <n-space>
          <n-button v-if="asset.auto_detect" :loading="detecting" @click="detect">{{ t('asset.detect') }}</n-button>
          <n-button @click="emit('edit', asset)">{{ t('common.edit') }}</n-button>
        </n-space>
      </template>
    </n-drawer-content>
  </n-drawer>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, message } from '../utils/api'
import { fmt, fmtDate } from '../utils/time'
import DaysTag from './DaysTag.vue'

const props = defineProps({ show: Boolean, asset: Object })
const emit = defineEmits(['update:show', 'edit', 'changed'])
const { t } = useI18n()
const width = Math.min(520, window.innerWidth)
const events = ref([])
const detecting = ref(false)

const meta = computed(() => props.asset?.detect_meta || {})
const ABNORMAL = ['clienthold', 'serverhold', 'redemptionperiod', 'pendingdelete', 'pendingrestore', 'inactive', 'serverinactive']
const abnormal = computed(() => [...new Set((meta.value.status || []).map((s) => String(s).toLowerCase().replace(/[\s_-]+/g, '')).filter((s) => ABNORMAL.includes(s)))])

watch(() => [props.show, props.asset?.id], async ([v]) => {
  events.value = []
  if (v && props.asset) events.value = await api.get(`/api/assets/${props.asset.id}/events`, null, { silent: true }).catch(() => [])
})

async function detect() {
  detecting.value = true
  try {
    const res = await api.post(`/api/assets/${props.asset.id}/detect`)
    res.detect_status === 'ok' ? message.success(fmtDate(res.expire_time)) : message.error(res.detect_error)
    emit('changed', res)
  } finally {
    detecting.value = false
  }
}

function eventType(type) {
  return { domain_renewed: 'success', ssl_renewed: 'success', ns_changed: 'warning', ssl_renew_stuck: 'warning' }[type] || 'error'
}

function eventText(e) {
  const d = e.data || {}
  if (d.old_expire || d.new_expire) return `${fmtDate(d.old_expire)} → ${fmtDate(d.new_expire)}`
  if (d.old) return `${d.old.join(', ')} → ${d.new.join(', ')}`
  if (d.statuses) return d.statuses.map((s) => t('asset.domainStatus.' + s)).join(', ')
  if (d.issue) return t('asset.issues.' + d.issue) + (d.error ? ` · ${d.error}` : '')
  return d.error || ''
}
</script>

<style scoped>
.headline { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 14px; gap: 8px; flex-wrap: wrap; }
.big { font-size: 22px; font-weight: 600; }
.sub { margin: 18px 0 8px; font-size: 14px; }
</style>

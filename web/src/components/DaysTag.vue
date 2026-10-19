<template>
  <n-tag v-if="days !== null && days !== undefined" :type="type" size="small" :bordered="false" round>
    {{ text }}
  </n-tag>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({ days: { type: Number, default: null }, muted: Boolean })
const { t } = useI18n()

const type = computed(() => {
  if (props.muted) return 'default'
  if (props.days < 0) return 'error'
  if (props.days <= 7) return 'warning'
  if (props.days <= 30) return 'info'
  return 'success'
})
const text = computed(() => {
  if (props.days < 0) return t('asset.status.overdue', { n: -props.days })
  if (props.days === 0) return t('asset.status.today')
  return t('asset.status.left', { n: props.days })
})
</script>

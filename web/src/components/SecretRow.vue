<template>
  <div class="row">
    <div class="label">{{ label }}</div>
    <div class="line">
      <span class="value mono">{{ masked && !visible ? '••••••••••••' : value }}</span>
      <n-button v-if="masked" size="tiny" quaternary @click="visible = !visible" :title="visible ? t('vault.hide') : t('vault.show')">
        <template #icon><n-icon><EyeOffOutline v-if="visible" /><EyeOutline v-else /></n-icon></template>
      </n-button>
      <n-button size="tiny" quaternary @click="emit('copy')" :title="t('vault.copy')">
        <template #icon><n-icon><CopyOutline /></n-icon></template>
      </n-button>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { CopyOutline, EyeOutline, EyeOffOutline } from '@vicons/ionicons5'

defineProps({ label: String, value: String, masked: Boolean })
const emit = defineEmits(['copy'])
const { t } = useI18n()
const visible = ref(false)
</script>

<style scoped>
.row { padding: 8px 0; border-bottom: 1px dashed rgba(127, 127, 127, .2); }
.label { font-size: 12px; opacity: .6; margin-bottom: 4px; }
.line { display: flex; align-items: center; gap: 4px; }
.value { flex: 1; min-width: 0; word-break: break-all; }
</style>

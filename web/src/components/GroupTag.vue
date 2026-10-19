<template>
  <span v-if="group" class="group-tag">
    <i :style="{ background: group.color || '#18a058' }" />{{ group.name }}
  </span>
</template>

<script setup>
defineProps({ group: Object })
</script>

<style scoped>
.group-tag { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; white-space: nowrap; }
.group-tag i { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
</style>

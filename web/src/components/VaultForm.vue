<template>
  <n-drawer :show="show" :width="width" @update:show="(v) => emit('update:show', v)">
    <n-drawer-content :title="item ? t('common.edit') : t('vault.add')" closable>
      <n-spin :show="loading">
        <n-form ref="formRef" :model="form" :rules="rules" label-placement="top" size="small">
          <n-form-item :label="t('vault.name')" path="title">
            <n-input v-model:value="form.title" />
          </n-form-item>
          <n-grid :cols="2" :x-gap="12">
            <n-gi>
              <n-form-item :label="t('vault.type')">
                <n-select v-model:value="form.type" :options="typeOptions" />
              </n-form-item>
            </n-gi>
            <n-gi>
              <n-form-item :label="t('asset.group')">
                <n-select v-model:value="form.group_id" :options="groupOptions" />
              </n-form-item>
            </n-gi>
          </n-grid>
          <n-form-item :label="t('vault.url')">
            <n-input v-model:value="form.url" placeholder="https://" />
          </n-form-item>

          <template v-if="secretLoaded">
            <n-form-item :label="t('vault.username')">
              <n-input v-model:value="form.secret.username" :input-props="{ autocomplete: 'off' }" />
            </n-form-item>
            <n-form-item :label="t('vault.password')">
              <n-input-group>
                <n-input v-model:value="form.secret.password" type="password" show-password-on="click" class="mono" :input-props="{ autocomplete: 'new-password' }" />
                <n-button @click="form.secret.password = generatePassword()">{{ t('vault.generate') }}</n-button>
              </n-input-group>
            </n-form-item>
            <n-form-item :label="t('vault.totp')">
              <n-input v-model:value="form.secret.totp" class="mono" :placeholder="t('vault.totpHint')" :input-props="{ autocomplete: 'off' }" />
            </n-form-item>

            <n-form-item :label="t('vault.fields')">
              <n-space vertical style="width: 100%">
                <n-input-group v-for="(f, i) in form.secret.fields" :key="i">
                  <n-input v-model:value="f.label" :placeholder="t('vault.fieldLabel')" style="width: 32%" />
                  <n-input v-model:value="f.value" :type="f.secret ? 'password' : 'text'" show-password-on="click" :placeholder="t('vault.fieldValue')" />
                  <n-button :type="f.secret ? 'warning' : 'default'" @click="f.secret = !f.secret" :title="t('vault.fieldSecret')">
                    <template #icon><n-icon><LockClosedOutline /></n-icon></template>
                  </n-button>
                  <n-button @click="form.secret.fields.splice(i, 1)"><template #icon><n-icon><CloseOutline /></n-icon></template></n-button>
                </n-input-group>
                <n-button dashed size="small" @click="form.secret.fields.push({ label: '', value: '', secret: true })">+ {{ t('vault.addField') }}</n-button>
              </n-space>
            </n-form-item>

            <n-form-item :label="t('vault.notes')">
              <n-input v-model:value="form.secret.notes" type="textarea" :autosize="{ minRows: 3, maxRows: 10 }" />
            </n-form-item>
          </template>
          <n-alert v-else type="warning" :bordered="false" style="margin-bottom: 12px">{{ t('vault.locked') }}</n-alert>

          <n-grid :cols="2" :x-gap="12">
            <n-gi>
              <n-form-item :label="t('vault.visibility')">
                <n-select v-model:value="form.visibility" :options="visibilityOptions" />
              </n-form-item>
            </n-gi>
            <n-gi>
              <n-form-item :label="t('vault.asset')">
                <n-select v-model:value="form.asset_id" :options="assetOptions" clearable filterable :placeholder="t('common.optional')" />
              </n-form-item>
            </n-gi>
          </n-grid>
        </n-form>
      </n-spin>
      <template #footer>
        <n-space>
          <n-button @click="emit('update:show', false)">{{ t('common.cancel') }}</n-button>
          <n-button type="primary" :loading="saving" @click="submit">{{ t('common.save') }}</n-button>
        </n-space>
      </template>
    </n-drawer-content>
  </n-drawer>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { CloseOutline, LockClosedOutline } from '@vicons/ionicons5'
import { api, message } from '../utils/api'
import { lockedCall, generatePassword } from '../utils/vault'

const props = defineProps({ show: Boolean, item: Object, groups: Array })
const emit = defineEmits(['update:show', 'saved'])
const { t } = useI18n()
const width = Math.min(540, window.innerWidth)
const formRef = ref()
const loading = ref(false)
const saving = ref(false)
const secretLoaded = ref(true)
const assets = ref([])

const emptySecret = () => ({ username: '', password: '', totp: '', notes: '', fields: [] })
const empty = () => ({ title: '', type: 'login', group_id: null, url: '', visibility: 'team', asset_id: null, secret: emptySecret() })
const form = reactive(empty())
const rules = { title: { required: true, trigger: 'blur' } }

const typeOptions = computed(() => ['login', 'api_key', 'server', 'note', 'other'].map((v) => ({ value: v, label: t('vault.types.' + v) })))
const groupOptions = computed(() => (props.groups || []).map((g) => ({ value: g.id, label: g.name })))
const visibilityOptions = computed(() => [{ value: 'team', label: t('vault.team') }, { value: 'private', label: t('vault.private') }])
const assetOptions = computed(() => assets.value.map((a) => ({ value: a.id, label: a.name })))

watch(() => props.show, async (v) => {
  if (!v) return
  Object.assign(form, empty())
  form.group_id = (props.groups || []).find((g) => g.is_default)?.id ?? null
  secretLoaded.value = true
  if (!assets.value.length) api.get('/api/assets', { page_size: 100 }, { silent: true }).then((r) => { assets.value = r.items }).catch(() => {})

  const x = props.item
  if (!x) return
  Object.assign(form, { title: x.title, type: x.type, group_id: x.group_id, url: x.url, visibility: x.visibility, asset_id: x.asset_id || null })
  // 编辑时需要解锁才能加载敏感字段；取消解锁则只修改基本信息
  secretLoaded.value = false
  loading.value = true
  try {
    const res = await lockedCall(() => api.post(`/api/vault/${x.id}/reveal`))
    form.secret = { ...emptySecret(), ...res.secret, fields: (res.secret.fields || []).map((f) => ({ ...f })) }
    secretLoaded.value = true
  } catch { /* 未解锁 */ } finally {
    loading.value = false
  }
})

async function submit() {
  await formRef.value.validate()
  saving.value = true
  try {
    const payload = { title: form.title.trim(), type: form.type, group_id: form.group_id || 0, url: form.url, visibility: form.visibility, asset_id: form.asset_id || 0 }
    if (secretLoaded.value) payload.secret = form.secret
    const save = () => (props.item ? api.put(`/api/vault/${props.item.id}`, payload) : api.post('/api/vault', payload))
    // 修改敏感字段需要处于解锁状态
    const res = props.item && payload.secret ? await lockedCall(save) : await save()
    message.success(t('common.save'))
    emit('saved', res)
  } finally {
    saving.value = false
  }
}
</script>

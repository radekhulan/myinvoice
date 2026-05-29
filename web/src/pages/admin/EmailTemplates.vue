<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi, type EmailTemplateListItem, type EmailTemplate } from '@/api/admin'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'

const { t } = useI18n()
const toast = useToast()

const list = ref<EmailTemplateListItem[]>([])
const loading = ref(false)
const editing = ref<EmailTemplate | null>(null)
const saving = ref(false)

useHotkey('escape', () => { if (editing.value) editing.value = null })

async function load() {
  loading.value = true
  try { list.value = await adminApi.listEmailTemplates() }
  finally { loading.value = false }
}
onMounted(load)

async function open(item: EmailTemplateListItem) {
  editing.value = await adminApi.getEmailTemplate(item.code, item.locale)
}

async function save() {
  if (!editing.value) return
  saving.value = true
  try {
    await adminApi.saveEmailTemplate(editing.value.code, editing.value.locale, {
      subject:   editing.value.subject,
      body_html: editing.value.body_html,
      body_text: editing.value.body_text,
    })
    toast.success(t('users.et_saved'))
    editing.value = null
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

async function resetDefault() {
  if (!editing.value) return
  if (!confirm(t('users.et_reset_confirm'))) return
  try {
    await adminApi.resetEmailTemplate(editing.value.code, editing.value.locale)
    toast.success(t('users.et_reset_done'))
    editing.value = null
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function codeLabel(code: string): string {
  return t(`users.et_known_codes.${code}`) as string
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold mb-4">{{ t('users.email_templates_title') }}</h1>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2 font-medium">{{ t('client.title') /* "Šablona" — reuse */ }}</th>
            <th class="text-left px-4 py-2 font-medium">{{ t('common.language') }}</th>
            <th class="text-left px-4 py-2 font-medium">{{ t('users.et_has_override') }}</th>
            <th class="text-right px-4 py-2 font-medium w-24"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="it in list" :key="`${it.code}.${it.locale}`" class="hover:bg-neutral-50">
            <td class="px-4 py-2.5 font-medium">{{ codeLabel(it.code) }}</td>
            <td class="px-4 py-2.5 font-mono text-xs uppercase">{{ it.locale }}</td>
            <td class="px-4 py-2.5 text-xs">
              <span v-if="it.has_override" class="px-2 py-0.5 rounded bg-warning-50 text-warning-600">{{ t('users.et_has_override') }}</span>
              <span v-else class="text-neutral-400">{{ t('users.et_default') }}</span>
            </td>
            <td class="px-4 py-2.5 text-right">
              <button @click="open(it)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs">{{ t('common.edit') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
      </div>

      <!-- Mobile: karty -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="it in list" :key="`m-${it.code}.${it.locale}`"
          @click="open(it)"
          class="cursor-pointer hover:bg-neutral-50 px-4 py-3">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 truncate">{{ codeLabel(it.code) }}</div>
            <span class="font-mono text-xs uppercase text-neutral-500 whitespace-nowrap">{{ it.locale }}</span>
          </div>
          <div class="mt-1.5">
            <span v-if="it.has_override" class="text-xs px-2 py-0.5 rounded bg-warning-50 text-warning-600">{{ t('users.et_has_override') }}</span>
            <span v-else class="text-xs text-neutral-400">{{ t('users.et_default') }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Editor modal -->
    <div v-if="editing" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-4xl w-full max-h-[90vh] overflow-y-auto p-5">
        <h3 class="text-lg font-semibold mb-3">{{ codeLabel(editing.code) }} <span class="text-xs font-mono uppercase text-neutral-500 ml-1">{{ editing.locale }}</span></h3>

        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('users.et_subject') }}</label>
            <input v-model="editing.subject" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('users.et_body_html') }}</label>
            <textarea v-model="editing.body_html" rows="14" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-xs font-mono leading-relaxed"></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('users.et_body_text') }}</label>
            <textarea v-model="editing.body_text" rows="8" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-xs font-mono leading-relaxed"></textarea>
          </div>
        </div>

        <div class="flex justify-between gap-2 pt-4 mt-3 border-t border-neutral-200">
          <button v-if="editing.has_override" @click="resetDefault"
            class="cursor-pointer h-9 px-3 text-sm border border-warning-500/50 text-warning-600 hover:bg-warning-50 rounded-md">
            {{ t('users.et_reset') }}
          </button>
          <span v-else></span>
          <div class="flex gap-2">
            <button @click="editing = null" class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="save" :disabled="saving" class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
              {{ saving ? '…' : t('users.et_save') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

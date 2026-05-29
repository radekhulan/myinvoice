<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { tokensApi, type ApiToken, type CreateTokenResult } from '@/api/tokens'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const auth = useAuthStore()
const suppliers = useSupplierStore()
const toast = useToast()

const list = ref<ApiToken[]>([])
const loading = ref(false)
const error = ref('')

// Create modal state
const showCreate = ref(false)
const createBusy = ref(false)
const createError = ref('')
const form = ref({
  name: '',
  supplier_id: null as number | null,
  scope: 'read_write' as 'read' | 'read_write',
  expires_at: '' as string,
  totp_code: '',
})

// Created token reveal modal
const revealed = ref<CreateTokenResult | null>(null)
const copied = ref(false)
const confirmCopied = ref(false)

const needsTotp = computed(() => auth.user?.totp_enabled === true)

async function load() {
  loading.value = true
  error.value = ''
  try {
    list.value = await tokensApi.list()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  form.value = {
    name: '',
    supplier_id: suppliers.currentSupplierId || null,
    scope: 'read_write',
    expires_at: '',
    totp_code: '',
  }
  createError.value = ''
  showCreate.value = true
}

async function submitCreate() {
  if (form.value.name.trim() === '') {
    createError.value = t('api_tokens.name_required')
    return
  }
  if (needsTotp.value && !/^\d{6}$/.test(form.value.totp_code)) {
    createError.value = t('auth.totp_invalid')
    return
  }
  createBusy.value = true
  createError.value = ''
  try {
    const res = await tokensApi.create({
      name: form.value.name.trim(),
      supplier_id: form.value.supplier_id,
      scope: form.value.scope,
      expires_at: form.value.expires_at || null,
      totp_code: needsTotp.value ? form.value.totp_code : undefined,
    })
    showCreate.value = false
    revealed.value = res
    confirmCopied.value = false
    copied.value = false
    await load()
  } catch (e: any) {
    createError.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    createBusy.value = false
  }
}

async function copyToken() {
  if (!revealed.value) return
  try {
    await navigator.clipboard.writeText(revealed.value.token)
    copied.value = true
    toast.success(t('api_tokens.copied'))
  } catch {
    /* ignore — user can copy manually */
  }
}

function closeReveal() {
  if (!confirmCopied.value) return
  revealed.value = null
  confirmCopied.value = false
  copied.value = false
}

async function revoke(id: number, name: string) {
  if (!confirm(t('api_tokens.confirm_revoke', { name }))) return
  try {
    await tokensApi.revoke(id)
    toast.success(t('api_tokens.revoked'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function badgeClass(tk: ApiToken): string {
  if (tk.is_revoked) return 'bg-neutral-100 text-neutral-500'
  if (tk.is_expired) return 'bg-warning-50 text-warning-600'
  return 'bg-success-50 text-success-600'
}

function statusLabel(tk: ApiToken): string {
  if (tk.is_revoked) return t('api_tokens.status_revoked')
  if (tk.is_expired) return t('api_tokens.status_expired')
  return t('api_tokens.status_active')
}

function fmtTime(iso: string | null): string {
  if (!iso) return '—'
  return iso.replace('T', ' ').slice(0, 16)
}

onMounted(load)
</script>

<template>
  <div class="max-w-5xl">
    <div class="flex items-start justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('api_tokens.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('api_tokens.subtitle') }}</p>
      </div>
      <button
        @click="openCreate"
        class="cursor-pointer h-10 px-4 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md"
      >
        + {{ t('api_tokens.new') }}
      </button>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 text-sm text-neutral-700">
      <p>
        {{ t('api_tokens.intro') }}
        <a href="/api/docs" target="_blank" class="text-primary-600 hover:underline">{{ t('api_tokens.docs_link') }}</a>
      </p>
    </div>

    <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 mb-3 text-sm text-danger-500">
      {{ error }}
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-neutral-600 text-xs uppercase">
          <tr>
            <th class="text-left px-3 py-2">{{ t('api_tokens.col_name') }}</th>
            <th class="text-left px-3 py-2">{{ t('api_tokens.col_prefix') }}</th>
            <th class="text-left px-3 py-2">{{ t('api_tokens.col_supplier') }}</th>
            <th class="text-left px-3 py-2">{{ t('api_tokens.col_scope') }}</th>
            <th class="text-left px-3 py-2">{{ t('api_tokens.col_last_used') }}</th>
            <th class="text-left px-3 py-2">{{ t('api_tokens.col_expires') }}</th>
            <th class="text-left px-3 py-2">{{ t('api_tokens.col_status') }}</th>
            <th class="text-right px-3 py-2">{{ t('common.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && list.length === 0">
            <td colspan="8" class="text-center text-neutral-500 py-8">{{ t('api_tokens.empty') }}</td>
          </tr>
          <tr v-for="tk in list" :key="tk.id" class="border-t border-neutral-200">
            <td class="px-3 py-2 font-medium">{{ tk.name }}</td>
            <td class="px-3 py-2 font-mono text-xs text-neutral-600">{{ tk.prefix }}…</td>
            <td class="px-3 py-2 text-neutral-600">
              {{ tk.supplier_id === null ? t('api_tokens.supplier_all') : (tk.supplier_name || tk.supplier_company) }}
            </td>
            <td class="px-3 py-2">
              <span class="px-2 py-0.5 rounded text-xs font-medium"
                :class="tk.scope === 'read' ? 'bg-neutral-100 text-neutral-600' : 'bg-primary-100 text-primary-700'">
                {{ tk.scope === 'read' ? t('api_tokens.scope_read') : t('api_tokens.scope_read_write') }}
              </span>
            </td>
            <td class="px-3 py-2 text-neutral-500">{{ fmtTime(tk.last_used_at) }}</td>
            <td class="px-3 py-2 text-neutral-500">{{ tk.expires_at ? fmtTime(tk.expires_at) : t('api_tokens.expires_never') }}</td>
            <td class="px-3 py-2">
              <span class="px-2 py-0.5 rounded text-xs font-medium" :class="badgeClass(tk)">{{ statusLabel(tk) }}</span>
            </td>
            <td class="px-3 py-2 text-right">
              <button
                v-if="!tk.is_revoked"
                @click="revoke(tk.id, tk.name)"
                class="cursor-pointer text-danger-500 hover:text-danger-600 text-sm"
              >
                {{ t('api_tokens.revoke') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create modal -->
    <div v-if="showCreate" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-surface rounded-lg shadow-xl max-w-md w-full p-6">
        <h2 class="text-xl font-semibold mb-4">{{ t('api_tokens.new') }}</h2>

        <div class="space-y-3">
          <label class="block text-sm">
            <span class="text-neutral-700 font-medium">{{ t('api_tokens.col_name') }}</span>
            <input v-model="form.name" type="text" maxlength="100"
              :placeholder="t('api_tokens.name_placeholder')"
              class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </label>

          <label class="block text-sm">
            <span class="text-neutral-700 font-medium">{{ t('api_tokens.col_supplier') }}</span>
            <select v-model="form.supplier_id"
              class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option :value="null">{{ t('api_tokens.supplier_all') }}</option>
              <option v-for="s in suppliers.availableSuppliers" :key="s.id" :value="s.id">{{ s.company_name }}</option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('api_tokens.supplier_hint') }}</p>
          </label>

          <fieldset class="block text-sm">
            <legend class="text-neutral-700 font-medium mb-1">{{ t('api_tokens.col_scope') }}</legend>
            <div class="space-y-1">
              <label class="flex items-start gap-2">
                <input type="radio" v-model="form.scope" value="read" class="mt-0.5" />
                <span>
                  <strong>{{ t('api_tokens.scope_read') }}</strong>
                  <span class="text-neutral-500"> — {{ t('api_tokens.scope_read_desc') }}</span>
                </span>
              </label>
              <label class="flex items-start gap-2">
                <input type="radio" v-model="form.scope" value="read_write" class="mt-0.5" />
                <span>
                  <strong>{{ t('api_tokens.scope_read_write') }}</strong>
                  <span class="text-neutral-500"> — {{ t('api_tokens.scope_read_write_desc') }}</span>
                </span>
              </label>
            </div>
          </fieldset>

          <label class="block text-sm">
            <span class="text-neutral-700 font-medium">{{ t('api_tokens.col_expires') }} <span class="text-neutral-400">{{ t('common.optional') }}</span></span>
            <input v-model="form.expires_at" type="date"
              class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </label>

          <label v-if="needsTotp" class="block text-sm">
            <span class="text-neutral-700 font-medium">{{ t('auth.totp_code') }}</span>
            <input v-model="form.totp_code" type="text" inputmode="numeric" maxlength="6" pattern="\d{6}"
              placeholder="000000"
              class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-lg tracking-widest text-center" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('api_tokens.totp_hint') }}</p>
          </label>
        </div>

        <div v-if="createError"
          class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ createError }}
        </div>

        <div class="mt-5 flex justify-end gap-2">
          <button @click="showCreate = false"
            class="cursor-pointer h-10 px-4 border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button @click="submitCreate" :disabled="createBusy"
            class="cursor-pointer h-10 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ createBusy ? '…' : t('api_tokens.create') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Reveal modal — plaintext shown ONCE -->
    <div v-if="revealed" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4">
      <div class="bg-surface rounded-lg shadow-xl max-w-lg w-full p-6">
        <h2 class="text-xl font-semibold mb-2 text-success-600">{{ t('api_tokens.created_title') }}</h2>
        <p class="text-sm text-warning-600 font-medium mb-3">⚠ {{ t('api_tokens.created_warning') }}</p>

        <div class="bg-neutral-900 text-neutral-100 rounded-md p-3 font-mono text-sm break-all select-all">
          {{ revealed.token }}
        </div>

        <button @click="copyToken"
          class="mt-3 cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm rounded-md">
          {{ copied ? '✓ ' + t('api_tokens.copied') : t('api_tokens.copy') }}
        </button>

        <label class="mt-4 flex items-start gap-2 text-sm">
          <input type="checkbox" v-model="confirmCopied" class="mt-0.5" />
          <span>{{ t('api_tokens.created_confirm') }}</span>
        </label>

        <div class="mt-4 flex justify-end">
          <button @click="closeReveal" :disabled="!confirmCopied"
            class="cursor-pointer h-10 px-4 bg-neutral-700 hover:bg-neutral-800 disabled:bg-neutral-300 text-neutral-50 font-medium rounded-md">
            {{ t('common.close') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

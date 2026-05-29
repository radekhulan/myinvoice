<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { adminApi, type AdminUser } from '@/api/admin'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const users = ref<AdminUser[]>([])
const loading = ref(false)
const error = ref('')

const activeAdminCount = computed(() => users.value.filter(u => u.role === 'admin' && u.is_active).length)
function isLastAdmin(u: AdminUser): boolean {
  return u.role === 'admin' && u.is_active && activeAdminCount.value <= 1
}

const showForm = ref(false)
useHotkey('escape', () => { if (showForm.value) showForm.value = false })

const form = reactive({
  id: null as number | null,
  email: '',
  name: '',
  role: 'readonly' as AdminUser['role'],
  locale: 'cs' as 'cs' | 'en',
  is_active: true,
  password: '',
})

async function load() {
  loading.value = true
  try { users.value = await adminApi.listUsers() }
  finally { loading.value = false }
}
onMounted(load)

function openCreate() {
  Object.assign(form, { id: null, email: '', name: '', role: 'readonly', locale: 'cs', is_active: true, password: '' })
  showForm.value = true
}
function openEdit(u: AdminUser) {
  Object.assign(form, { id: u.id, email: u.email, name: u.name, role: u.role, locale: u.locale, is_active: u.is_active, password: '' })
  showForm.value = true
}

async function save() {
  error.value = ''
  // Client-side guard: nesmí jít odebrat poslední admin
  if (form.id !== null) {
    const original = users.value.find(u => u.id === form.id)
    if (original && original.role === 'admin' && original.is_active) {
      const losingAdmin = form.role !== 'admin' || !form.is_active
      if (losingAdmin && activeAdminCount.value <= 1) {
        error.value = t('users.last_admin_form')
        return
      }
    }
  }
  try {
    if (form.id === null) {
      if (!form.password) { error.value = t('users.password_required'); return }
      await adminApi.createUser({
        email: form.email, name: form.name, role: form.role, locale: form.locale, password: form.password,
      })
    } else {
      const payload: Record<string, unknown> = {
        name: form.name, role: form.role, locale: form.locale, is_active: form.is_active,
      }
      if (form.password) payload.password = form.password
      await adminApi.updateUser(form.id, payload)
    }
    showForm.value = false
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  }
}

async function deactivate(u: AdminUser) {
  if (isLastAdmin(u)) {
    toast.warning(t('users.last_admin_alert'))
    return
  }
  if (!confirm(t('users.deactivate_confirm', { email: u.email }))) return
  try {
    await adminApi.deleteUser(u.id)
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function roleBadge(role: string): string {
  if (role === 'admin') return 'bg-primary-100 text-primary-700'
  if (role === 'accountant') return 'bg-warning-50 text-warning-600'
  return 'bg-neutral-100 text-neutral-600'
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('users.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('users.subtitle') }}</p>
      </div>
      <button @click="openCreate"
        class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
        {{ t('users.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">{{ t('settings.email') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('users.name') }}</th>
            <th class="px-3 py-2 text-center font-medium">Role</th>
            <th class="px-3 py-2 text-center font-medium">{{ t('users.locale') }}</th>
            <th class="px-3 py-2 text-center font-medium">{{ t('users.active') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('users.last_login') }}</th>
            <th class="px-3 py-2 w-32"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="u in users" :key="u.id" :class="{ 'opacity-50': !u.is_active }">
            <td class="px-3 py-2 font-mono text-xs">{{ u.email }}</td>
            <td class="px-3 py-2">{{ u.name }}</td>
            <td class="px-3 py-2 text-center">
              <span class="text-xs px-2 py-0.5 rounded font-medium" :class="roleBadge(u.role)">{{ u.role }}</span>
              <span v-if="isLastAdmin(u)" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-warning-50 text-warning-600" :title="t('users.is_last_admin_lock')">🔒</span>
            </td>
            <td class="px-3 py-2 text-center text-xs">{{ u.locale }}</td>
            <td class="px-3 py-2 text-center">
              <span v-if="u.is_active" class="text-success-600">✓</span>
              <span v-else class="text-neutral-400">—</span>
            </td>
            <td class="px-3 py-2 text-xs text-neutral-500">{{ u.last_login_at?.replace('T', ' ').slice(0, 19) || '—' }}</td>
            <td class="px-3 py-2 text-right">
              <RouterLink v-if="auth.user?.id === u.id" to="/profile/totp"
                class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs mr-3" :title="t('auth.totp_2fa')">
                2FA
              </RouterLink>
              <button @click="openEdit(u)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs mr-3">{{ t('common.edit') }}</button>
              <button v-if="u.is_active" @click="deactivate(u)" :disabled="isLastAdmin(u)"
                class="cursor-pointer text-danger-500 hover:text-danger-600 text-xs disabled:opacity-30 disabled:cursor-not-allowed"
                :title="isLastAdmin(u) ? t('users.is_last_admin_lock') : t('users.deactivate')">
                {{ t('users.deactivate') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      </div>

      <!-- Mobile: karty -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="u in users" :key="`m-${u.id}`" class="p-3 space-y-2"
          :class="{ 'opacity-50': !u.is_active }">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 truncate">{{ u.name }}</div>
            <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="roleBadge(u.role)">{{ u.role }}</span>
          </div>
          <div class="flex items-baseline justify-between gap-2 text-xs text-neutral-500">
            <span class="font-mono truncate">{{ u.email }}</span>
            <span v-if="isLastAdmin(u)" class="px-1.5 py-0.5 rounded bg-warning-50 text-warning-600 whitespace-nowrap" :title="t('users.is_last_admin_lock')">🔒</span>
          </div>
          <div class="flex items-baseline justify-between gap-2 text-xs text-neutral-500">
            <span>
              <span v-if="u.is_active" class="text-success-600">✓ {{ t('users.active') }}</span>
              <span v-else>— {{ t('users.active') }}</span>
              <span class="text-neutral-400 mx-1.5">·</span>
              <span class="font-mono">{{ u.locale }}</span>
            </span>
            <span class="font-mono">{{ u.last_login_at?.replace('T', ' ').slice(0, 16) || '—' }}</span>
          </div>
          <div class="flex gap-2 pt-1">
            <RouterLink v-if="auth.user?.id === u.id" to="/profile/totp"
              class="cursor-pointer flex-1 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md inline-flex items-center justify-center"
              :title="t('auth.totp_2fa')">
              2FA
            </RouterLink>
            <button @click="openEdit(u)"
              class="cursor-pointer flex-1 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md">
              {{ t('common.edit') }}
            </button>
            <button v-if="u.is_active" @click="deactivate(u)" :disabled="isLastAdmin(u)"
              class="cursor-pointer flex-1 h-9 text-sm border border-danger-500/40 text-danger-500 hover:bg-danger-50 disabled:opacity-30 disabled:cursor-not-allowed rounded-md"
              :title="isLastAdmin(u) ? t('users.is_last_admin_lock') : t('users.deactivate')">
              {{ t('users.deactivate') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="showForm" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ form.id === null ? t('users.new_title') : t('users.edit_title', { email: form.email }) }}</h3>
        <div class="space-y-3">
          <div v-if="form.id === null">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('users.email_required') }}</label>
            <input v-model="form.email" type="email" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('users.name_required') }}</label>
            <input v-model="form.name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('users.role') }}</label>
              <select v-model="form.role" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                <option value="admin">admin</option>
                <option value="accountant">accountant</option>
                <option value="readonly">readonly</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.language') }}</label>
              <select v-model="form.locale" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                <option value="cs">cs</option>
                <option value="en">en</option>
              </select>
            </div>
          </div>
          <div v-if="form.id !== null">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('common.active') }}
            </label>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">
              {{ t('auth.password') }} {{ form.id === null ? '*' : t('users.password_change_hint') }}
            </label>
            <input v-model="form.password" type="password" autocomplete="new-password"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('users.password_min') }}</p>
          </div>
          <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="showForm = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="save" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">
              {{ form.id === null ? t('common.create') : t('common.save') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { adminApi, type ApprovalInboxItem } from '@/api/admin'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()

type StatusFilter = 'requested' | 'approved' | 'rejected' | 'all'
const statusFilter = ref<StatusFilter>('requested')
const overdueOnly = ref(false)
const items = ref<ApprovalInboxItem[]>([])
const loading = ref(true)
const loadingMore = ref(false)
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const counts = ref<{ all: number; requested: number; approved: number; rejected: number }>({
  all: 0, requested: 0, approved: 0, rejected: 0,
})

// Server-side filter + paginace. overdue_only se posílá jako overdue_days=5 do API.
async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const r = await adminApi.listApprovals({
      status: statusFilter.value,
      overdue_days: overdueOnly.value ? 5 : undefined,
      page: page.value,
    })
    if (reset) {
      items.value = r.data
    } else {
      items.value.push(...r.data)
    }
    total.value = r.meta.total
    pages.value = r.meta.pages
    if (r.meta.status_counts) counts.value = r.meta.status_counts
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('errors.generic'))
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

onMounted(() => load(true))
watch(statusFilter, () => load(true))
watch(overdueOnly, () => load(true))

// Server už filtruje, frontend žádný extra filter neaplikuje.
const filteredItems = computed(() => items.value)

function badgeClass(s: ApprovalInboxItem): string {
  if (s.approval_status === 'requested') {
    if (s.approval_token_expires_at && new Date(s.approval_token_expires_at) < new Date()) {
      return 'bg-warning-50 text-warning-600'
    }
    return 'bg-primary-100 text-primary-700'
  }
  if (s.approval_status === 'approved') return 'bg-success-50 text-success-600'
  if (s.approval_status === 'rejected') return 'bg-danger-50 text-danger-500'
  return 'bg-neutral-100 text-neutral-600'
}

function statusLabel(s: ApprovalInboxItem): string {
  if (s.approval_status === 'requested'
    && s.approval_token_expires_at
    && new Date(s.approval_token_expires_at) < new Date()) {
    return t('invoice.approval.status_expired')
  }
  return t('invoice.approval.status_' + s.approval_status)
}

function daysSince(date: string | null): number | null {
  if (!date) return null
  const ms = Date.now() - new Date(date).getTime()
  return Math.floor(ms / 86_400_000)
}

</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('approval_inbox.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('approval_inbox.subtitle') }}</p>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg p-3 mb-4 flex flex-wrap items-center gap-2">
      <button v-for="opt in (['requested','approved','rejected','all'] as const)" :key="opt"
        @click="statusFilter = opt"
        class="cursor-pointer px-3 h-8 text-xs rounded-md font-medium border inline-flex items-center gap-1.5"
        :class="statusFilter === opt
          ? 'bg-primary-600 text-white border-primary-600'
          : 'bg-surface text-neutral-700 border-neutral-300 hover:bg-neutral-50'">
        {{ t('approval_inbox.filter_' + opt) }}
        <span class="text-xs opacity-80">({{ counts[opt] }})</span>
      </button>
      <span class="ml-2 text-xs text-neutral-400">·</span>
      <label class="flex items-center gap-2 text-sm text-neutral-700 cursor-pointer ml-2">
        <input v-model="overdueOnly" type="checkbox"
          class="rounded border-neutral-300 text-primary-600" />
        {{ t('approval_inbox.overdue_only') }}
      </label>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="filteredItems.length === 0"
      class="bg-surface border border-neutral-200 rounded-lg p-12 text-center text-sm text-neutral-500">
      {{ t('approval_inbox.empty') }}
    </div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">{{ t('invoice.varsymbol') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('invoice.client') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('invoice.project') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('invoice.amount_to_pay') }}</th>
            <th class="px-3 py-2 text-center font-medium">{{ t('invoice.approval.badge') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('invoice.approval.requested_at') }}</th>
            <th class="px-3 py-2 text-center font-medium">{{ t('invoice.approval.reminders_sent') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('invoice.approval.comment') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="r in filteredItems" :key="r.id" class="hover:bg-neutral-50">
            <td class="px-3 py-2">
              <RouterLink :to="`/invoices/${r.id}`" class="font-mono text-primary-700 hover:underline">
                {{ r.varsymbol || '#' + r.id }}
              </RouterLink>
            </td>
            <td class="px-3 py-2">{{ r.client_company_name }}</td>
            <td class="px-3 py-2 text-neutral-600 text-xs">{{ r.project_name || '—' }}</td>
            <td class="px-3 py-2 text-right font-mono">{{ formatMoney(r.amount_to_pay, r.currency) }}</td>
            <td class="px-3 py-2 text-center">
              <span class="text-xs px-2 py-0.5 rounded font-medium" :class="badgeClass(r)">
                {{ statusLabel(r) }}
              </span>
            </td>
            <td class="px-3 py-2 text-xs text-neutral-600">
              <span v-if="r.approval_requested_at">
                {{ formatDate(r.approval_requested_at) }}
                <span v-if="daysSince(r.approval_requested_at) !== null"
                  class="text-neutral-400">
                  ({{ t('approval_inbox.days_ago', { n: daysSince(r.approval_requested_at) }) }})
                </span>
              </span>
              <span v-else class="text-neutral-400">—</span>
            </td>
            <td class="px-3 py-2 text-center font-mono text-xs">
              <span v-if="r.approval_reminder_count > 0" class="text-warning-600 font-semibold">
                {{ r.approval_reminder_count }}×
              </span>
              <span v-else class="text-neutral-400">0</span>
            </td>
            <td class="px-3 py-2 text-xs text-neutral-600 max-w-xs truncate"
              :title="r.approval_rejection_reason || ''">
              {{ r.approval_rejection_reason || '—' }}
            </td>
          </tr>
        </tbody>
      </table>
      </div>

      <!-- Mobile: karty -->
      <div class="md:hidden divide-y divide-neutral-100">
        <RouterLink v-for="r in filteredItems" :key="`m-${r.id}`" :to="`/invoices/${r.id}`"
          class="block hover:bg-neutral-50 px-3 py-3">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-mono font-medium text-primary-700">{{ r.varsymbol || '#' + r.id }}</div>
            <div class="font-mono text-sm font-semibold whitespace-nowrap">{{ formatMoney(r.amount_to_pay, r.currency) }}</div>
          </div>
          <div class="text-sm text-neutral-900 truncate mt-0.5">{{ r.client_company_name }}</div>
          <div v-if="r.project_name" class="text-xs text-neutral-500 truncate">{{ r.project_name }}</div>
          <div class="flex items-baseline justify-between gap-2 mt-2">
            <span class="text-xs px-2 py-0.5 rounded font-medium" :class="badgeClass(r)">
              {{ statusLabel(r) }}
            </span>
            <span v-if="r.approval_reminder_count > 0" class="text-xs text-warning-600 font-semibold">
              ⚠ {{ r.approval_reminder_count }}×
            </span>
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            <span v-if="r.approval_requested_at">
              {{ formatDate(r.approval_requested_at) }}
              <span v-if="daysSince(r.approval_requested_at) !== null" class="text-neutral-400">
                ({{ t('approval_inbox.days_ago', { n: daysSince(r.approval_requested_at) }) }})
              </span>
            </span>
            <span v-else>—</span>
          </div>
          <div v-if="r.approval_rejection_reason" class="text-xs text-neutral-600 mt-1 truncate">
            {{ r.approval_rejection_reason }}
          </div>
        </RouterLink>
      </div>

      <div v-if="items.length" class="px-4 py-3 border-t border-neutral-200 flex items-center justify-between text-sm">
        <span class="text-neutral-500">{{ t('common.loaded_count', { loaded: items.length, total: total }) }}</span>
        <button v-if="page < pages" @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-1.5">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>

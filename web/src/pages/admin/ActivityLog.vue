<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi, type ActivityLogEntry } from '@/api/admin'

const { t } = useI18n()

const entries = ref<ActivityLogEntry[]>([])
const total = ref(0)
const actions = ref<Array<{ action: string; cnt: number }>>([])
const loading = ref(false)

const filter = ref({
  action: '',
  entity_type: '',
  limit: 100,
  offset: 0,
})

async function load() {
  loading.value = true
  try {
    const params: Record<string, string | number> = { limit: filter.value.limit, offset: filter.value.offset }
    if (filter.value.action) params.action = filter.value.action
    if (filter.value.entity_type) params.entity_type = filter.value.entity_type
    const r = await adminApi.activityLog(params)
    entries.value = r.data
    total.value = r.total
    actions.value = r.actions
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => [filter.value.action, filter.value.entity_type], () => { filter.value.offset = 0; load() })

function actionBadgeClass(a: string): string {
  if (a.startsWith('invoice.')) return 'bg-primary-100 text-primary-700'
  if (a.startsWith('auth.') || a.startsWith('login')) return 'bg-success-50 text-success-600'
  if (a.includes('delete') || a.includes('failed') || a.includes('cancel') || a.includes('locked')) return 'bg-danger-50 text-danger-500'
  if (a.includes('warning') || a.includes('force')) return 'bg-warning-50 text-warning-600'
  return 'bg-neutral-100 text-neutral-600'
}

function fmtPayload(p: Record<string, unknown> | null): string {
  if (!p) return '—'
  return Object.entries(p).map(([k, v]) => `${k}=${typeof v === 'object' ? JSON.stringify(v) : String(v)}`).join(' · ')
}

function fmtTime(iso: string): string {
  return iso.replace('T', ' ').slice(0, 19)
}

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / filter.value.limit)))
const currentPage = computed(() => Math.floor(filter.value.offset / filter.value.limit) + 1)

function goPage(delta: number) {
  filter.value.offset = Math.max(0, filter.value.offset + delta * filter.value.limit)
  load()
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('activity_log.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('activity_log.subtitle') }}</p>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3 flex flex-wrap gap-2 items-center">
      <select v-model="filter.action" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option value="">{{ t('activity_log.all_actions') }}</option>
        <option v-for="a in actions" :key="a.action" :value="a.action">{{ a.action }} ({{ a.cnt }})</option>
      </select>
      <select v-model="filter.entity_type" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option value="">{{ t('activity_log.all_entities') }}</option>
        <option value="invoice">invoice</option>
        <option value="user">user</option>
        <option value="client">client</option>
        <option value="project">project</option>
        <option value="work_report">work_report</option>
      </select>
      <button @click="load" class="cursor-pointer h-9 px-3 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
        {{ t('activity_log.refresh') }}
      </button>
      <span class="ml-auto text-xs text-neutral-500">{{ t('activity_log.total', { n: total, p: currentPage, tp: totalPages }) }}</span>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="!entries.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-12 text-center text-neutral-500">
      {{ t('activity_log.no_records') }}
    </div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium w-44">{{ t('activity_log.time') }}</th>
            <th class="px-3 py-2 text-left font-medium w-44">{{ t('activity_log.user') }}</th>
            <th class="px-3 py-2 text-left font-medium w-48">{{ t('activity_log.action') }}</th>
            <th class="px-3 py-2 text-left font-medium w-36">{{ t('activity_log.entity') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('activity_log.payload') }}</th>
            <th class="px-3 py-2 text-left font-medium w-32">IP</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="e in entries" :key="e.id" class="hover:bg-neutral-50 align-top">
            <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ fmtTime(e.created_at) }}</td>
            <td class="px-3 py-2 text-xs break-words">
              <span v-if="e.user_email">{{ e.user_name || e.user_email }}</span>
              <span v-else class="text-neutral-400">—</span>
            </td>
            <td class="px-3 py-2">
              <span class="text-xs px-2 py-0.5 rounded font-medium" :class="actionBadgeClass(e.action)">{{ e.action }}</span>
            </td>
            <td class="px-3 py-2 text-xs whitespace-nowrap">
              <span v-if="e.entity_type">{{ e.entity_type }} #{{ e.entity_id }}</span>
              <span v-else class="text-neutral-400">—</span>
            </td>
            <td class="px-3 py-2 text-xs text-neutral-600 break-all whitespace-pre-wrap leading-snug">{{ fmtPayload(e.payload) }}</td>
            <td class="px-3 py-2 font-mono text-xs text-neutral-500 break-all">{{ e.ip || '—' }}</td>
          </tr>
        </tbody>
      </table>
      </div>

      <!-- Mobile: karty -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="e in entries" :key="`m-${e.id}`" class="p-3 space-y-1">
          <div class="flex items-baseline justify-between gap-2">
            <span class="text-xs px-2 py-0.5 rounded font-medium" :class="actionBadgeClass(e.action)">{{ e.action }}</span>
            <span v-if="e.entity_type" class="text-xs font-mono text-neutral-600 whitespace-nowrap">{{ e.entity_type }} #{{ e.entity_id }}</span>
          </div>
          <div class="flex items-baseline justify-between gap-2 text-xs text-neutral-500">
            <span class="truncate">
              <span v-if="e.user_email">{{ e.user_name || e.user_email }}</span>
              <span v-else>—</span>
            </span>
            <span class="font-mono whitespace-nowrap">{{ fmtTime(e.created_at) }}</span>
          </div>
          <div v-if="fmtPayload(e.payload)" class="text-xs text-neutral-600 break-all whitespace-pre-wrap leading-snug">{{ fmtPayload(e.payload) }}</div>
          <div v-if="e.ip" class="text-xs font-mono text-neutral-400 break-all">{{ e.ip }}</div>
        </div>
      </div>

      <div class="border-t border-neutral-200 p-3 flex items-center justify-between">
        <button @click="goPage(-1)" :disabled="filter.offset === 0"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded text-sm disabled:opacity-30 hover:bg-neutral-50">
          {{ t('common.previous') }}
        </button>
        <span class="text-xs text-neutral-500">{{ t('common.page') }} {{ currentPage }} / {{ totalPages }}</span>
        <button @click="goPage(1)" :disabled="currentPage >= totalPages"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded text-sm disabled:opacity-30 hover:bg-neutral-50">
          {{ t('common.next') }} →
        </button>
      </div>
    </div>
  </div>
</template>

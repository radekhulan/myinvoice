<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { projectsApi, type Project } from '@/api/projects'
import { clientsApi, type Client } from '@/api/clients'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { formatMoney, formatDate } from '@/composables/useFormat'

const { t } = useI18n()

const router = useRouter()
const route = useRoute()
const items = ref<Project[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const status = ref<'' | 'active' | 'paused' | 'closed'>('active')
const clientId = ref<number | ''>('')
const sort = ref<'name' | 'revenue' | 'last_activity' | 'client'>('name')
const clients = ref<Client[]>([])

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const r = await projectsApi.list({
      status: status.value || undefined,
      client_id: clientId.value === '' ? undefined : Number(clientId.value),
      sort: sort.value,
      page: page.value,
    })
    if (reset) {
      items.value = r.data
    } else {
      items.value.push(...r.data)
    }
    total.value = r.meta.total
    pages.value = r.meta.pages
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

async function loadClients() {
  const r = await clientsApi.list({ archived: false, per_page: 200, role: 'customers' })
  clients.value = r.data
}

onMounted(async () => {
  // Pre-fill from query
  if (route.query.client_id) clientId.value = Number(route.query.client_id)
  await Promise.all([loadClients(), load(true)])
})

function emailsFor(p: Project): string {
  const all = [p.client_main_email, ...(p.billing_emails ?? []).map(b => b.email)]
    .filter((e): e is string => !!e && e.trim() !== '')
  return Array.from(new Set(all)).join(', ')
}

watch([status, clientId, sort], () => load(true))
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ t('project.title') }}</h1>
    </div>

    <div class="mb-4 rounded-md border border-primary-500/30 bg-primary-50 px-4 py-2.5 text-sm text-primary-700 flex items-start gap-2">
      <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
      <i18n-t keypath="project.info_create_in_client" tag="div">
        <template #default><RouterLink to="/clients" class="underline font-medium hover:text-primary-800">{{ t('nav.clients') }}</RouterLink></template>
      </i18n-t>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-4 py-3 border-b border-neutral-200 flex flex-wrap items-center gap-2">
        <select v-model="status"
          class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
          <option value="">{{ t('invoice.all_statuses') }}</option>
          <option value="active">{{ t('common.active') }}</option>
          <option value="paused">{{ t('project.status_paused') }}</option>
          <option value="closed">{{ t('project.status_closed') }}</option>
        </select>
        <div class="min-w-48 flex-1 max-w-xs">
          <SearchableSelect
            :model-value="clientId === '' ? null : clientId"
            @update:model-value="(v) => clientId = v === null ? '' : v"
            :options="clients.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))"
            :placeholder="t('project.all_clients')"
          />
        </div>
        <select v-model="sort" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface ml-auto"
          :title="t('common.sort_by')">
          <option value="name">{{ t('common.sort_name') }}</option>
          <option value="client">{{ t('common.sort_client') }}</option>
          <option value="revenue">{{ t('common.sort_revenue') }}</option>
          <option value="last_activity">{{ t('common.sort_last_activity') }}</option>
        </select>
      </div>

      <TableSkeleton v-if="loading" :rows="6" :cols="5" />

      <EmptyState v-else-if="!items.length" :title="t('project.no_data')" />

      <!-- Desktop: tabulka -->
      <div v-else class="hidden md:block overflow-x-auto"><table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('project.name') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('nav.clients') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('project.status') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('common.revenue') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('common.last_activity') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('project.rate') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="p in items" :key="p.id" class="cursor-pointer hover:bg-neutral-50"
              @click="router.push(`/projects/${p.id}`)">
            <td class="px-4 py-3 font-medium">{{ p.name }}</td>
            <td class="px-4 py-3 text-neutral-600">
              <div>{{ p.client_company_name }}</div>
              <div v-if="emailsFor(p)" class="text-xs text-neutral-400 mt-0.5 truncate max-w-xs" :title="emailsFor(p)">
                {{ emailsFor(p) }}
              </div>
            </td>
            <td class="px-4 py-3">
              <span class="text-xs px-2 py-0.5 rounded"
                :class="{
                  'bg-success-50 text-success-600': p.status === 'active',
                  'bg-warning-50 text-warning-600': p.status === 'paused',
                  'bg-neutral-100 text-neutral-600': p.status === 'closed',
                }">
                {{ p.status === 'active' ? t('common.active') : p.status === 'paused' ? t('project.status_paused') : t('project.status_closed') }}
              </span>
            </td>
            <td class="px-4 py-3 text-right font-mono">
              <span v-if="p.revenue && p.revenue > 0">{{ formatMoney(p.revenue, p.currency) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-neutral-600 text-xs">
              <span v-if="p.last_invoice_date">{{ formatDate(p.last_invoice_date) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-right font-mono whitespace-nowrap">{{ p.hourly_rate.toLocaleString('cs') }} {{ p.currency }}/h</td>
          </tr>
        </tbody>
      </table></div>

      <!-- Mobile: karty -->
      <div v-if="items.length" class="md:hidden divide-y divide-neutral-100">
        <div
          v-for="p in items"
          :key="`m-${p.id}`"
          @click="router.push(`/projects/${p.id}`)"
          class="cursor-pointer hover:bg-neutral-50 transition px-4 py-3"
        >
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 truncate">{{ p.name }}</div>
            <div class="font-mono text-sm whitespace-nowrap">
              <span v-if="p.revenue && p.revenue > 0">{{ formatMoney(p.revenue, p.currency) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </div>
          </div>
          <div class="text-xs text-neutral-500 mt-0.5 truncate">{{ p.client_company_name }}</div>
          <div class="flex items-center justify-between gap-2 mt-2 text-xs">
            <span class="px-2 py-0.5 rounded"
              :class="{
                'bg-success-50 text-success-600': p.status === 'active',
                'bg-warning-50 text-warning-600': p.status === 'paused',
                'bg-neutral-100 text-neutral-600': p.status === 'closed',
              }">
              {{ p.status === 'active' ? t('common.active') : p.status === 'paused' ? t('project.status_paused') : t('project.status_closed') }}
            </span>
            <div class="flex items-center gap-2 text-neutral-600">
              <span v-if="p.last_invoice_date">{{ formatDate(p.last_invoice_date) }}</span>
              <span class="font-mono whitespace-nowrap">{{ p.hourly_rate.toLocaleString('cs') }} {{ p.currency }}/h</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="items.length" class="px-4 py-3 border-t border-neutral-200 flex items-center justify-between text-sm">
        <span class="text-neutral-500">{{ t('common.loaded_count', { loaded: items.length, total }) }}</span>
        <button v-if="page < pages" @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-1.5">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>

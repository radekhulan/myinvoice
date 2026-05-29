<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { clientsApi, type Client } from '@/api/clients'
import { expenseCategoriesApi, type ExpenseCategory } from '@/api/expenseCategories'
import { formatMoney, formatDate } from '@/composables/useFormat'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

type RoleFilter = 'all' | 'customers' | 'vendors'

const { t } = useI18n()
const auth = useAuthStore()

const router = useRouter()
const items = ref<Client[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const showArchived = ref(false)
const sort = ref<'name' | 'revenue' | 'last_activity'>('name')
// Filtr na výchozí kategorii nákladu dodavatele — jen ve vendor view.
const expenseCategories = ref<ExpenseCategory[]>([])
const categoryFilter = ref<number | null>(null)
const route = useRoute()
// Filter from ?role=vendors|all|customers (default customers).
// Watch query.role pro proklik mezi sidebar položkami Klienti ↔ Dodavatelé
// (Vue Router neremountuje komponentu při stejné route, jen mění query).
function readRoleFromQuery(): RoleFilter {
  const q = String(route.query.role ?? '')
  return q === 'vendors' || q === 'all' ? q : 'customers'
}
const roleFilter = ref<RoleFilter>(readRoleFromQuery())
watch(() => route.query.role, () => {
  roleFilter.value = readRoleFromQuery()
})
let searchTimeout: ReturnType<typeof setTimeout> | null = null

// Server-side role filter — backend respektuje cfg.php pagination.clients_per_page
// a vrací meta.role_counts pro tab badge. Frontend žádný client-side filter neaplikuje.
const filteredItems = computed(() => items.value)

const roleCounts = ref<{ all: number; customers: number; vendors: number }>({
  all: 0, customers: 0, vendors: 0,
})

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const r = await clientsApi.list({
      q: search.value,
      archived: showArchived.value,
      sort: sort.value,
      role: roleFilter.value,
      // Kategorie filtruje jen u dodavatelů (jinde nemá smysl).
      expense_category_id: roleFilter.value === 'vendors' ? categoryFilter.value : null,
      page: page.value,
    })
    if (reset) {
      items.value = r.data
    } else {
      items.value.push(...r.data)
    }
    total.value = r.meta.total
    pages.value = r.meta.pages
    if (r.meta.role_counts) {
      roleCounts.value = r.meta.role_counts
    }
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

onMounted(async () => {
  load(true)
  expenseCategories.value = await expenseCategoriesApi.list(false).catch(() => [])
})
watch(showArchived, () => load(true))
watch(sort, () => load(true))
watch(roleFilter, () => load(true))
watch(categoryFilter, () => load(true))
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => load(true), 300)
})

function openClient(c: Client) {
  router.push(`/clients/${c.id}`)
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ roleFilter === 'vendors' ? t('client.title_vendors') : t('client.title') }}</h1>
      <RouterLink
        v-if="auth.canWrite"
        :to="roleFilter === 'vendors' ? '/clients/new?role=vendor' : '/clients/new'"
        class="inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
      >
        {{ roleFilter === 'vendors' ? '+ ' + t('purchase_invoice.new_vendor') : t('client.new') }}
      </RouterLink>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <!-- Tabs: Klienti / Dodavatelé / Vše -->
      <div class="px-4 pt-2 border-b border-neutral-100 flex items-center gap-1">
        <button
          v-for="opt in [
            { key: 'customers' as RoleFilter, label: t('client.tab_customers') },
            { key: 'vendors' as RoleFilter,   label: t('client.tab_vendors') },
            { key: 'all' as RoleFilter,       label: t('client.tab_all') },
          ]"
          :key="opt.key"
          type="button"
          @click="roleFilter = opt.key"
          class="cursor-pointer relative px-3 py-2 text-sm border-b-2 transition"
          :class="roleFilter === opt.key
            ? 'border-primary-600 text-primary-700 font-medium'
            : 'border-transparent text-neutral-600 hover:text-neutral-900'"
        >
          {{ opt.label }}
          <span class="ml-1.5 inline-block px-1.5 py-0.5 text-xs bg-neutral-100 text-neutral-600 rounded-full">{{ roleCounts[opt.key] }}</span>
        </button>
      </div>

      <div class="px-4 py-3 border-b border-neutral-200 flex flex-col sm:flex-row sm:items-center gap-3">
        <input
          v-model="search"
          type="search"
          :placeholder="t('common.search')"
          class="flex-1 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        />
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="showArchived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('client.show_archived') }}
        </label>
        <select v-if="roleFilter === 'vendors'" v-model.number="categoryFilter"
          class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
          :title="t('client.default_expense_category')">
          <option :value="null">{{ t('client.filter_category_all') }}</option>
          <option v-for="c in expenseCategories" :key="c.id" :value="c.id">{{ c.label }} ({{ c.code }})</option>
        </select>
        <select v-model="sort" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
          :title="t('common.sort_by')">
          <option value="name">{{ t('common.sort_name') }}</option>
          <option value="revenue">{{ t('common.sort_revenue') }}</option>
          <option value="last_activity">{{ t('common.sort_last_activity') }}</option>
        </select>
      </div>

      <TableSkeleton v-if="loading" :rows="6" :cols="6" />

      <EmptyState v-else-if="!items.length"
        :title="t('client.no_data')"
        :cta="t('client.create_first')"
        :to="roleFilter === 'vendors' ? '/clients/new?role=vendor' : '/clients/new'" />

      <!-- Desktop: tabulka -->
      <div v-else class="hidden md:block overflow-x-auto"><table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('client.company') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('common.ic') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('client.email') }}</th>
            <th class="text-center px-4 py-2.5 font-medium">{{ roleFilter === 'vendors' ? t('client.invoice_count_label') : t('nav.projects') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ roleFilter === 'vendors' ? t('common.costs') : t('common.revenue') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('common.last_activity') }}</th>
            <th class="text-center px-4 py-2.5 font-medium">{{ t('common.currency') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr
            v-for="c in filteredItems"
            :key="c.id"
            @click="openClient(c)"
            class="cursor-pointer hover:bg-neutral-50"
          >
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <div class="font-medium text-neutral-900">{{ c.company_name }}</div>
                <span v-if="c.is_customer !== false && c.is_vendor === true"
                      class="inline-block px-1.5 py-0 text-[10px] bg-primary-100 text-primary-700 rounded font-medium uppercase tracking-wide"
                      :title="t('client.dual_role_tooltip')">K+D</span>
                <span v-else-if="c.is_vendor === true"
                      class="inline-block px-1.5 py-0 text-[10px] bg-warning-50 text-warning-600 rounded font-medium uppercase tracking-wide">{{ t('client.vendor_badge') }}</span>
              </div>
              <div v-if="c.archived_at" class="text-xs text-neutral-400 mt-0.5">{{ t('common.archived') }}</div>
            </td>
            <td class="px-4 py-3 font-mono text-xs text-neutral-600">{{ c.ic || '—' }}</td>
            <td class="px-4 py-3 text-neutral-600">{{ c.main_email }}</td>
            <td class="px-4 py-3 text-center">
              <template v-if="roleFilter === 'vendors'">
                <span v-if="c.purchase_count" class="inline-block px-2 py-0.5 text-xs bg-warning-50 text-warning-700 rounded">
                  {{ c.purchase_count }}
                </span>
                <span v-else class="text-neutral-300">—</span>
              </template>
              <template v-else>
                <span v-if="c.active_projects_count" class="inline-block px-2 py-0.5 text-xs bg-primary-50 text-primary-700 rounded">
                  {{ c.active_projects_count }}
                </span>
                <span v-else class="text-neutral-300">—</span>
              </template>
            </td>
            <td class="px-4 py-3 text-right font-mono">
              <template v-if="roleFilter === 'vendors'">
                <span v-if="c.costs && c.costs > 0">{{ formatMoney(c.costs, 'CZK') }}</span>
                <span v-else class="text-neutral-300">—</span>
              </template>
              <template v-else>
                <span v-if="c.revenue && c.revenue > 0">{{ formatMoney(c.revenue, c.currency_default) }}</span>
                <span v-else class="text-neutral-300">—</span>
              </template>
            </td>
            <td class="px-4 py-3 text-neutral-600 text-xs">
              <template v-if="roleFilter === 'vendors'">
                <span v-if="c.last_purchase_date">{{ formatDate(c.last_purchase_date) }}</span>
                <span v-else class="text-neutral-300">—</span>
              </template>
              <template v-else>
                <span v-if="c.last_invoice_date">{{ formatDate(c.last_invoice_date) }}</span>
                <span v-else class="text-neutral-300">—</span>
              </template>
            </td>
            <td class="px-4 py-3 text-center text-neutral-600 font-mono text-xs">{{ c.currency_default }}</td>
          </tr>
        </tbody>
      </table></div>

      <!-- Mobile: karty -->
      <div v-if="items.length" class="md:hidden divide-y divide-neutral-100">
        <div
          v-for="c in filteredItems"
          :key="`m-${c.id}`"
          @click="openClient(c)"
          class="cursor-pointer hover:bg-neutral-50 transition px-4 py-3"
        >
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 truncate">{{ c.company_name }}</div>
            <div class="font-mono text-sm whitespace-nowrap">
              <template v-if="roleFilter === 'vendors'">
                <span v-if="c.costs && c.costs > 0">{{ formatMoney(c.costs, 'CZK') }}</span>
                <span v-else class="text-neutral-300">—</span>
              </template>
              <template v-else>
                <span v-if="c.revenue && c.revenue > 0">{{ formatMoney(c.revenue, c.currency_default) }}</span>
                <span v-else class="text-neutral-300">—</span>
              </template>
            </div>
          </div>
          <div v-if="c.archived_at" class="text-xs text-neutral-400 mt-0.5">{{ t('common.archived') }}</div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <div class="truncate">
              <span class="font-mono">{{ c.ic || '—' }}</span>
              <span v-if="c.main_email" class="text-neutral-400"> · </span>
              <span v-if="c.main_email" class="truncate">{{ c.main_email }}</span>
            </div>
            <span class="font-mono whitespace-nowrap">{{ c.currency_default }}</span>
          </div>
          <div class="flex items-center justify-between gap-2 mt-2 text-xs">
            <span class="text-neutral-600">
              <span v-if="c.last_invoice_date">{{ formatDate(c.last_invoice_date) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </span>
            <span v-if="roleFilter === 'vendors' && c.purchase_count" class="px-2 py-0.5 bg-warning-50 text-warning-700 rounded">
              {{ t('client.invoice_count_label') }}: {{ c.purchase_count }}
            </span>
            <span v-else-if="c.active_projects_count" class="px-2 py-0.5 bg-primary-50 text-primary-700 rounded">
              {{ t('nav.projects') }}: {{ c.active_projects_count }}
            </span>
          </div>
        </div>
      </div>

      <div v-if="items.length" class="px-4 py-3 border-t border-neutral-200 flex items-center justify-between text-sm">
        <span class="text-neutral-500">{{ t('common.loaded_count', { loaded: filteredItems.length, total: total }) }}</span>
        <button v-if="page < pages" @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-1.5">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>

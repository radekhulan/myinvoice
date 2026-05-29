<script setup lang="ts">
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { projectsApi, type Project } from '@/api/projects'
import { invoicesApi, type InvoiceListItem } from '@/api/invoices'
import { formatMoney, formatDate, statusLabel, typeLabel, statusBadgeClass, isOverdue, invoiceRowClass } from '@/composables/useFormat'
import MonthlyRevenueChart from '@/components/charts/MonthlyRevenueChart.vue'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'

const toast = useToast()
const auth = useAuthStore()

const route = useRoute()
const router = useRouter()

const project = ref<Project | null>(null)
const loading = ref(true)
const invoices = ref<InvoiceListItem[]>([])
const invoicesLoading = ref(false)
const invoicesLoadingMore = ref(false)
const invoicesTotal = ref(0)
const invoicesPage = ref(1)
const invoicesPages = ref(1)

const canDelete = computed(() => (project.value?.invoices_count ?? 0) === 0)

// Splatnost s jednotkou: měsíc → „Měsíc" / „N× měsíc", jinak „N dní".
const dueLabel = computed(() => {
  const p = project.value
  if (!p) return ''
  if (p.payment_due_unit === 'month') {
    return p.payment_due_days === 1
      ? t('project.payment_due_preset_month')
      : `${p.payment_due_days}× ${t('project.payment_due_preset_month').toLowerCase()}`
  }
  return `${p.payment_due_days} ${t('common.days')}`
})

// Pro graf: primární měna = nejčastější v datech, fallback default zakázky
const primaryCurrency = computed(() => {
  const tally: Record<string, number> = {}
  for (const r of project.value?.revenue_by_month ?? []) tally[r.currency] = (tally[r.currency] ?? 0) + r.total
  const top = Object.entries(tally).sort((a, b) => b[1] - a[1])[0]
  return top?.[0] || project.value?.currency || 'CZK'
})
const overdueAny = computed(() => (project.value?.unpaid_summary ?? []).some(u => u.overdue_count > 0))
const monthlyChart = computed(() => {
  const data = (project.value?.revenue_by_month ?? []).filter(r => r.currency === primaryCurrency.value)
  return {
    labels: data.map(r => r.month),
    values: data.map(r => r.total),
  }
})

async function load() {
  const id = Number(route.params.id)
  loading.value = true
  invoicesLoading.value = true
  invoicesPage.value = 1
  try {
    const [p, grouped] = await Promise.all([
      projectsApi.get(id),
      invoicesApi.listGrouped({ project_id: id, page: 1 }),
    ])
    project.value = p
    invoices.value = grouped.data.flatMap(g => g.invoices)
    invoicesTotal.value = grouped.meta.total
    invoicesPages.value = grouped.meta.pages ?? 1
  } finally {
    loading.value = false
    invoicesLoading.value = false
  }
}

async function loadMoreInvoices() {
  if (!project.value) return
  invoicesLoadingMore.value = true
  invoicesPage.value++
  try {
    const grouped = await invoicesApi.listGrouped({ project_id: project.value.id, page: invoicesPage.value })
    invoices.value.push(...grouped.data.flatMap(g => g.invoices))
    invoicesTotal.value = grouped.meta.total
    invoicesPages.value = grouped.meta.pages ?? 1
  } finally {
    invoicesLoadingMore.value = false
  }
}

onMounted(load)

async function archive() {
  if (!project.value) return
  if (!confirm(t('project.archive_confirm'))) return
  await projectsApi.archive(project.value.id)
  router.push(`/clients/${project.value.client_id}`)
}

async function deleteProject() {
  if (!project.value) return
  if (!confirm(t('project.delete_warning', { name: project.value.name }))) return
  try {
    await projectsApi.delete(project.value.id)
    router.push(`/clients/${project.value.client_id}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('project.delete_failed'))
  }
}
</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

  <div v-else-if="project" class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 md:gap-4">
      <div class="min-w-0">
        <RouterLink :to="`/clients/${project.client_id}`" class="text-sm text-neutral-600 hover:text-neutral-900">
          ← {{ project.client_company_name }}
        </RouterLink>
        <h1 class="text-2xl font-semibold mt-1">{{ project.name }}</h1>
        <div class="text-sm text-neutral-500 mt-1 flex items-center gap-2 flex-wrap">
          <span class="text-xs px-2 py-0.5 rounded"
            :class="{
              'bg-success-50 text-success-600': project.status === 'active',
              'bg-warning-50 text-warning-600': project.status === 'paused',
              'bg-neutral-100 text-neutral-600': project.status === 'closed',
            }">{{ project.status }}</span>
          <span v-if="project.project_number" class="font-mono text-xs">{{ project.project_number }}</span>
          <span v-if="project.requires_work_report_approval"
            class="text-xs px-2 py-0.5 rounded bg-primary-100 text-primary-700"
            :title="t('project.requires_approval_hint')">
            ✓ {{ t('project.requires_approval_badge') }}
          </span>
        </div>
      </div>
      <div class="flex flex-wrap gap-2 md:justify-end">
        <RouterLink v-if="(project.status === 'active') && auth.canWrite"
          :to="`/invoices/new?client_id=${project.client_id}&project_id=${project.id}`"
          class="cursor-pointer px-3 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('project.new_invoice') }}
        </RouterLink>
        <RouterLink v-if="auth.canWrite" :to="`/projects/${project.id}/edit`"
          class="cursor-pointer px-3 h-9 text-sm border border-primary-500/40 rounded-md text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          {{ t('project.edit_project') }}
        </RouterLink>
        <RouterLink v-if="auth.canWrite" :to="`/clients/${project.client_id}/edit`"
          class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z"/></svg>
          {{ t('project.edit_client') }}
        </RouterLink>
        <button v-if="auth.canWrite" @click="archive"
          class="cursor-pointer px-3 h-9 text-sm border border-warning-500/50 rounded-md text-warning-600 hover:bg-warning-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-warning-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 1 1 0-4h14a2 2 0 1 1 0 4M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8m-9 4h4"/></svg>
          {{ t('common.archive') }}
        </button>
        <button v-if="(canDelete) && auth.canWrite" @click="deleteProject"
          class="cursor-pointer px-3 h-9 text-sm border border-danger-500/50 rounded-md text-danger-500 hover:bg-danger-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-danger-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
          {{ t('common.delete') }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('project.rates_due_section') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.hourly_rate') }}</dt><dd class="font-mono">{{ project.hourly_rate.toLocaleString('cs') }} {{ project.currency }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.due_short') }}</dt><dd>{{ dueLabel }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('common.currency') }}</dt><dd class="font-mono">{{ project.currency }}</dd></div>
        </dl>
      </div>

      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('common.budgets') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.budget_total_short') }}</dt><dd class="font-mono">{{ project.budget_total?.toLocaleString('cs') ?? '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('common.yearly') }}</dt><dd class="font-mono">{{ project.budget_yearly?.toLocaleString('cs') ?? '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('common.monthly') }}</dt><dd class="font-mono">{{ project.budget_monthly?.toLocaleString('cs') ?? '—' }}</dd></div>
        </dl>
      </div>

      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('project.reference_section') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.project_number') }}</dt><dd class="font-mono">{{ project.project_number || '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.contract_number') }}</dt><dd class="font-mono">{{ project.contract_number || '—' }}</dd></div>
        </dl>
      </div>
    </div>

    <div v-if="project.billing_emails.length" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('project.billing_emails') }}</h3>
      <ul class="space-y-1.5 text-sm">
        <li v-for="b in project.billing_emails" :key="b.position" class="flex items-center justify-between border-b border-neutral-100 pb-1.5 last:border-b-0">
          <span class="text-neutral-900">{{ b.email }}</span>
          <span class="text-xs text-neutral-500">{{ b.label || '—' }}</span>
        </li>
      </ul>
      <p class="text-xs text-neutral-400 mt-2">{{ t('project.client_main_email_note', { email: project.client_main_email }) }}</p>
    </div>

    <div v-if="project.note" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ t('project.note') }}</h3>
      <p class="text-sm text-neutral-700 whitespace-pre-wrap">{{ project.note }}</p>
    </div>

    <!-- KPI: nezaplaceno + po splatnosti -->
    <div v-if="(project.unpaid_summary?.length ?? 0) > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('client.unpaid') }}</h3>
        <div class="space-y-1">
          <div v-for="u in project.unpaid_summary || []" :key="`u-${u.currency}`" class="flex items-baseline justify-between">
            <span class="text-2xl font-semibold font-mono text-neutral-900">{{ formatMoney(u.unpaid_total, u.currency) }}</span>
            <span class="text-xs text-neutral-500 ml-3 whitespace-nowrap">{{ t('client.n_invoices', { n: u.unpaid_count }) }}</span>
          </div>
        </div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm" :class="overdueAny ? 'border-danger-500/40' : ''">
        <h3 class="text-sm font-semibold uppercase tracking-wide mb-3" :class="overdueAny ? 'text-danger-500' : 'text-neutral-500'">{{ t('client.overdue') }}</h3>
        <div class="space-y-1">
          <div v-for="u in project.unpaid_summary || []" :key="`o-${u.currency}`" class="flex items-baseline justify-between">
            <span class="text-2xl font-semibold font-mono" :class="u.overdue_total > 0 ? 'text-danger-500' : 'text-neutral-400'">{{ formatMoney(u.overdue_total, u.currency) }}</span>
            <span class="text-xs ml-3 whitespace-nowrap" :class="u.overdue_count > 0 ? 'text-danger-500' : 'text-neutral-400'">{{ t('client.n_invoices', { n: u.overdue_count }) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Obrat: graf po měsících + sumace po letech -->
    <div v-if="(project.revenue_by_month?.length ?? 0) > 0" class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="md:col-span-2 bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('client.revenue_by_month') }}</h3>
          <span class="text-xs font-mono text-neutral-500">{{ primaryCurrency }}</span>
        </div>
        <MonthlyRevenueChart :labels="monthlyChart.labels" :values="monthlyChart.values" :currency="primaryCurrency" />
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('client.revenue_by_year') }}</h3>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in project.revenue_by_year || []" :key="`${r.year}-${r.currency}`">
              <td class="py-2 text-neutral-900 font-medium">{{ r.year }}</td>
              <td class="py-2 text-right font-mono text-neutral-900">{{ formatMoney(r.total, r.currency) }}</td>
              <td class="py-2 pl-3 text-right text-xs text-neutral-500 whitespace-nowrap">{{ t('client.year_invoices', { n: r.count }) }}</td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- Faktury -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
        <h3 class="font-semibold">{{ t('nav.invoices') }} <span v-if="invoicesTotal" class="text-neutral-400 font-normal">({{ invoicesTotal }})</span></h3>
        <RouterLink v-if="(project.status === 'active') && auth.canWrite"
          :to="`/invoices/new?client_id=${project.client_id}&project_id=${project.id}`"
          class="px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md inline-flex items-center">
          {{ t('invoice.new') }}
        </RouterLink>
      </div>
      <div v-if="invoicesLoading" class="p-8 text-center text-neutral-500 text-sm">{{ t('common.loading') }}</div>
      <div v-else-if="!invoices.length" class="p-8 text-center text-neutral-500 text-sm">
        {{ t('common.no_data') }}
      </div>
      <!-- Desktop: tabulka -->
      <div v-else class="hidden md:block overflow-x-auto"><table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.varsymbol') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.type') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.issue_date') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.due_date') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('invoice.amount_to_pay') }}</th>
            <th class="text-center px-4 py-2.5 font-medium">{{ t('invoice.status_label') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="inv in invoices" :key="inv.id" class="cursor-pointer hover:bg-neutral-50"
              :class="invoiceRowClass(inv.due_date, inv.status)"
              @click="router.push(`/invoices/${inv.id}`)">
            <td class="px-4 py-2.5 font-mono">{{ inv.varsymbol || `#${inv.id}` }}</td>
            <td class="px-4 py-2.5 text-neutral-600">{{ typeLabel(inv.invoice_type) }}</td>
            <td class="px-4 py-2.5 text-neutral-600">{{ formatDate(inv.issue_date) }}</td>
            <td class="px-4 py-2.5">
              <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-600 font-medium' : 'text-neutral-600'">
                {{ formatDate(inv.due_date) }}
              </span>
            </td>
            <td class="px-4 py-2.5 text-right font-mono">
              {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
            </td>
            <td class="px-4 py-2.5 text-center">
              <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                {{ statusLabel(inv.status) }}
              </span>
            </td>
          </tr>
        </tbody>
      </table></div>

      <!-- Mobile: karty -->
      <div v-if="invoices.length" class="md:hidden divide-y divide-neutral-100">
        <div v-for="inv in invoices" :key="`m-${inv.id}`"
          @click="router.push(`/invoices/${inv.id}`)"
          class="cursor-pointer hover:bg-neutral-50 px-4 py-3"
          :class="invoiceRowClass(inv.due_date, inv.status)">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-mono font-medium text-neutral-900">{{ inv.varsymbol || `#${inv.id}` }}</div>
            <div class="font-mono text-sm font-semibold whitespace-nowrap">
              {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
            </div>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <span>{{ typeLabel(inv.invoice_type) }}</span>
            <span>
              <span>{{ formatDate(inv.issue_date) }}</span>
              <span class="text-neutral-400 mx-1"> → </span>
              <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''">
                {{ formatDate(inv.due_date) }}
              </span>
            </span>
          </div>
          <div class="mt-2">
            <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
              {{ statusLabel(inv.status) }}
            </span>
          </div>
        </div>
      </div>

      <div v-if="invoices.length" class="px-5 py-3 border-t border-neutral-200 flex items-center justify-between text-sm">
        <span class="text-neutral-500">{{ t('common.loaded_count', { loaded: invoices.length, total: invoicesTotal }) }}</span>
        <button v-if="invoicesPage < invoicesPages" @click="loadMoreInvoices" :disabled="invoicesLoadingMore"
          class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-1.5">
          {{ invoicesLoadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>
    <LinkedDocumentsPanel v-if="project" class="mt-4 block" entity-type="project" :entity-id="project.id" />
  </div>
</template>

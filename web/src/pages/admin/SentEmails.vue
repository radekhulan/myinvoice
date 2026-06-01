<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi, type SentEmail } from '@/api/admin'

const { t } = useI18n()

const entries = ref<SentEmail[]>([])
const total = ref(0)
const types = ref<Array<{ action: string; cnt: number }>>([])
const loading = ref(false)

const filter = ref({
  type: '',
  limit: 100,
  offset: 0,
})

async function load() {
  loading.value = true
  try {
    const params: Record<string, string | number> = { limit: filter.value.limit, offset: filter.value.offset }
    if (filter.value.type) params.type = filter.value.type
    const r = await adminApi.sentEmails(params)
    entries.value = r.data
    total.value = r.total
    types.value = r.types
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => filter.value.type, () => { filter.value.offset = 0; load() })

/** action z activity_log → i18n suffix popisku + barevný pill. Jedno místo na typ e-mailu. */
const EMAIL_TYPES: Record<string, { key: string; badge: string }> = {
  'invoice.sent':                   { key: 'invoice_sent',            badge: 'bg-primary-100 text-primary-700' },
  'invoice.reminder_sent':          { key: 'reminder_sent',           badge: 'bg-warning-50 text-warning-600' },
  'invoice.approval_reminder_sent': { key: 'approval_reminder_sent',  badge: 'bg-warning-50 text-warning-600' },
  'invoice.payment_thanks_sent':    { key: 'payment_thanks_sent',     badge: 'bg-success-50 text-success-600' },
  'recurring.reminder_sent':        { key: 'recurring_reminder_sent', badge: 'bg-warning-50 text-warning-600' },
  'email.sent_test':                { key: 'test',                    badge: 'bg-neutral-100 text-neutral-600' },
  'email.sent_test_reminder':       { key: 'test_reminder',           badge: 'bg-neutral-100 text-neutral-600' },
}

function typeLabel(action: string): string {
  const meta = EMAIL_TYPES[action]
  return meta ? t(`sent_emails.types.${meta.key}`) : action
}
function typeBadge(action: string): string {
  return EMAIL_TYPES[action]?.badge ?? 'bg-neutral-100 text-neutral-600'
}

function fmtRecipients(r: string[]): string {
  return r.length ? r.join(', ') : '—'
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
      <h1 class="text-2xl font-semibold">{{ t('sent_emails.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('sent_emails.subtitle') }}</p>
    </div>

    <!-- Filtr typu -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3 flex flex-wrap gap-2 items-center">
      <select v-model="filter.type" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option value="">{{ t('sent_emails.all_types') }}</option>
        <option v-for="ty in types" :key="ty.action" :value="ty.action">{{ typeLabel(ty.action) }} ({{ ty.cnt }})</option>
      </select>
      <button @click="load" class="cursor-pointer h-9 px-3 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
        {{ t('sent_emails.refresh') }}
      </button>
      <span class="ml-auto text-xs text-neutral-500">{{ t('sent_emails.total', { n: total, p: currentPage, tp: totalPages }) }}</span>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="!entries.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-12 text-center text-neutral-500">
      {{ t('sent_emails.no_records') }}
    </div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium w-44">{{ t('sent_emails.time') }}</th>
            <th class="px-3 py-2 text-left font-medium w-52">{{ t('sent_emails.type') }}</th>
            <th class="px-3 py-2 text-left font-medium w-48">{{ t('sent_emails.invoice') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('sent_emails.recipients') }}</th>
            <th class="px-3 py-2 text-left font-medium w-44">{{ t('sent_emails.sender') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="e in entries" :key="e.id" class="hover:bg-neutral-50 align-top">
            <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ fmtTime(e.created_at) }}</td>
            <td class="px-3 py-2">
              <span class="text-xs px-2 py-0.5 rounded font-medium" :class="typeBadge(e.action)"
                    :title="e.smtp_response || ''">{{ typeLabel(e.action) }}</span>
            </td>
            <td class="px-3 py-2 text-xs">
              <RouterLink v-if="e.invoice_id" :to="`/invoices/${e.invoice_id}`"
                          class="font-medium text-primary-700 hover:underline">
                {{ e.invoice_varsymbol || `#${e.invoice_id}` }}
              </RouterLink>
              <span v-else class="text-neutral-400">—</span>
              <div v-if="e.client_company_name" class="text-neutral-500 truncate max-w-[16rem]">{{ e.client_company_name }}</div>
            </td>
            <td class="px-3 py-2 text-xs text-neutral-600 break-all leading-snug">{{ fmtRecipients(e.recipients) }}</td>
            <td class="px-3 py-2 text-xs break-words">
              <span v-if="e.user_email">{{ e.user_name || e.user_email }}</span>
              <span v-else class="text-neutral-400 italic">{{ t('sent_emails.sender_system') }}</span>
            </td>
          </tr>
        </tbody>
      </table>
      </div>

      <!-- Mobile: karty -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="e in entries" :key="`m-${e.id}`" class="p-3 space-y-1">
          <div class="flex items-baseline justify-between gap-2">
            <span class="text-xs px-2 py-0.5 rounded font-medium" :class="typeBadge(e.action)">{{ typeLabel(e.action) }}</span>
            <RouterLink v-if="e.invoice_id" :to="`/invoices/${e.invoice_id}`"
                        class="text-xs font-medium text-primary-700 hover:underline whitespace-nowrap">
              {{ e.invoice_varsymbol || `#${e.invoice_id}` }}
            </RouterLink>
          </div>
          <div class="text-xs text-neutral-600 break-all leading-snug">{{ fmtRecipients(e.recipients) }}</div>
          <div v-if="e.client_company_name" class="text-xs text-neutral-500 truncate">{{ e.client_company_name }}</div>
          <div class="flex items-baseline justify-between gap-2 text-xs text-neutral-500">
            <span class="truncate">
              <span v-if="e.user_email">{{ e.user_name || e.user_email }}</span>
              <span v-else class="italic">{{ t('sent_emails.sender_system') }}</span>
            </span>
            <span class="font-mono whitespace-nowrap">{{ fmtTime(e.created_at) }}</span>
          </div>
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

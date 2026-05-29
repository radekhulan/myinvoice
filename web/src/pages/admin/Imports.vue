<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { uploadImport, type ImportReport, type ImportResultRow, type ImportKind } from '@/api/imports'
import { purchaseInvoicesApi, type InboxScanResult } from '@/api/purchaseInvoices'
import { integrationsApi, type AnthropicCredentialsStatus } from '@/api/integrations'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'

type TabKey = 'issued' | 'purchase'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const toast = useToast()

// Tab inicializace z URL (?tab=issued|purchase) — pojďme respektovat hash i query
const initialTab: TabKey = (() => {
  const q = String(route.query.tab ?? '')
  return q === 'purchase' ? 'purchase' : 'issued'
})()
const activeTab = ref<TabKey>(initialTab)

// Watch query.tab — když user clickne v sidebar, route.query se změní, aktualizujme tab
watch(() => route.query.tab, (v) => {
  if (v === 'purchase' || v === 'issued') activeTab.value = v
})

// ── Vystavené (existující flow) ─────────────────────────────────────────────
const files = ref<File[]>([])
const uploading = ref(false)
const error = ref('')
const report = ref<ImportReport | null>(null)

function onPick(e: Event) {
  const input = e.target as HTMLInputElement
  if (!input.files) return
  files.value = Array.from(input.files)
  report.value = null
  error.value = ''
}
function onDrop(e: DragEvent) {
  e.preventDefault()
  const dropped = e.dataTransfer?.files
  if (!dropped) return
  files.value = Array.from(dropped)
  report.value = null
  error.value = ''
}
async function submit(kind: ImportKind = 'issued') {
  if (files.value.length === 0) return
  uploading.value = true
  error.value = ''
  report.value = null
  try {
    report.value = await uploadImport(files.value, kind)
  } catch (e: any) {
    error.value = e?.message || t('imports.upload_failed')
  } finally {
    uploading.value = false
  }
}
function clear() {
  files.value = []
  report.value = null
  error.value = ''
}
const rows = computed<ImportResultRow[]>(() => report.value?.results ?? [])
const statusBadge = (s: string) => {
  if (s === 'created') return 'bg-success-50 text-success-600 border-success-500/40'
  if (s === 'skipped') return 'bg-warning-50 text-warning-600 border-warning-500/40'
  return 'bg-danger-50 text-danger-500 border-danger-500/40'
}

// ── Přijaté (scan inbox flow) ──────────────────────────────────────────────
const scanRunning = ref(false)
const scanResult = ref<InboxScanResult | null>(null)
const scanDryRun = ref(false)

// Items které NEBYLY importovány (AI selhalo / ISDOC missing bez AI / parser error)
const notImportedItems = computed(() => {
  if (!scanResult.value) return []
  return scanResult.value.details.filter(d => d.status === 'skipped' || d.status === 'failed')
})

// AI integration status — load při mount aby uživatel věděl, zda PDF bez ISDOC
// bude zpracováno (AI fallback) nebo skipnuté (no AI config)
const aiStatus = ref<AnthropicCredentialsStatus | null>(null)
async function loadAiStatus() {
  try {
    aiStatus.value = await integrationsApi.getAnthropicCreds()
  } catch {
    aiStatus.value = { configured: false } as AnthropicCredentialsStatus
  }
}
onMounted(loadAiStatus)
async function runScan() {
  scanRunning.value = true
  scanResult.value = null
  try {
    scanResult.value = await purchaseInvoicesApi.scanInbox(scanDryRun.value)
    toast.success(t('purchase_invoice.scan_inbox.result_summary', {
      created: scanResult.value.created,
      skipped: scanResult.value.skipped,
      failed:  scanResult.value.failed,
    }))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    scanRunning.value = false
  }
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('imports.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('imports.subtitle_unified') }}</p>
    </div>

    <!-- Tabs: Vystavené / Přijaté -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm max-w-3xl">
      <div class="px-4 pt-2 border-b border-neutral-100 flex items-center gap-1">
        <button
          type="button"
          @click="activeTab = 'issued'"
          class="cursor-pointer px-3 py-2 text-sm border-b-2 transition"
          :class="activeTab === 'issued'
            ? 'border-primary-600 text-primary-700 font-medium'
            : 'border-transparent text-neutral-600 hover:text-neutral-900'"
        >{{ t('imports.tab_issued') }}</button>
        <button
          type="button"
          @click="activeTab = 'purchase'"
          class="cursor-pointer px-3 py-2 text-sm border-b-2 transition"
          :class="activeTab === 'purchase'
            ? 'border-primary-600 text-primary-700 font-medium'
            : 'border-transparent text-neutral-600 hover:text-neutral-900'"
        >{{ t('imports.tab_purchase') }}</button>
      </div>

      <!-- ════ Tab: Vystavené ════ -->
      <div v-if="activeTab === 'issued'" class="p-5 space-y-4">
        <div class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-600">
          <strong>{{ t('imports.supplier_required_title') }}:</strong>
          {{ t('imports.supplier_required_hint') }}
        </div>
        <div class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">
          <strong>{{ t('imports.status_rule_title') }}:</strong>
          {{ t('imports.status_rule_hint') }}
        </div>
        <label
          @dragover.prevent
          @drop="onDrop"
          class="block border-2 border-dashed border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30 rounded-lg p-8 text-center cursor-pointer transition"
        >
          <input
            type="file"
            multiple
            accept=".xml,.isdoc,.zip,.pdf,application/xml,application/zip,application/x-isdoc,application/pdf"
            @change="onPick"
            class="hidden"
          />
          <svg class="w-8 h-8 mx-auto text-neutral-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9 5 5 0 0 1 9.9-1A5.5 5.5 0 0 1 18.5 16H17m-5-4v9m0-9l-3 3m3-3l3 3" />
          </svg>
          <div class="text-sm font-medium text-neutral-700">{{ t('imports.drop_or_click') }}</div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('imports.formats_hint') }}</div>
        </label>

        <div v-if="files.length > 0" class="border border-neutral-200 rounded-md p-3 bg-neutral-50">
          <div class="text-xs font-medium text-neutral-700 mb-2">{{ t('imports.selected_files') }} ({{ files.length }})</div>
          <ul class="text-sm space-y-1 font-mono">
            <li v-for="f in files" :key="f.name" class="flex justify-between text-neutral-700">
              <span class="truncate">{{ f.name }}</span>
              <span class="text-neutral-400 ml-2">{{ Math.round(f.size / 1024) }} kB</span>
            </li>
          </ul>
        </div>

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </div>

        <div class="flex gap-2">
          <button
            @click="submit('issued')"
            :disabled="uploading || files.length === 0"
            class="cursor-pointer flex-1 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center justify-center gap-2"
          >
            {{ uploading ? t('imports.uploading') : t('imports.upload') }}
          </button>
          <button
            v-if="files.length > 0 || report"
            @click="clear"
            :disabled="uploading"
            class="cursor-pointer h-10 px-4 border border-neutral-300 hover:bg-neutral-50 text-sm rounded-md"
          >
            {{ t('common.close') }}
          </button>
        </div>

        <p class="text-xs text-neutral-500">{{ t('imports.hint') }}</p>
      </div>

      <!-- ════ Tab: Přijaté ════ -->
      <div v-else class="p-5 space-y-4">
        <!-- Dropzone — multipart upload pro PDF/ISDOC přijatých faktur (kind=purchase) -->
        <div class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">
          <strong>{{ t('imports.purchase_upload_title') }}:</strong>
          {{ t('imports.purchase_upload_hint') }}
        </div>
        <label
          @dragover.prevent
          @drop="onDrop"
          class="block border-2 border-dashed border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30 rounded-lg p-8 text-center cursor-pointer transition"
        >
          <input
            type="file"
            multiple
            accept=".xml,.isdoc,.zip,.pdf,application/xml,application/zip,application/x-isdoc,application/pdf"
            @change="onPick"
            class="hidden"
          />
          <svg class="w-8 h-8 mx-auto text-neutral-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9 5 5 0 0 1 9.9-1A5.5 5.5 0 0 1 18.5 16H17m-5-4v9m0-9l-3 3m3-3l3 3" />
          </svg>
          <div class="text-sm font-medium text-neutral-700">{{ t('imports.drop_or_click') }}</div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('imports.formats_hint') }}</div>
        </label>

        <div v-if="files.length > 0" class="border border-neutral-200 rounded-md p-3 bg-neutral-50">
          <div class="text-xs font-medium text-neutral-700 mb-2">{{ t('imports.selected_files') }} ({{ files.length }})</div>
          <ul class="text-sm space-y-1 font-mono">
            <li v-for="f in files" :key="f.name" class="flex justify-between text-neutral-700">
              <span class="truncate">{{ f.name }}</span>
              <span class="text-neutral-400 ml-2">{{ Math.round(f.size / 1024) }} kB</span>
            </li>
          </ul>
        </div>

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>

        <div class="flex gap-2">
          <button
            @click="submit('purchase')"
            :disabled="uploading || files.length === 0"
            class="cursor-pointer flex-1 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center justify-center gap-2"
          >
            {{ uploading ? t('imports.uploading') : t('imports.upload') }}
          </button>
          <button
            v-if="files.length > 0 || report"
            @click="clear"
            :disabled="uploading"
            class="cursor-pointer h-10 px-4 border border-neutral-300 hover:bg-neutral-50 text-sm rounded-md"
          >
            {{ t('common.close') }}
          </button>
        </div>

        <div class="border-t border-neutral-100 pt-4 mt-2">
        <div class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">
          <strong>{{ t('imports.purchase_scan_title') }}:</strong>
          {{ t('imports.purchase_scan_hint') }}
        </div>

        <!-- AI integration status — informuje user zda PDF bez ISDOC bude zpracováno -->
        <div class="mt-3">
          <div v-if="aiStatus === null" class="rounded-md bg-neutral-50 border border-neutral-200 px-3 py-2 text-sm text-neutral-500">
            {{ t('common.loading') }}…
          </div>
          <div v-else-if="aiStatus.configured"
               class="rounded-md bg-success-50 border border-success-500/40 px-3 py-2 text-sm text-success-600">
            <strong>✓ {{ t('imports.ai_active_title') }}</strong>
            <span class="ml-2 font-mono text-xs text-success-600/80">{{ aiStatus.default_model }}</span>
            <span class="ml-3 text-xs">{{ t('imports.ai_active_hint') }}</span>
          </div>
          <div v-else
               class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-700">
            <strong>⚠ {{ t('imports.ai_not_configured_title') }}</strong>
            <span class="ml-2 text-xs">{{ t('imports.ai_not_configured_hint') }}</span>
            <RouterLink to="/admin/integrations?tab=ai" class="ml-1 text-xs text-primary-700 hover:underline whitespace-nowrap">
              → {{ t('nav.ai_import') }}
            </RouterLink>
          </div>
        </div>

        <div class="border border-neutral-200 rounded-lg p-5 bg-neutral-50/50">
          <div class="flex items-start gap-3 mb-3">
            <svg class="w-6 h-6 text-primary-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7M3 7l2-3h14l2 3M3 7h18M8 11h8" />
            </svg>
            <div class="flex-1">
              <h3 class="font-medium text-neutral-900">{{ t('purchase_invoice.scan_inbox.title') }}</h3>
              <p class="text-xs text-neutral-500 mt-1">{{ t('imports.purchase_scan_path_hint') }}</p>
            </div>
          </div>

          <label class="inline-flex items-center gap-2 text-sm mb-3">
            <input v-model="scanDryRun" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            <span>{{ t('purchase_invoice.scan_inbox.dry_run') }}</span>
          </label>

          <div class="flex gap-2">
            <button
              type="button"
              @click="runScan"
              :disabled="scanRunning"
              class="cursor-pointer h-10 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md"
            >
              {{ scanRunning ? t('purchase_invoice.scan_inbox.running') : '📥 ' + t('purchase_invoice.scan_inbox.trigger') }}
            </button>
          </div>
        </div>

        <!-- Plus odkaz na PDF editor pro 1-by-1 upload (drag & drop přímo na faktuře) -->
        <div class="rounded-md bg-neutral-50 border border-neutral-200 px-3 py-2 text-sm text-neutral-700">
          <strong>{{ t('imports.purchase_manual_title') }}:</strong>
          {{ t('imports.purchase_manual_hint') }}
          <button type="button" @click="router.push('/purchase-invoices/new')"
                  class="cursor-pointer ml-1 text-primary-700 hover:underline font-medium">
            {{ t('purchase_invoice.new') }}
          </button>
        </div>

        <!-- Scan result -->
        <div v-if="scanResult" class="mt-4 bg-surface border border-neutral-200 rounded-lg p-4">
          <div class="flex flex-wrap items-center gap-4 mb-3 text-sm">
            <div><span class="font-semibold text-success-600">{{ scanResult.created }}</span> {{ t('imports.summary_created') }}</div>
            <div><span class="font-semibold text-warning-600">{{ scanResult.skipped }}</span> {{ t('imports.summary_skipped') }}</div>
            <div><span class="font-semibold text-danger-500">{{ scanResult.failed }}</span> {{ t('imports.summary_failed') }}</div>
          </div>
          <p v-if="scanResult.inbox_dir" class="text-xs text-neutral-500 mb-2 font-mono">{{ scanResult.inbox_dir }}</p>

          <!-- Prominent box: faktury které NEBYLY importovány (failed + skipped, ale ne 'imported') -->
          <div v-if="notImportedItems.length > 0" class="mt-3 rounded-md bg-warning-50 border border-warning-500/40 p-3">
            <div class="text-sm font-semibold text-warning-700 mb-2">
              ⚠ {{ t('purchase_invoice.scan_inbox.not_imported_title', { n: notImportedItems.length }) }}
            </div>
            <ul class="space-y-1.5 max-h-72 overflow-y-auto">
              <li v-for="(d, i) in notImportedItems" :key="i" class="text-xs flex items-start gap-2">
                <span class="inline-block px-1.5 py-0.5 rounded text-[10px] shrink-0" :class="statusBadge(d.status)">{{ d.status }}</span>
                <div class="flex-1 min-w-0">
                  <div class="font-mono text-neutral-800 truncate">{{ d.file ? d.file.split(/[/\\]/).pop() : '—' }}</div>
                  <div v-if="d.reason" class="text-neutral-600 mt-0.5">{{ d.reason }}</div>
                </div>
              </li>
            </ul>
          </div>

          <details v-if="scanResult.details.length > 0" class="text-xs mt-3">
            <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">
              {{ t('purchase_invoice.scan_inbox.details') }} ({{ scanResult.details.length }})
            </summary>
            <ul class="mt-2 space-y-1 max-h-72 overflow-y-auto">
              <li v-for="(d, i) in scanResult.details" :key="i" class="font-mono text-[11px] border-b border-neutral-50 py-1">
                <span class="inline-block px-1.5 py-0.5 rounded text-[10px]" :class="statusBadge(d.status)">{{ d.status }}</span>
                <span class="ml-1 text-neutral-700">{{ d.file ? d.file.split(/[/\\]/).pop() : '—' }}</span>
                <span v-if="d.reason" class="ml-1 text-neutral-500">— {{ d.reason }}</span>
              </li>
            </ul>
          </details>
        </div>
        </div><!-- /border-t scan inbox wrap -->
      </div>
    </div>

    <!-- Report — pro oba tabs (issued / purchase) -->
    <div v-if="report" class="mt-6 bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm max-w-3xl">
      <div class="flex items-center gap-4 mb-4 text-sm">
        <div><span class="font-semibold text-success-600">{{ report.summary.created }}</span> {{ t('imports.summary_created') }}</div>
        <div><span class="font-semibold text-warning-600">{{ report.summary.skipped }}</span> {{ t('imports.summary_skipped') }}</div>
        <div><span class="font-semibold text-danger-500">{{ report.summary.failed }}</span> {{ t('imports.summary_failed') }}</div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-200">
              <th class="py-2 pr-3">{{ t('imports.col_file') }}</th>
              <th class="py-2 pr-3">{{ t('imports.col_status') }}</th>
              <th class="py-2 pr-3">{{ t('imports.col_varsymbol') }}</th>
              <th class="py-2">{{ t('imports.col_detail') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in rows" :key="i" class="border-b border-neutral-100">
              <td class="py-2 pr-3 font-mono text-xs truncate max-w-xs">{{ r.file }}</td>
              <td class="py-2 pr-3">
                <span class="inline-block px-2 py-0.5 text-xs rounded border" :class="statusBadge(r.status)">
                  {{ t('imports.status_' + r.status) }}
                </span>
              </td>
              <td class="py-2 pr-3 font-mono">{{ r.varsymbol || '—' }}</td>
              <td class="py-2 text-neutral-600">
                <span v-if="r.status === 'created'">
                  <!-- Vystavená faktura -->
                  <a v-if="r.invoice_id" :href="`/invoices/${r.invoice_id}`" class="text-primary-700 hover:underline">#{{ r.invoice_id }}</a>
                  <!-- Přijatá faktura (purchase route) -->
                  <a v-if="r.purchase_invoice_id" :href="`/purchase-invoices/${r.purchase_invoice_id}`" class="text-primary-700 hover:underline">
                    #{{ r.purchase_invoice_id }}
                  </a>
                  <!-- Kind badge — vystavená vs přijatá -->
                  <span
                    v-if="r.kind"
                    class="ml-2 text-xs px-1.5 py-0.5 rounded border"
                    :class="r.kind === 'purchase'
                      ? 'bg-warning-50 text-warning-600 border-warning-500/40'
                      : 'bg-primary-50 text-primary-700 border-primary-500/40'"
                  >
                    {{ t('imports.kind_' + r.kind) }}
                  </span>
                  <span
                    v-if="r.imported_status"
                    class="ml-2 text-xs px-1.5 py-0.5 rounded border"
                    :class="r.imported_status === 'paid' ? 'bg-success-50 text-success-600 border-success-500/40' : 'bg-neutral-50 text-neutral-600 border-neutral-200'"
                  >
                    {{ t('imports.imported_as_' + r.imported_status) }}
                  </span>
                  <span v-if="r.client_created" class="ml-2 text-xs text-success-600">{{ t('imports.new_client') }}</span>
                  <span v-if="r.project_id" class="ml-2 text-xs text-primary-700">{{ t('imports.new_project') }}</span>
                </span>
                <span v-else class="text-xs">{{ r.reason || '—' }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

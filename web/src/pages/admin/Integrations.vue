<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { integrationsApi,
  type IdokladCredentialsStatus, type FakturoidCredentialsStatus,
  type AnthropicCredentialsStatus, type AiExtractResult, type ImportJob } from '@/api/integrations'
import { useRouter, useRoute } from 'vue-router'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const toast = useToast()

type Tab = 'idoklad' | 'fakturoid' | 'ai'
// Tab z ?tab=... query (default idoklad). Watch pro proklik mezi sidebar položkami
// "Externí integrace" (no query) ↔ "AI import" (?tab=ai).
const route = useRoute()
function readTabFromQuery(): Tab {
  const q = String(route.query.tab ?? '')
  return q === 'fakturoid' || q === 'ai' ? q as Tab : 'idoklad'
}
const tab = ref<Tab>(readTabFromQuery())
watch(() => route.query.tab, () => {
  tab.value = readTabFromQuery()
})

// ── iDoklad credentials state ─────────────────────────────────────────
const idokladStatus = ref<IdokladCredentialsStatus | null>(null)
const idokladClientId = ref('')
const idokladClientSecret = ref('')
const idokladSaving = ref(false)
const idokladTestMsg = ref<{ ok: boolean; text: string } | null>(null)
const showSecret = ref(false)

async function loadIdokladStatus() {
  try {
    idokladStatus.value = await integrationsApi.getIdokladCreds()
    if (idokladStatus.value?.client_id) idokladClientId.value = idokladStatus.value.client_id
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function saveIdokladCreds() {
  if (!idokladClientId.value || !idokladClientSecret.value) {
    toast.error('Vyplň oboje pole (client_id i client_secret).')
    return
  }
  idokladSaving.value = true
  idokladTestMsg.value = null
  try {
    const r = await integrationsApi.setIdokladCreds(idokladClientId.value, idokladClientSecret.value)
    if (r.test_ok) {
      idokladTestMsg.value = { ok: true, text: t('integrations.idoklad.test_success') }
      idokladClientSecret.value = ''  // clear sensitive field
      await loadIdokladStatus()
    } else {
      idokladTestMsg.value = { ok: false, text: r.test_error || 'Test connectivity selhal' }
    }
  } catch (e) {
    idokladTestMsg.value = { ok: false, text: apiErrorMessage(e) }
  } finally {
    idokladSaving.value = false
  }
}

async function deleteIdokladCreds() {
  if (!confirm(t('integrations.idoklad.delete_confirm'))) return
  try {
    await integrationsApi.deleteIdokladCreds()
    idokladStatus.value = null
    idokladClientId.value = ''
    idokladClientSecret.value = ''
    idokladTestMsg.value = null
    toast.success(t('integrations.idoklad.deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

// ── iDoklad import job state ──────────────────────────────────────────
const startParams = ref({
  include_clients: true,
  include_issued: true,
  include_received: true,
  incremental: false,
  download_attachments: false,
  dry_run: false,
})
const currentJob = ref<ImportJob | null>(null)
const starting = ref(false)
let pollTimer: ReturnType<typeof setInterval> | null = null

async function startImport() {
  if (starting.value) return
  starting.value = true
  try {
    const r = await integrationsApi.startIdoklad(startParams.value)
    toast.success(t('integrations.idoklad.started', { jobId: r.job_id }))
    await pollJob(r.job_id)
  } catch (e: any) {
    toast.error(apiErrorMessage(e))
  } finally {
    starting.value = false
  }
}

async function pollJob(jobId: number) {
  // Initial fetch
  currentJob.value = await integrationsApi.getJob(jobId)
  if (pollTimer) clearInterval(pollTimer)
  // Poll každé 2s dokud queued/running
  pollTimer = setInterval(async () => {
    if (!currentJob.value) return
    try {
      currentJob.value = await integrationsApi.getJob(jobId)
      if (['completed', 'failed', 'cancelled'].includes(currentJob.value.status)) {
        if (pollTimer) clearInterval(pollTimer)
        pollTimer = null
      }
    } catch (e) {
      if (pollTimer) clearInterval(pollTimer)
      pollTimer = null
    }
  }, 2000)
}

async function cancelImport() {
  if (!currentJob.value) return
  if (!confirm(t('integrations.idoklad.cancel_confirm'))) return
  try {
    await integrationsApi.cancelJob(currentJob.value.id)
    toast.success(t('integrations.idoklad.cancel_requested'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function deleteImport() {
  if (!currentJob.value) return
  if (!confirm(t('integrations.idoklad.delete_confirm'))) return
  try {
    await integrationsApi.deleteJob(currentJob.value.id)
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
    currentJob.value = null
    toast.success(t('integrations.idoklad.deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

const isJobRunning = computed(() =>
  currentJob.value && ['queued', 'running'].includes(currentJob.value.status)
)

const progressPercent = computed(() => {
  if (!currentJob.value || !currentJob.value.total_items) return null
  return Math.round((currentJob.value.processed / currentJob.value.total_items) * 100)
})

// ── Fakturoid credentials state ───────────────────────────────────────
// Dva paralelní auth flow (issue #31):
//   - Legacy BasicAuth  : slug + email + api_key (starší účty, deprecated 2024)
//   - OAuth2 Client Cred: slug + client_id + client_secret (nové účty)
// User vyplní jeden z bloků (nebo oba — OAuth2 má pak prioritu při requestech).
const fakStatus = ref<FakturoidCredentialsStatus | null>(null)
const fakAuthMode = ref<'oauth2' | 'basic'>('oauth2')
const fakSlug = ref('')
const fakEmail = ref('')
const fakApiKey = ref('')
const fakClientId = ref('')
const fakClientSecret = ref('')
const fakSaving = ref(false)
const fakShowKey = ref(false)
const fakShowSecret = ref(false)
const fakTestMsg = ref<{ ok: boolean; text: string } | null>(null)

const fakStartParams = ref({
  include_clients: true,
  include_issued: true,
  include_received: true,
  incremental: false,
  download_attachments: false,
  dry_run: false,
})
const fakStarting = ref(false)

async function loadFakStatus() {
  try {
    fakStatus.value = await integrationsApi.getFakturoidCreds()
    if (fakStatus.value?.slug) fakSlug.value = fakStatus.value.slug
    if (fakStatus.value?.email) fakEmail.value = fakStatus.value.email
    if (fakStatus.value?.client_id) fakClientId.value = fakStatus.value.client_id
    // Auto-pick aktivní auth mode pro úpravu (OAuth2 priorita)
    if (fakStatus.value?.auth_mode) fakAuthMode.value = fakStatus.value.auth_mode
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function saveFakCreds() {
  if (!fakSlug.value) {
    toast.error(t('integrations.fakturoid.slug_required'))
    return
  }
  if (fakAuthMode.value === 'oauth2') {
    if (!fakClientId.value || !fakClientSecret.value) {
      toast.error(t('integrations.fakturoid.oauth_required'))
      return
    }
  } else {
    if (!fakEmail.value || !fakApiKey.value) {
      toast.error(t('integrations.fakturoid.basic_required'))
      return
    }
  }
  fakSaving.value = true
  fakTestMsg.value = null
  try {
    const payload = fakAuthMode.value === 'oauth2'
      ? { slug: fakSlug.value, client_id: fakClientId.value, client_secret: fakClientSecret.value }
      : { slug: fakSlug.value, email: fakEmail.value, api_key: fakApiKey.value }
    const r = await integrationsApi.setFakturoidCreds(payload)
    if (r.test_ok) {
      fakTestMsg.value = { ok: true, text: t('integrations.fakturoid.test_success', { name: r.account_name || '' }) }
      fakApiKey.value = ''
      fakClientSecret.value = ''
      await loadFakStatus()
    } else {
      fakTestMsg.value = { ok: false, text: r.test_error || 'Test connectivity selhal' }
    }
  } catch (e) {
    fakTestMsg.value = { ok: false, text: apiErrorMessage(e) }
  } finally {
    fakSaving.value = false
  }
}

async function deleteFakCreds() {
  if (!confirm(t('integrations.fakturoid.delete_confirm'))) return
  try {
    await integrationsApi.deleteFakturoidCreds()
    fakStatus.value = null
    fakSlug.value = ''
    fakEmail.value = ''
    fakApiKey.value = ''
    fakClientId.value = ''
    fakClientSecret.value = ''
    fakTestMsg.value = null
    toast.success(t('integrations.fakturoid.deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function startFakImport() {
  if (fakStarting.value) return
  fakStarting.value = true
  try {
    const r = await integrationsApi.startFakturoid(fakStartParams.value)
    toast.success(t('integrations.idoklad.started', { jobId: r.job_id }))
    await pollJob(r.job_id)
  } catch (e: any) {
    toast.error(apiErrorMessage(e))
  } finally {
    fakStarting.value = false
  }
}

// ── Anthropic AI ─────────────────────────────────────────────────────
const router = useRouter()
const aiStatus = ref<AnthropicCredentialsStatus | null>(null)
const aiApiKey = ref('')
const aiModel = ref('claude-haiku-4-5')
const aiShowKey = ref(false)
const aiSaving = ref(false)
const aiTestMsg = ref<{ ok: boolean; text: string } | null>(null)

const aiPdfFile = ref<File | null>(null)
const aiExtracting = ref(false)
const aiResult = ref<AiExtractResult | null>(null)
const aiPerRequestModel = ref('')  // empty = použít default

async function loadAiStatus() {
  try {
    aiStatus.value = await integrationsApi.getAnthropicCreds()
    if (aiStatus.value) aiModel.value = aiStatus.value.default_model
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function saveAiCreds() {
  // Při změně jen modelu (klíč už uložen) povol prázdné aiApiKey — backend zachová stávající klíč.
  const keyProvided = aiApiKey.value !== ''
  if (keyProvided && !aiApiKey.value.startsWith('sk-ant-')) {
    toast.error('API key musí začínat "sk-ant-"')
    return
  }
  if (!keyProvided && !aiStatus.value?.configured) {
    toast.error('API key musí začínat "sk-ant-"')
    return
  }
  aiSaving.value = true
  aiTestMsg.value = null
  try {
    const r = await integrationsApi.setAnthropicCreds(aiApiKey.value, aiModel.value)
    if (r.test_ok) {
      aiTestMsg.value = { ok: true, text: t('integrations.ai.test_success', { model: r.model || '' }) }
      aiApiKey.value = ''
      await loadAiStatus()
    } else {
      aiTestMsg.value = { ok: false, text: r.test_error || 'Test selhal' }
    }
  } catch (e) {
    aiTestMsg.value = { ok: false, text: apiErrorMessage(e) }
  } finally {
    aiSaving.value = false
  }
}

async function deleteAiCreds() {
  if (!confirm(t('integrations.ai.delete_confirm'))) return
  try {
    await integrationsApi.deleteAnthropicCreds()
    aiStatus.value = null
    aiApiKey.value = ''
    aiTestMsg.value = null
    toast.success(t('integrations.ai.deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

function onAiPdfPick(e: Event) {
  const input = e.target as HTMLInputElement
  aiPdfFile.value = input.files?.[0] ?? null
  aiResult.value = null
}

// Drag & drop handlers (browser default = open PDF in tab; preventDefault zastaví)
const aiDragOver = ref(false)

// Batch queue — vícero PDF naráz, processed serial (1 v čase) aby se nepřetížil
// Anthropic API rate limit. Status: pending → processing → ok | failed.
interface BatchItem {
  file: File
  status: 'pending' | 'processing' | 'ok' | 'failed'
  result: any
}
const aiBatchQueue = ref<BatchItem[]>([])
const aiBatchRunning = ref(false)

async function runAiBatch() {
  if (aiBatchRunning.value || aiBatchQueue.value.length === 0) return
  aiBatchRunning.value = true
  try {
    for (const item of aiBatchQueue.value) {
      if (item.status === 'ok') continue // skip already done (idempotent re-run)
      item.status = 'processing'
      try {
        const model = aiPerRequestModel.value || undefined
        const r = await integrationsApi.extractPdfAi(item.file, model)
        item.result = r
        item.status = r.ok ? 'ok' : 'failed'
      } catch (e: any) {
        item.result = e?.response?.data ?? { error: { message: apiErrorMessage(e) } }
        item.status = 'failed'
      }
    }
    await loadAiStatus()
    toast.success(t('integrations.ai.batch_done', { n: aiBatchQueue.value.filter(x => x.status === 'ok').length }))
  } finally {
    aiBatchRunning.value = false
  }
}

function clearBatch() {
  aiBatchQueue.value = []
}
function onAiDragEnter(e: DragEvent) { e.preventDefault(); aiDragOver.value = true }
function onAiDragOver(e: DragEvent) {
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'
}
function onAiDragLeave(e: DragEvent) {
  if (e.target === e.currentTarget) aiDragOver.value = false
}
function onAiDrop(e: DragEvent) {
  e.preventDefault()
  aiDragOver.value = false
  const files = Array.from(e.dataTransfer?.files ?? [])
  if (files.length === 0) return

  const pdfs = files.filter(f =>
    f.type === 'application/pdf' || f.type.startsWith('image/') ||
    /\.(pdf|jpe?g|png|webp|heic|heif|gif|bmp)$/i.test(f.name))
  if (pdfs.length === 0) {
    toast.error(t('integrations.ai.only_pdf'))
    return
  }

  if (pdfs.length === 1) {
    // Single drop — preserve původní single-file flow
    aiPdfFile.value = pdfs[0]
    aiResult.value = null
  } else {
    // Batch — naqueue všechny, user klikne "Spustit dávku"
    aiBatchQueue.value = pdfs.map(f => ({ file: f, status: 'pending' as const, result: null }))
    aiPdfFile.value = null
    aiResult.value = null
  }
}

async function runAiExtract() {
  if (!aiPdfFile.value || aiExtracting.value) return
  aiExtracting.value = true
  aiResult.value = null
  try {
    const model = aiPerRequestModel.value || undefined
    aiResult.value = await integrationsApi.extractPdfAi(aiPdfFile.value, model)
    if (aiResult.value.ok) {
      toast.success(t('integrations.ai.extract_success'))
      await loadAiStatus()  // refresh counter
    }
  } catch (e: any) {
    // Server vrátil 422 (extraction_failed) — extract ai_data ze response
    const respData = e?.response?.data
    if (respData?.error?.details) {
      aiResult.value = { ok: false, ...respData.error.details, error: respData.error.message, source: respData.error.details?.source ?? 'ai_failed' }
    } else {
      toast.error(apiErrorMessage(e))
    }
  } finally {
    aiExtracting.value = false
  }
}

function gotoInvoice(id: number) {
  router.push(`/purchase-invoices/${id}`)
}

onMounted(() => {
  loadIdokladStatus()
  loadFakStatus()
  loadAiStatus()
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})
</script>

<template>
  <div class="max-w-4xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('integrations.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('integrations.subtitle') }}</p>
    </div>

    <!-- Tabs: iDoklad / Fakturoid / AI -->
    <div class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button
        v-for="tt in (['idoklad', 'fakturoid', 'ai'] as const)" :key="tt"
        @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap inline-flex items-center gap-1.5"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'"
      >
        {{ t('integrations.' + tt + '.tab') }}
        <span v-if="tt === 'ai'" class="text-[10px] uppercase tracking-wide bg-warning-50 text-warning-600 border border-warning-500/40 px-1.5 py-0.5 rounded">
          BETA
        </span>
      </button>
    </div>

    <!-- ════ iDoklad tab ════ -->
    <div v-if="tab === 'idoklad'" class="space-y-4">
      <!-- Box: credentials -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-2">{{ t('integrations.idoklad.credentials_title') }}</h2>
        <p class="text-xs text-neutral-500 mb-4">{{ t('integrations.idoklad.credentials_hint') }}</p>

        <div class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700 mb-4" v-if="idokladStatus?.configured">
          <strong>✓ {{ t('integrations.idoklad.configured') }}</strong>
          <span v-if="idokladStatus.client_id" class="ml-2 font-mono text-xs">{{ idokladStatus.client_id.slice(0, 12) }}…</span>
        </div>

        <div class="space-y-3">
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.idoklad.client_id') }} *</label>
            <input v-model="idokladClientId" type="text" maxlength="256"
                   class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                   placeholder="01234567-89ab-cdef-0123-456789abcdef" />
          </div>
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.idoklad.client_secret') }} *</label>
            <div class="flex gap-2">
              <input v-model="idokladClientSecret" :type="showSecret ? 'text' : 'password'" maxlength="512"
                     class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                     :placeholder="idokladStatus?.configured ? t('integrations.idoklad.secret_placeholder_existing') : ''" />
              <button type="button" @click="showSecret = !showSecret"
                      class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
                {{ showSecret ? '🙈' : '👁' }}
              </button>
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ t('integrations.idoklad.secret_hint') }}</p>
          </div>
        </div>

        <div v-if="idokladTestMsg" class="mt-3 rounded-md px-3 py-2 text-sm"
             :class="idokladTestMsg.ok ? 'bg-success-50 text-success-600 border border-success-500/40' : 'bg-danger-50 text-danger-500 border border-danger-500/40'">
          {{ idokladTestMsg.text }}
        </div>

        <div class="flex items-center justify-between gap-2 mt-4 pt-4 border-t border-neutral-100">
          <button v-if="idokladStatus?.configured" type="button" @click="deleteIdokladCreds"
                  class="cursor-pointer h-10 px-4 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md">
            {{ t('integrations.idoklad.delete') }}
          </button>
          <span v-else></span>
          <button type="button" @click="saveIdokladCreds" :disabled="idokladSaving"
                  class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ idokladSaving ? '…' : t('integrations.idoklad.save_and_test') }}
          </button>
        </div>

        <details class="mt-4 text-xs text-neutral-500">
          <summary class="cursor-pointer hover:text-neutral-700">{{ t('integrations.idoklad.how_to_get') }}</summary>
          <ol class="mt-2 list-decimal list-inside space-y-1">
            <li>{{ t('integrations.idoklad.step1') }}</li>
            <li>{{ t('integrations.idoklad.step2') }}</li>
            <li>{{ t('integrations.idoklad.step3') }}</li>
            <li>{{ t('integrations.idoklad.step4') }}</li>
          </ol>
        </details>
      </div>

      <!-- Box: import controls -->
      <div v-if="idokladStatus?.configured" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-3">{{ t('integrations.idoklad.run_title') }}</h2>

        <div v-if="!isJobRunning" class="space-y-3">
          <p class="text-sm text-neutral-600">{{ t('integrations.idoklad.run_hint') }}</p>
          <div class="grid grid-cols-2 gap-3">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.include_clients" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.include_clients') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.include_issued" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.include_issued') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.include_received" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.include_received') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.incremental" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :title="t('integrations.idoklad.incremental_hint')">{{ t('integrations.idoklad.incremental') }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.download_attachments" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :title="t('integrations.idoklad.download_attachments_hint')">{{ t('integrations.idoklad.download_attachments') }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.dry_run" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.dry_run') }}
            </label>
          </div>
          <button type="button" @click="startImport" :disabled="starting"
                  class="cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0 0 10 9.87v4.263a1 1 0 0 0 1.555.832l3.197-2.132a1 1 0 0 0 0-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
            {{ starting ? '…' : t('integrations.idoklad.start_import') }}
          </button>
        </div>

        <div v-if="currentJob" class="space-y-3">
          <div class="flex items-center justify-between text-sm">
            <div>
              <span class="font-medium">Job #{{ currentJob.id }}</span>
              <span class="ml-2 px-2 py-0.5 text-xs rounded border"
                    :class="{
                      'bg-neutral-100 text-neutral-600 border-neutral-200': currentJob.status === 'queued',
                      'bg-primary-50 text-primary-700 border-primary-500/40': currentJob.status === 'running',
                      'bg-success-50 text-success-600 border-success-500/40': currentJob.status === 'completed',
                      'bg-danger-50 text-danger-500 border-danger-500/40': currentJob.status === 'failed',
                      'bg-warning-50 text-warning-600 border-warning-500/40': currentJob.status === 'cancelled',
                    }">
                {{ t('integrations.idoklad.status.' + currentJob.status) }}
              </span>
            </div>
            <div class="flex gap-2">
              <button v-if="isJobRunning" type="button" @click="cancelImport"
                      :disabled="currentJob.cancel_requested"
                      class="cursor-pointer h-8 px-3 text-xs border border-danger-500/50 text-danger-500 hover:bg-danger-50 disabled:opacity-50 rounded-md">
                {{ currentJob.cancel_requested ? t('integrations.idoklad.cancelling') : t('integrations.idoklad.cancel') }}
              </button>
              <button type="button" @click="deleteImport"
                      class="cursor-pointer h-8 px-3 text-xs border border-neutral-300 text-neutral-600 hover:bg-neutral-100 rounded-md">
                {{ t('integrations.idoklad.delete') }}
              </button>
            </div>
          </div>

          <div v-if="currentJob.current_step" class="text-sm text-neutral-600">{{ currentJob.current_step }}</div>

          <div v-if="progressPercent !== null" class="space-y-1">
            <div class="w-full h-2 bg-neutral-100 rounded-full overflow-hidden">
              <div class="h-full bg-primary-500 transition-all" :style="{ width: progressPercent + '%' }"></div>
            </div>
            <div class="text-xs text-neutral-500 font-mono">
              {{ currentJob.processed }} / {{ currentJob.total_items }} ({{ progressPercent }}%)
            </div>
          </div>

          <div class="grid grid-cols-3 gap-2 text-sm">
            <div class="bg-success-50 border border-success-500/40 rounded p-2">
              <div class="text-xs text-success-600">{{ t('integrations.idoklad.created') }}</div>
              <div class="font-mono font-semibold text-success-600">{{ currentJob.created_count }}</div>
            </div>
            <div class="bg-warning-50 border border-warning-500/40 rounded p-2">
              <div class="text-xs text-warning-600">{{ t('integrations.idoklad.skipped') }}</div>
              <div class="font-mono font-semibold text-warning-600">{{ currentJob.skipped_count }}</div>
            </div>
            <div class="bg-danger-50 border border-danger-500/40 rounded p-2">
              <div class="text-xs text-danger-500">{{ t('integrations.idoklad.failed') }}</div>
              <div class="font-mono font-semibold text-danger-500">{{ currentJob.failed_count }}</div>
            </div>
          </div>

          <div v-if="currentJob.last_error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
            {{ currentJob.last_error }}
          </div>

          <details v-if="currentJob.log_text" class="text-xs">
            <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">{{ t('integrations.idoklad.log') }}</summary>
            <pre class="mt-2 max-h-72 overflow-y-auto bg-neutral-900 text-neutral-100 p-3 rounded font-mono text-[11px] whitespace-pre-wrap">{{ currentJob.log_text }}</pre>
          </details>
        </div>
      </div>
    </div>

    <!-- ════ Fakturoid tab ════ -->
    <div v-else-if="tab === 'fakturoid'" class="space-y-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-2">{{ t('integrations.fakturoid.credentials_title') }}</h2>
        <p class="text-xs text-neutral-500 mb-4">{{ t('integrations.fakturoid.credentials_hint') }}</p>

        <div class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700 mb-4" v-if="fakStatus?.configured">
          <strong>✓ {{ t('integrations.idoklad.configured') }}</strong>
          <span v-if="fakStatus.slug" class="ml-2 font-mono text-xs">{{ fakStatus.slug }}</span>
          <span v-if="fakStatus.auth_mode === 'oauth2'" class="ml-2 text-xs">· OAuth2</span>
          <span v-else-if="fakStatus.auth_mode === 'basic'" class="ml-2 text-xs">· BasicAuth ({{ fakStatus.email }})</span>
        </div>

        <!-- OAuth2 vs BasicAuth auth mode picker -->
        <div class="rounded-md bg-neutral-50 border border-neutral-200 p-3 mb-4">
          <div class="flex gap-2 mb-2">
            <button type="button" @click="fakAuthMode = 'oauth2'"
                    class="cursor-pointer px-3 py-1.5 text-xs rounded-md border transition"
                    :class="fakAuthMode === 'oauth2'
                      ? 'bg-primary-600 text-white border-primary-600 font-medium'
                      : 'bg-surface text-neutral-700 border-neutral-300 hover:border-neutral-400'">
              {{ t('integrations.fakturoid.mode_oauth') }}
            </button>
            <button type="button" @click="fakAuthMode = 'basic'"
                    class="cursor-pointer px-3 py-1.5 text-xs rounded-md border transition"
                    :class="fakAuthMode === 'basic'
                      ? 'bg-primary-600 text-white border-primary-600 font-medium'
                      : 'bg-surface text-neutral-700 border-neutral-300 hover:border-neutral-400'">
              {{ t('integrations.fakturoid.mode_basic') }}
            </button>
          </div>
          <p class="text-xs text-neutral-600 leading-relaxed">
            {{ fakAuthMode === 'oauth2'
              ? t('integrations.fakturoid.mode_oauth_hint')
              : t('integrations.fakturoid.mode_basic_hint') }}
          </p>
        </div>

        <div class="space-y-3">
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.fakturoid.slug') }} *</label>
            <input v-model="fakSlug" type="text" maxlength="64"
                   class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                   placeholder="moje-firma" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('integrations.fakturoid.slug_hint') }}</p>
          </div>

          <!-- OAuth2 block -->
          <template v-if="fakAuthMode === 'oauth2'">
            <div>
              <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.fakturoid.client_id') }} *</label>
              <input v-model="fakClientId" type="text" maxlength="190"
                     class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                     placeholder="abcd1234efgh5678..." />
              <p class="text-xs text-neutral-500 mt-1">{{ t('integrations.fakturoid.client_id_hint') }}</p>
            </div>
            <div>
              <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.fakturoid.client_secret') }} *</label>
              <div class="flex gap-2">
                <input v-model="fakClientSecret" :type="fakShowSecret ? 'text' : 'password'" maxlength="512"
                       class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                       :placeholder="fakStatus?.has_oauth ? t('integrations.fakturoid.secret_placeholder_existing') : ''" />
                <button type="button" @click="fakShowSecret = !fakShowSecret"
                        class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
                  {{ fakShowSecret ? '🙈' : '👁' }}
                </button>
              </div>
              <p class="text-xs text-neutral-500 mt-1">{{ t('integrations.fakturoid.client_secret_hint') }}</p>
            </div>
          </template>

          <!-- Legacy BasicAuth block -->
          <template v-else>
            <div>
              <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.fakturoid.email') }} *</label>
              <input v-model="fakEmail" type="email" maxlength="255"
                     class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm"
                     placeholder="me@example.com" />
            </div>
            <div>
              <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.fakturoid.api_key') }} *</label>
              <div class="flex gap-2">
                <input v-model="fakApiKey" :type="fakShowKey ? 'text' : 'password'" maxlength="512"
                       class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                       :placeholder="fakStatus?.has_basic ? t('integrations.fakturoid.key_placeholder_existing') : ''" />
                <button type="button" @click="fakShowKey = !fakShowKey"
                        class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
                  {{ fakShowKey ? '🙈' : '👁' }}
                </button>
              </div>
              <p class="text-xs text-neutral-500 mt-1">{{ t('integrations.fakturoid.key_hint') }}</p>
            </div>
          </template>
        </div>

        <div v-if="fakTestMsg" class="mt-3 rounded-md px-3 py-2 text-sm"
             :class="fakTestMsg.ok ? 'bg-success-50 text-success-600 border border-success-500/40' : 'bg-danger-50 text-danger-500 border border-danger-500/40'">
          {{ fakTestMsg.text }}
        </div>

        <div class="flex items-center justify-between gap-2 mt-4 pt-4 border-t border-neutral-100">
          <button v-if="fakStatus?.configured" type="button" @click="deleteFakCreds"
                  class="cursor-pointer h-10 px-4 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md">
            {{ t('integrations.idoklad.delete') }}
          </button>
          <span v-else></span>
          <button type="button" @click="saveFakCreds" :disabled="fakSaving"
                  class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ fakSaving ? '…' : t('integrations.idoklad.save_and_test') }}
          </button>
        </div>
      </div>

      <!-- Box: import controls -->
      <div v-if="fakStatus?.configured" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-3">{{ t('integrations.idoklad.run_title') }}</h2>

        <div v-if="!isJobRunning" class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="fakStartParams.include_clients" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.fakturoid.include_subjects') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="fakStartParams.include_issued" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.include_issued') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="fakStartParams.include_received" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.fakturoid.include_expenses') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="fakStartParams.incremental" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :title="t('integrations.idoklad.incremental_hint')">{{ t('integrations.idoklad.incremental') }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="fakStartParams.download_attachments" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :title="t('integrations.idoklad.download_attachments_hint')">{{ t('integrations.idoklad.download_attachments') }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="fakStartParams.dry_run" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.dry_run') }}
            </label>
          </div>
          <button type="button" @click="startFakImport" :disabled="fakStarting"
                  class="cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center justify-center gap-2">
            {{ fakStarting ? '…' : t('integrations.idoklad.start_import') }}
          </button>
        </div>

        <div v-if="currentJob" class="space-y-3">
          <div class="flex items-center justify-between text-sm">
            <div>
              <span class="font-medium">Job #{{ currentJob.id }}</span>
              <span class="ml-2 px-2 py-0.5 text-xs rounded border"
                    :class="{
                      'bg-neutral-100 text-neutral-600 border-neutral-200': currentJob.status === 'queued',
                      'bg-primary-50 text-primary-700 border-primary-500/40': currentJob.status === 'running',
                      'bg-success-50 text-success-600 border-success-500/40': currentJob.status === 'completed',
                      'bg-danger-50 text-danger-500 border-danger-500/40': currentJob.status === 'failed',
                      'bg-warning-50 text-warning-600 border-warning-500/40': currentJob.status === 'cancelled',
                    }">
                {{ t('integrations.idoklad.status.' + currentJob.status) }}
              </span>
            </div>
            <div class="flex gap-2">
              <button v-if="isJobRunning" type="button" @click="cancelImport"
                      :disabled="currentJob.cancel_requested"
                      class="cursor-pointer h-8 px-3 text-xs border border-danger-500/50 text-danger-500 hover:bg-danger-50 disabled:opacity-50 rounded-md">
                {{ currentJob.cancel_requested ? t('integrations.idoklad.cancelling') : t('integrations.idoklad.cancel') }}
              </button>
              <button type="button" @click="deleteImport"
                      class="cursor-pointer h-8 px-3 text-xs border border-neutral-300 text-neutral-600 hover:bg-neutral-100 rounded-md">
                {{ t('integrations.idoklad.delete') }}
              </button>
            </div>
          </div>
          <div v-if="currentJob.current_step" class="text-sm text-neutral-600">{{ currentJob.current_step }}</div>
          <div class="grid grid-cols-3 gap-2 text-sm">
            <div class="bg-success-50 border border-success-500/40 rounded p-2">
              <div class="text-xs text-success-600">{{ t('integrations.idoklad.created') }}</div>
              <div class="font-mono font-semibold text-success-600">{{ currentJob.created_count }}</div>
            </div>
            <div class="bg-warning-50 border border-warning-500/40 rounded p-2">
              <div class="text-xs text-warning-600">{{ t('integrations.idoklad.skipped') }}</div>
              <div class="font-mono font-semibold text-warning-600">{{ currentJob.skipped_count }}</div>
            </div>
            <div class="bg-danger-50 border border-danger-500/40 rounded p-2">
              <div class="text-xs text-danger-500">{{ t('integrations.idoklad.failed') }}</div>
              <div class="font-mono font-semibold text-danger-500">{{ currentJob.failed_count }}</div>
            </div>
          </div>
          <div v-if="currentJob.last_error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
            {{ currentJob.last_error }}
          </div>
          <details v-if="currentJob.log_text" class="text-xs">
            <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">{{ t('integrations.idoklad.log') }}</summary>
            <pre class="mt-2 max-h-72 overflow-y-auto bg-neutral-900 text-neutral-100 p-3 rounded font-mono text-[11px] whitespace-pre-wrap">{{ currentJob.log_text }}</pre>
          </details>
        </div>
      </div>
    </div>

    <!-- ════ AI extrakce (Anthropic Claude) ════ -->
    <!-- #97: když je AI nakonfigurované, je opakovaná akce (extrakce) primární → flex
         `order` ji vysune nad konfiguraci, kterou sbalíme do collapsible „Nastavení AI". -->
    <div v-else-if="tab === 'ai'" class="flex flex-col gap-4">
      <!-- Konfigurace AI: onboarding (rozbalené) když není nakonfig.; jinak sbalené a pod plochou. -->
      <details :open="!aiStatus?.configured"
               :class="['group space-y-4', aiStatus?.configured ? 'order-2' : 'order-1']">
        <summary class="cursor-pointer select-none list-none [&::-webkit-details-marker]:hidden inline-flex items-center gap-2 px-3 py-2 rounded-md border border-neutral-200 bg-neutral-50 text-sm font-medium text-neutral-700 hover:bg-neutral-100">
          <svg class="w-4 h-4 text-neutral-400 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
          </svg>
          <span>{{ t('integrations.ai.settings_title') }}</span>
          <span v-if="aiStatus?.configured" class="ml-1 font-mono text-xs font-normal text-success-600">✓ {{ aiStatus.default_model }}</span>
        </summary>
      <!-- Privacy notice: PDF data se odesílá na servery Anthropic. -->
      <div class="rounded-md bg-warning-50 border border-warning-500/40 px-4 py-3 text-sm text-warning-700">
        <div class="flex gap-2 items-start">
          <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z"/>
          </svg>
          <div class="space-y-1">
            <strong>{{ t('integrations.ai.privacy_title') }}</strong>
            <p class="text-xs leading-relaxed">{{ t('integrations.ai.privacy_body') }}</p>
            <p class="text-xs">
              <a href="https://www.anthropic.com/legal/privacy" target="_blank" rel="noopener"
                 class="underline hover:text-warning-800">{{ t('integrations.ai.privacy_anthropic_link') }}</a>
            </p>
          </div>
        </div>
      </div>

      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-2">{{ t('integrations.ai.credentials_title') }}</h2>
        <p class="text-xs text-neutral-500 mb-4">{{ t('integrations.ai.credentials_hint') }}</p>

        <div class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700 mb-4" v-if="aiStatus?.configured">
          <strong>✓ {{ t('integrations.idoklad.configured') }}</strong>
          <span class="ml-2 font-mono text-xs">{{ aiStatus.default_model }}</span>
          <span class="ml-3 text-xs">{{ t('integrations.ai.extractions_count', { n: aiStatus.extractions_count }) }}</span>
        </div>

        <div class="space-y-3">
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.ai.api_key') }} *</label>
            <div class="flex gap-2">
              <input v-model="aiApiKey" :type="aiShowKey ? 'text' : 'password'" maxlength="256"
                     class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                     placeholder="sk-ant-…"
                     :readonly="aiStatus?.configured" />
              <button type="button" @click="aiShowKey = !aiShowKey"
                      class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
                {{ aiShowKey ? '🙈' : '👁' }}
              </button>
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ t('integrations.ai.api_key_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.ai.default_model') }}</label>
            <select v-model="aiModel" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option v-for="m in (aiStatus?.allowed_models || ['claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-opus-4-7'])" :key="m" :value="m">
                {{ m }} —
                {{ m.includes('haiku') ? t('integrations.ai.cost_haiku')
                 : m.includes('sonnet') ? t('integrations.ai.cost_sonnet')
                 : t('integrations.ai.cost_opus') }}
              </option>
            </select>
          </div>
        </div>

        <div v-if="aiTestMsg" class="mt-3 rounded-md px-3 py-2 text-sm"
             :class="aiTestMsg.ok ? 'bg-success-50 text-success-600 border border-success-500/40' : 'bg-danger-50 text-danger-500 border border-danger-500/40'">
          {{ aiTestMsg.text }}
        </div>

        <div class="flex items-center justify-between gap-2 mt-4 pt-4 border-t border-neutral-100">
          <button v-if="aiStatus?.configured" type="button" @click="deleteAiCreds"
                  class="cursor-pointer h-10 px-4 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md">
            {{ t('integrations.idoklad.delete') }}
          </button>
          <span v-else></span>
          <button type="button" @click="saveAiCreds" :disabled="aiSaving"
                  class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ aiSaving ? '…' : t('integrations.idoklad.save_and_test') }}
          </button>
        </div>
      </div>
      </details>

      <!-- AI PDF extract (primární akce — jen když je nakonfigurováno; #97 nad konfigurací) -->
      <div v-if="aiStatus?.configured" class="order-1 bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-2">{{ t('integrations.ai.extract_title') }}</h2>
        <p class="text-xs text-neutral-500 mb-4">{{ t('integrations.ai.extract_hint') }}</p>

        <div class="space-y-3">
          <label class="block border-2 border-dashed rounded-lg p-6 text-center cursor-pointer transition"
            :class="aiDragOver
              ? 'border-primary-500 bg-primary-50'
              : 'border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30'"
            @dragenter="onAiDragEnter" @dragover="onAiDragOver" @dragleave="onAiDragLeave" @drop="onAiDrop">
            <input type="file" accept="application/pdf,.pdf,image/*" @change="onAiPdfPick" class="hidden" />
            <svg class="w-8 h-8 mx-auto text-neutral-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9 5 5 0 0 1 9.9-1A5.5 5.5 0 0 1 18.5 16H17m-5-4v9m0-9l-3 3m3-3l3 3" />
            </svg>
            <div class="text-sm font-medium text-neutral-700">
              {{ aiPdfFile ? aiPdfFile.name : t('integrations.ai.drop_pdf') }}
            </div>
            <div v-if="aiPdfFile" class="text-xs text-neutral-500 mt-1">{{ Math.round(aiPdfFile.size / 1024) }} kB</div>
          </label>

          <div class="flex items-center gap-2">
            <label class="text-sm text-neutral-700">{{ t('integrations.ai.model_override') }}</label>
            <select v-model="aiPerRequestModel" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="">{{ t('integrations.ai.use_default') }} ({{ aiStatus.default_model }})</option>
              <option v-for="m in aiStatus.allowed_models" :key="m" :value="m">{{ m }}</option>
            </select>
          </div>

          <button type="button" @click="runAiExtract" :disabled="!aiPdfFile || aiExtracting"
                  class="cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
            {{ aiExtracting ? t('integrations.ai.extracting') : t('integrations.ai.run_extract') }}
          </button>
        </div>

        <!-- Batch queue (multi-file drop) — processed serial -->
        <div v-if="aiBatchQueue.length > 0" class="mt-4 pt-4 border-t border-neutral-100">
          <div class="flex items-center justify-between mb-2">
            <h3 class="text-sm font-medium text-neutral-700">
              {{ t('integrations.ai.batch_title', { n: aiBatchQueue.length }) }}
            </h3>
            <button type="button" @click="clearBatch" :disabled="aiBatchRunning"
                    class="cursor-pointer text-xs text-neutral-500 hover:text-danger-500">
              {{ t('common.cancel') }}
            </button>
          </div>
          <div class="space-y-1 max-h-64 overflow-y-auto border border-neutral-200 rounded-md p-2 text-xs">
            <div v-for="(item, idx) in aiBatchQueue" :key="idx" class="flex items-center justify-between gap-2 py-1">
              <span class="font-mono truncate flex-1">{{ item.file.name }}</span>
              <span class="text-neutral-400">{{ Math.round(item.file.size / 1024) }} kB</span>
              <span :class="[
                'px-2 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide',
                item.status === 'ok' ? 'bg-success-50 text-success-600' :
                item.status === 'failed' ? 'bg-danger-50 text-danger-500' :
                item.status === 'processing' ? 'bg-warning-50 text-warning-600' :
                'bg-neutral-100 text-neutral-500']">
                {{ item.status === 'processing' ? '…' : item.status }}
              </span>
              <RouterLink v-if="item.status === 'ok' && item.result?.purchase_invoice_id"
                :to="`/purchase-invoices/${item.result.purchase_invoice_id}`"
                class="text-primary-600 hover:underline text-[10px]">→</RouterLink>
            </div>
          </div>
          <button type="button" @click="runAiBatch" :disabled="aiBatchRunning"
                  class="mt-3 cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
            {{ aiBatchRunning ? t('integrations.ai.batch_running') : t('integrations.ai.batch_run') }}
          </button>
          <p class="text-xs text-neutral-500 mt-2">
            ℹ {{ t('integrations.ai.batch_serial_hint') }}
          </p>
        </div>

        <div v-if="aiResult" class="mt-4 pt-4 border-t border-neutral-100">
          <div v-if="aiResult.ok" class="rounded-md bg-success-50 border border-success-500/40 px-3 py-2 text-sm text-success-600">
            <strong>✓ {{ t('integrations.ai.extracted_via', { source: aiResult.source }) }}</strong>
            <button v-if="aiResult.purchase_invoice_id" type="button" @click="gotoInvoice(aiResult.purchase_invoice_id!)"
                    class="ml-3 cursor-pointer underline hover:text-success-700">
              {{ t('integrations.ai.go_to_invoice') }} #{{ aiResult.purchase_invoice_id }}
            </button>
            <div v-if="aiResult.usage" class="text-xs mt-1 font-mono">
              Tokens: in={{ aiResult.usage.input_tokens }}, out={{ aiResult.usage.output_tokens }}
              <span v-if="aiResult.model" class="ml-2">· {{ aiResult.model }}</span>
            </div>
          </div>
          <div v-else class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
            <strong>✗ {{ aiResult.error }}</strong>
            <div class="text-xs mt-1">Source: {{ aiResult.source }}</div>
          </div>

          <details v-if="aiResult.ai_data" class="mt-3 text-xs">
            <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">{{ t('integrations.ai.raw_data') }}</summary>
            <pre class="mt-2 max-h-72 overflow-y-auto bg-neutral-900 text-neutral-100 p-3 rounded font-mono text-[11px] whitespace-pre-wrap">{{ JSON.stringify(aiResult.ai_data, null, 2) }}</pre>
          </details>
        </div>
      </div>
    </div>

    <!-- Fallback (žádný tab nevyhovuje) -->
    <div v-else class="bg-surface border border-neutral-200 rounded-lg p-8 shadow-sm text-center text-neutral-500 text-sm">
      —
    </div>
  </div>
</template>

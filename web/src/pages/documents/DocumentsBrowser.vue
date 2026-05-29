<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { onUnmounted } from 'vue'
import {
  documentsApi, type DocItem, type DocFolder, type BreadcrumbItem, type DocJob, type TagInfo,
} from '@/api/documents'
import { docTypeBadge, formatBytes } from '@/components/documents/docFormat'
import TagInput from '@/components/documents/TagInput.vue'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const folderId = computed<number | null>(() => {
  const f = route.query.folder
  return f != null && /^\d+$/.test(String(f)) ? Number(f) : null
})

const breadcrumb = ref<BreadcrumbItem[]>([])
const folders = ref<DocFolder[]>([])
const documents = ref<DocItem[]>([])
const maxFileBytes = ref(50 * 1024 * 1024)
const phpMaxUpload = ref(0) // efektivní PHP per-request limit (0 = neznámý/neomezený)
const loading = ref(false)

const view = ref<'grid' | 'list'>((localStorage.getItem('documents.view') as 'grid' | 'list') || 'grid')
watch(view, v => localStorage.setItem('documents.view', v))

// search
const query = ref('')
const searchResults = ref<DocItem[]>([])
const searching = ref(false)
let searchDebounce: ReturnType<typeof setTimeout> | null = null
const searchActive = computed(() => query.value.trim().length >= 2)

// trash — stav v URL (?trash=1), aby klik na „Dokumenty" v menu z koše vystoupil
const trashMode = computed(() => route.query.trash === '1')
const trashDocs = ref<DocItem[]>([])
const trashFolders = ref<DocFolder[]>([])

// selection (dokumenty i složky současně)
const selected = ref<Set<number>>(new Set())
const selectedFolders = ref<Set<number>>(new Set())
const selDocCount = computed(() => selected.value.size)
const selFolderCount = computed(() => selectedFolders.value.size)
const selCount = computed(() => selDocCount.value + selFolderCount.value)

// Mobilní odkrytí akcí složky: bez hoveru jsou ikony přejmenovat/smazat skryté.
// První ťuk je jen odkryje (canHover=false → pointer-events vypnuté), teprve další ťuk akci spustí.
const canHover = ref(true)
const revealedFolderId = ref<number | null>(null)
function revealFolderActions(f: DocFolder) { revealedFolderId.value = f.id }

// upload
const uploading = ref(false)
const uploadPct = ref(0)
const zipMode = ref<'explode' | 'keep'>('explode')
const dragOver = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const folderInput = ref<HTMLInputElement | null>(null)

// background jobs (ZIP import/export)
const jobs = ref<DocJob[]>([])
let jobTimer: ReturnType<typeof setInterval> | null = null
const anyJobActive = computed(() => jobs.value.some(j => j.status === 'queued' || j.status === 'running'))

// move modal
const moveOpen = ref(false)
const allFolders = ref<DocFolder[]>([])
const moveTargetIds = ref<number[]>([])
const moveTargetFolders = ref<number[]>([])

// řazení (client-side; default dle názvu vzestupně)
const sortKey = ref<'name' | 'size' | 'created'>('name')
const sortDir = ref<'asc' | 'desc'>('asc')
function setSort(k: 'name' | 'size' | 'created') {
  if (sortKey.value === k) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  else { sortKey.value = k; sortDir.value = k === 'created' ? 'desc' : 'asc' }
}
const sortedDocuments = computed(() => {
  const dir = sortDir.value === 'asc' ? 1 : -1
  return [...documents.value].sort((a, b) => {
    let r = 0
    if (sortKey.value === 'size') r = a.size_bytes - b.size_bytes
    else if (sortKey.value === 'created') r = a.created_at.localeCompare(b.created_at)
    else r = a.title.localeCompare(b.title, 'cs', { numeric: true })
    return r * dir
  })
})

// filtr tagem (v URL ?tag=) + dostupné tagy pro select
const tagFilter = computed(() => typeof route.query.tag === 'string' ? route.query.tag : '')
const availableTags = ref<TagInfo[]>([])
function setTagFilter(tag: string) {
  query.value = ''
  router.push({ name: 'documents', query: tag ? { tag } : {} })
}
async function loadTags() {
  try { availableTags.value = await documentsApi.tags() } catch { /* ignore */ }
}

// hromadné tagování (modal s našeptáváním)
const tagModalOpen = ref(false)
const bulkTags = ref<string[]>([])

async function loadListing() {
  loading.value = true
  selected.value = new Set()
  selectedFolders.value = new Set()
  revealedFolderId.value = null
  try {
    const r = await documentsApi.list(folderId.value, { tag: tagFilter.value || undefined })
    breadcrumb.value = r.breadcrumb
    folders.value = r.folders
    documents.value = r.documents
    maxFileBytes.value = r.max_file_bytes
    phpMaxUpload.value = r.php_max_upload_bytes ?? 0
  } finally {
    loading.value = false
  }
}

async function loadTrash() {
  const r = await documentsApi.trash()
  trashDocs.value = r.documents
  trashFolders.value = r.folders
}

watch(folderId, () => { if (!trashMode.value) loadListing() })
watch(tagFilter, () => { if (!trashMode.value) loadListing() })
watch(trashMode, (v) => { v ? loadTrash() : loadListing() })
watch(query, (q) => {
  if (searchDebounce) clearTimeout(searchDebounce)
  if (q.trim().length < 2) { searchResults.value = []; return }
  searchDebounce = setTimeout(async () => {
    searching.value = true
    try { searchResults.value = await documentsApi.search(q.trim()) }
    finally { searching.value = false }
  }, 250)
})

function openFolder(id: number | null) {
  query.value = ''
  router.push({ name: 'documents', query: id ? { folder: String(id) } : {} })
}
function openDoc(d: DocItem) {
  router.push({ name: 'document-detail', params: { id: d.id } })
}

// ───── selection ─────
function toggleSel(id: number) {
  const s = new Set(selected.value)
  s.has(id) ? s.delete(id) : s.add(id)
  selected.value = s
}
function toggleFolderSel(id: number) {
  const s = new Set(selectedFolders.value)
  s.has(id) ? s.delete(id) : s.add(id)
  selectedFolders.value = s
}
function selectAll() {
  selected.value = new Set(documents.value.map(d => d.id))
}
function clearSel() { selected.value = new Set(); selectedFolders.value = new Set() }

// ───── folders ─────
async function newFolder() {
  const name = prompt(t('documents.folder_name'))
  if (!name || !name.trim()) return
  try {
    await documentsApi.createFolder(name.trim(), folderId.value)
    await loadListing()
  } catch { toast.error(t('documents.upload_failed')) }
}
async function renameFolder(f: DocFolder) {
  const name = prompt(t('documents.rename'), f.name)
  if (!name || !name.trim() || name === f.name) return
  await documentsApi.renameFolder(f.id, name.trim())
  await loadListing()
}
async function deleteFolder(f: DocFolder) {
  if (!confirm(t('documents.delete_confirm'))) return
  await documentsApi.deleteFolder(f.id)
  await loadListing()
}

// ───── bulk ─────
async function bulkDelete() {
  if (!confirm(t('documents.delete_confirm'))) return
  await documentsApi.bulk('delete', [...selected.value], { folder_ids: [...selectedFolders.value] })
  await loadListing()
  toast.success(t('documents.saved'))
}
function bulkTag() {
  bulkTags.value = []
  tagModalOpen.value = true
}
async function applyBulkTags() {
  if (bulkTags.value.length === 0) { tagModalOpen.value = false; return }
  await documentsApi.bulk('tag', [...selected.value], { tags: bulkTags.value })
  tagModalOpen.value = false
  bulkTags.value = []
  toast.success(t('documents.saved'))
  clearSel()
  loadTags()
}
async function bulkDownload() {
  try {
    await documentsApi.exportZip([...selected.value], [...selectedFolders.value])
    toast.success(t('documents.export_started'))
    clearSel()
    await loadJobs()
  } catch {
    toast.error(t('documents.upload_failed'))
  }
}
async function openMove() {
  moveTargetIds.value = [...selected.value]
  moveTargetFolders.value = [...selectedFolders.value]
  try {
    allFolders.value = await documentsApi.allFolders()
  } catch {
    allFolders.value = []
  }
  moveOpen.value = true
}
async function doMove(targetFolderId: number | null) {
  await documentsApi.bulk('move', moveTargetIds.value, { folder_id: targetFolderId, folder_ids: moveTargetFolders.value })
  moveOpen.value = false
  await loadListing()
  toast.success(t('documents.saved'))
}

// Cíl přesunu jako strom (z ploché allFolders přes parent_id) + zákaz neplatných cílů.
const moveTreeFlat = computed<{ id: number; name: string; depth: number }[]>(() => {
  const childrenOf = new Map<number | null, DocFolder[]>()
  for (const f of allFolders.value) {
    const key = f.parent_id ?? null
    const arr = childrenOf.get(key) ?? []
    arr.push(f); childrenOf.set(key, arr)
  }
  for (const arr of childrenOf.values()) arr.sort((a, b) => a.name.localeCompare(b.name, 'cs', { numeric: true }))
  const out: { id: number; name: string; depth: number }[] = []
  const seen = new Set<number>()
  const walk = (parent: number | null, depth: number) => {
    for (const f of childrenOf.get(parent) ?? []) {
      if (seen.has(f.id)) continue
      seen.add(f.id)
      out.push({ id: f.id, name: f.name, depth })
      walk(f.id, depth + 1)
    }
  }
  walk(null, 0)
  // Sirotci (rodič mimo aktivní strom) → na kořen, ať z nabídky nezmizí.
  for (const f of allFolders.value) if (!seen.has(f.id)) { out.push({ id: f.id, name: f.name, depth: 0 }); seen.add(f.id) }
  return out
})
// Zakázané cíle při přesunu složek: vybrané složky + jejich potomci (jinak cyklus).
const forbiddenMoveTargets = computed<Set<number>>(() => {
  const forbidden = new Set<number>()
  if (moveTargetFolders.value.length === 0) return forbidden
  const childrenOf = new Map<number, number[]>()
  for (const f of allFolders.value) if (f.parent_id != null) {
    const arr = childrenOf.get(f.parent_id) ?? []; arr.push(f.id); childrenOf.set(f.parent_id, arr)
  }
  const addSubtree = (id: number) => { forbidden.add(id); for (const c of childrenOf.get(id) ?? []) addSubtree(c) }
  for (const id of moveTargetFolders.value) addSubtree(id)
  return forbidden
})

// ───── trash ─────
function toggleTrash() {
  query.value = ''
  router.push({ name: 'documents', query: trashMode.value ? {} : { trash: '1' } })
}
async function restoreDoc(d: DocItem) {
  await documentsApi.restore(d.id)
  await loadTrash()
}
async function restoreFolder(f: DocFolder) {
  await documentsApi.restoreFolder(f.id)
  await loadTrash()
}
async function emptyTrash() {
  if (!confirm(t('documents.empty_trash_confirm'))) return
  const r = await documentsApi.emptyTrash()
  await loadTrash()
  toast.success(t('documents.upload_done', { n: r.deleted }))
}

// ───── upload ─────
async function loadJobs() {
  try {
    const prev = jobs.value
    jobs.value = await documentsApi.jobs()
    for (const j of jobs.value) {
      const old = prev.find(p => p.id === j.id)
      if (old && (old.status === 'queued' || old.status === 'running') && old.status !== j.status) {
        if (j.status === 'completed') {
          toast.success(t('documents.job_done'))
          // Po importu (ZIP/složka/velký soubor) obnov výpis + tagy; export jen ke stažení.
          if (j.source !== 'document_zip_export') { loadListing(); loadTags() }
        } else if (j.status === 'failed') {
          toast.error(j.last_error || t('documents.upload_failed'))
        }
      }
    }
  } catch { /* keep */ }
  syncPolling()
}
function syncPolling() {
  if (anyJobActive.value && !jobTimer) jobTimer = setInterval(loadJobs, 2000)
  else if (!anyJobActive.value && jobTimer) { clearInterval(jobTimer); jobTimer = null }
}
async function cancelJob(j: DocJob) {
  await documentsApi.cancelJob(j.id)
  loadJobs()
}
async function dismissJob(j: DocJob) {
  await documentsApi.deleteJob(j.id)
  jobs.value = jobs.value.filter(x => x.id !== j.id)
}

// Bezpečný limit pro jeden HTTP request (90 % z PHP post_max_size, fallback 24 MB).
// Větší soubory/dávky posíláme chunkovaně, takže PHP limit neobejdeme jen tušíme.
function requestLimit(): number {
  return phpMaxUpload.value > 0 ? Math.floor(phpMaxUpload.value * 0.9) : 24 * 1024 * 1024
}
/** Nahraje jeden velký soubor po bytech do staging jobu (chunk ≤ PHP limit). */
async function uploadBlobInChunks(jobId: number, file: File) {
  const chunk = Math.max(256 * 1024, Math.min(8 * 1024 * 1024, requestLimit()))
  for (let off = 0; off < file.size || off === 0; off += chunk) {
    await documentsApi.uploadChunkBytes(jobId, file.slice(off, off + chunk))
    uploadPct.value = Math.min(100, Math.round(((off + chunk) / Math.max(1, file.size)) * 100))
    if (file.size === 0) break
  }
}

async function chunkedZipImport(file: File) {
  const { job_id } = await documentsApi.uploadStart('zip-explode', folderId.value)
  await uploadBlobInChunks(job_id, file)
  await documentsApi.uploadFinish(job_id)
  toast.success(t('documents.zip_job_started', { name: file.name }))
}

async function chunkedSingle(file: File) {
  const { job_id } = await documentsApi.uploadStart('single', folderId.value, file.name)
  await uploadBlobInChunks(job_id, file)
  await documentsApi.uploadFinish(job_id)
}

async function chunkedFolderImport(files: { file: File; path: string }[]) {
  const { job_id } = await documentsApi.uploadStart('folder', folderId.value)
  const limit = requestLimit()
  let batch: File[] = [], paths: string[] = [], size = 0, sent = 0
  const flush = async () => {
    if (!batch.length) return
    await documentsApi.uploadChunkFiles(job_id, batch, paths)
    sent += batch.length
    uploadPct.value = Math.min(100, Math.round((sent / files.length) * 100))
    batch = []; paths = []; size = 0
  }
  for (const f of files) {
    if (batch.length >= 50 || (size + f.file.size > limit && batch.length)) await flush()
    batch.push(f.file); paths.push(f.path); size += f.file.size
  }
  await flush()
  await documentsApi.uploadFinish(job_id)
}

async function uploadFiles(files: { file: File; path: string }[]) {
  if (files.length === 0) return
  uploading.value = true
  uploadPct.value = 0
  let usedJob = false
  try {
    const limit = requestLimit()

    // 1) ZIP v režimu „rozbalit" → chunkovaný zip job (zvládne i velký archiv).
    if (zipMode.value === 'explode') {
      const isZip = (n: string) => n.toLowerCase().endsWith('.zip')
      const zips = files.filter(f => isZip(f.file.name))
      files = files.filter(f => !isZip(f.file.name))
      for (const z of zips) { await chunkedZipImport(z.file); usedJob = true }
      if (files.length === 0) { if (usedJob) await loadJobs(); return }
    }

    // 2) Velké jednotlivé soubory (> bezpečný request limit) → chunkovaný single job.
    const big = files.filter(f => f.file.size > limit)
    files = files.filter(f => f.file.size <= limit)
    for (const b of big) { await chunkedSingle(b.file); usedJob = true }

    // 3) Složka (relativní cesty) / hodně souborů / velký součet → chunkovaný folder job.
    const total = files.reduce((s, f) => s + f.file.size, 0)
    const isFolder = files.some(f => f.path !== '')
    if (files.length && (isFolder || files.length > 20 || total > limit)) {
      await chunkedFolderImport(files)
      files = []
      usedJob = true
    }

    // 4) Zbytek (málo malých souborů) → přímý synchronní upload (rychlý, náhledy hned).
    if (files.length) {
      const r = await documentsApi.upload(
        files.map(f => f.file),
        { folderId: folderId.value, zipMode: zipMode.value, relpaths: files.map(f => f.path) },
        pct => { uploadPct.value = pct },
      )
      toast.success(t('documents.upload_done', { n: r.created }))
      await loadListing()
    }
    if (usedJob) { toast.success(t('documents.upload_job_started')); await loadJobs() }
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { error?: { message?: string } } } })?.response?.data?.error?.message
    toast.error(msg || t('documents.upload_failed'))
  } finally {
    uploading.value = false
  }
}

function onFilePick(e: Event) {
  const input = e.target as HTMLInputElement
  const list = input.files ? Array.from(input.files) : []
  uploadFiles(list.map(f => ({ file: f, path: '' })))
  input.value = ''
}
function onFolderPick(e: Event) {
  const input = e.target as HTMLInputElement
  const list = input.files ? Array.from(input.files) : []
  // webkitRelativePath = "Folder/sub/file.pdf" → path = dir portion
  uploadFiles(list.map(f => {
    const rel = (f as File & { webkitRelativePath?: string }).webkitRelativePath || ''
    const dir = rel.includes('/') ? rel.slice(0, rel.lastIndexOf('/')) : ''
    return { file: f, path: dir }
  }))
  input.value = ''
}

// drag & drop (vč. celých složek přes webkitGetAsEntry)
async function onDrop(e: DragEvent) {
  dragOver.value = false
  if (!auth.canWrite || !e.dataTransfer) return
  const collected = await collectFromDataTransfer(e.dataTransfer)
  await uploadFiles(collected)
}
async function collectFromDataTransfer(dt: DataTransfer): Promise<{ file: File; path: string }[]> {
  const items = dt.items ? Array.from(dt.items) : []
  const entries = items.map(it => (it as DataTransferItem & { webkitGetAsEntry?: () => any }).webkitGetAsEntry?.()).filter(Boolean)
  if (entries.length === 0) {
    return Array.from(dt.files).map(f => ({ file: f, path: '' }))
  }
  const out: { file: File; path: string }[] = []
  for (const entry of entries) await walkEntry(entry, '', out)
  return out
}
function walkEntry(entry: any, parentPath: string, out: { file: File; path: string }[]): Promise<void> {
  return new Promise((resolve) => {
    if (entry.isFile) {
      entry.file((f: File) => { out.push({ file: f, path: parentPath }); resolve() }, () => resolve())
    } else if (entry.isDirectory) {
      const dirPath = parentPath ? parentPath + '/' + entry.name : entry.name
      const reader = entry.createReader()
      const all: any[] = []
      const readBatch = () => reader.readEntries(async (batch: any[]) => {
        if (!batch.length) {
          for (const child of all) await walkEntry(child, dirPath, out)
          resolve()
        } else {
          all.push(...batch)
          readBatch()
        }
      }, () => resolve())
      readBatch()
    } else resolve()
  })
}

onMounted(() => {
  canHover.value = window.matchMedia('(hover: hover)').matches
  trashMode.value ? loadTrash() : loadListing(); loadJobs(); loadTags()
})
onUnmounted(() => { if (jobTimer) clearInterval(jobTimer) })
</script>

<template>
  <div
    class="min-h-[calc(100vh-7rem)]"
    @dragover.prevent="dragOver = true"
    @dragleave.prevent="dragOver = false"
    @drop.prevent="onDrop"
  >
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <h1 class="text-xl font-semibold text-neutral-800">{{ t('documents.title') }}</h1>
      <div class="flex items-center gap-2 flex-wrap">
        <button
          type="button"
          :class="['cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 text-sm font-medium rounded-md border', trashMode ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50']"
          @click="toggleTrash"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3" /></svg>
          {{ t('documents.trash') }}
        </button>
        <template v-if="auth.canWrite && !trashMode">
          <button type="button" class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 text-sm font-medium rounded-md border border-neutral-300 text-neutral-600 hover:bg-neutral-50" @click="newFolder">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM12 11v4m-2-2h4" /></svg>
            {{ t('documents.new_folder') }}
          </button>
          <button type="button" class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 text-sm font-medium rounded-md border border-neutral-300 text-neutral-600 hover:bg-neutral-50" :title="t('documents.upload_folder_hint')" @click="folderInput?.click()">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM12 16V8m-3 3l3-3 3 3" /></svg>
            {{ t('documents.upload_folder') }}
          </button>
          <button type="button" class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 text-sm font-medium rounded-md bg-primary-600 hover:bg-primary-700 text-white" @click="fileInput?.click()">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M12 4v12m-4-8l4-4 4 4" /></svg>
            {{ t('documents.upload') }}
          </button>
        </template>
      </div>
      <input ref="fileInput" type="file" multiple class="hidden" @change="onFilePick" />
      <input ref="folderInput" type="file" webkitdirectory multiple class="hidden" @change="onFolderPick" />
    </div>

    <!-- Toolbar: search + view -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3">
      <div class="flex flex-wrap items-center gap-2">
        <div class="relative flex-1 min-w-48">
          <input
            v-model="query"
            type="text"
            class="w-full h-9 pl-9 pr-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
            :placeholder="t('documents.search_placeholder')"
          />
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14z" /></svg>
        </div>
        <select
          v-if="availableTags.length"
          :value="tagFilter"
          class="cursor-pointer h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm max-w-44"
          @change="setTagFilter(($event.target as HTMLSelectElement).value)"
        >
          <option value="">{{ t('documents.all_tags') }}</option>
          <option v-for="tg in availableTags" :key="tg.id" :value="tg.name">#{{ tg.name }} ({{ tg.usage_count }})</option>
        </select>
        <div class="flex rounded-md border border-neutral-300 overflow-hidden h-9">
          <button type="button" :class="['cursor-pointer px-2.5', view === 'grid' ? 'bg-neutral-100 text-neutral-800' : 'text-neutral-400 hover:bg-neutral-50']" @click="view = 'grid'" :title="t('documents.view_grid')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5h6v6H4zM14 5h6v6h-6zM4 15h6v6H4zM14 15h6v6h-6z" /></svg>
          </button>
          <button type="button" :class="['cursor-pointer px-2.5', view === 'list' ? 'bg-neutral-100 text-neutral-800' : 'text-neutral-400 hover:bg-neutral-50']" @click="view = 'list'" :title="t('documents.view_list')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- ZIP mode -->
    <div v-if="auth.canWrite && !trashMode" class="flex items-center gap-3 mb-3 text-xs text-neutral-500">
      <span>{{ t('documents.zip_mode') }}:</span>
      <label class="inline-flex items-center gap-1 cursor-pointer"><input type="radio" value="explode" v-model="zipMode" /> {{ t('documents.zip_explode') }}</label>
      <label class="inline-flex items-center gap-1 cursor-pointer"><input type="radio" value="keep" v-model="zipMode" /> {{ t('documents.zip_keep') }}</label>
      <span class="text-neutral-400" :title="phpMaxUpload ? t('documents.php_limit_note', { mb: Math.round(phpMaxUpload / 1024 / 1024) }) : ''">· {{ t('documents.limit_info', { mb: Math.round(maxFileBytes / 1024 / 1024) }) }}</span>
      <span class="ml-auto inline-flex items-center gap-1 text-neutral-400">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9A5 5 0 0 1 15.9 6.1 4.5 4.5 0 0 1 17 15M12 12v8m-3-3l3 3 3-3" /></svg>
        {{ t('documents.drop_here') }}
      </span>
    </div>

    <!-- Upload progress -->
    <div v-if="uploading" class="mb-3">
      <div class="h-1.5 bg-neutral-100 rounded-full overflow-hidden">
        <div class="h-full bg-primary-500 transition-all" :style="{ width: uploadPct + '%' }"></div>
      </div>
      <p class="text-xs text-neutral-400 mt-1">{{ t('documents.uploading') }} {{ uploadPct }}%</p>
    </div>

    <!-- Background jobs (ZIP import / export) -->
    <div v-if="jobs.length" class="mb-4 space-y-2">
      <div v-for="j in jobs" :key="j.id" class="bg-surface border border-neutral-200 rounded-lg p-3">
        <div class="flex items-center gap-2 text-sm flex-wrap">
          <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path v-if="j.source === 'document_zip_import'" stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M12 4v12m-4-8l4-4 4 4" />
            <path v-else stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M7 10l5 5 5-5M12 15V3" />
          </svg>
          <span class="font-medium text-neutral-700">{{ j.source === 'document_zip_import' ? t('documents.job_import') : t('documents.job_export') }}</span>
          <span :class="['px-1.5 py-0.5 rounded text-[10px] font-medium uppercase', {
            'bg-primary-50 text-primary-700': j.status === 'queued' || j.status === 'running',
            'bg-success-50 text-success-600': j.status === 'completed',
            'bg-danger-50 text-danger-500': j.status === 'failed',
            'bg-neutral-100 text-neutral-500': j.status === 'cancelled',
          }]">{{ t('documents.job_status_' + j.status) }}</span>
          <span v-if="j.current_step && (j.status === 'running' || j.status === 'queued')" class="text-xs text-neutral-500">{{ j.current_step }}</span>
          <span v-if="j.total_items" class="text-xs text-neutral-400 tabular-nums">{{ j.processed }}/{{ j.total_items }}</span>
          <span v-if="j.last_error" class="text-xs text-danger-500 truncate max-w-xs">{{ j.last_error }}</span>
          <div class="flex-1"></div>
          <a v-if="j.status === 'completed' && j.source === 'document_zip_export'" :href="documentsApi.jobDownloadUrl(j.id)" class="cursor-pointer inline-flex items-center gap-1 h-7 px-2.5 text-xs font-medium rounded-md border border-primary-300 text-primary-700 hover:bg-primary-50">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M7 10l5 5 5-5M12 15V3" /></svg>
            {{ t('documents.download') }}
          </a>
          <button v-if="j.status === 'queued' || j.status === 'running'" type="button" class="cursor-pointer h-7 px-2.5 text-xs rounded-md border border-neutral-300 text-neutral-600 hover:bg-neutral-50" @click="cancelJob(j)">{{ t('documents.cancel_job') }}</button>
          <button v-else type="button" class="cursor-pointer inline-flex items-center justify-center h-7 w-7 rounded-md text-neutral-400 hover:bg-neutral-100" @click="dismissJob(j)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>
        <div v-if="(j.status === 'running' || j.status === 'queued') && j.total_items" class="mt-2 h-1.5 bg-neutral-100 rounded-full overflow-hidden">
          <div class="h-full bg-primary-500 transition-all" :style="{ width: Math.round((j.processed / Math.max(1, j.total_items)) * 100) + '%' }"></div>
        </div>
      </div>
    </div>

    <!-- ═══════════ TRASH ═══════════ -->
    <div v-if="trashMode">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-medium text-neutral-600">{{ t('documents.trash') }}</h2>
        <button v-if="auth.canWrite && (trashDocs.length || trashFolders.length)" type="button" class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 text-sm font-medium rounded-md border border-danger-300 text-danger-500 hover:bg-danger-50" @click="emptyTrash">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3" /></svg>
          {{ t('documents.trash_empty') }}
        </button>
      </div>
      <p v-if="!trashDocs.length && !trashFolders.length" class="text-sm text-neutral-400">{{ t('documents.trash_is_empty') }}</p>
      <ul class="space-y-1">
        <li v-for="f in trashFolders" :key="'tf' + f.id" class="flex items-center gap-3 px-3 py-2 bg-surface border border-neutral-200 rounded-lg">
          <svg class="w-5 h-5 text-warning-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /></svg>
          <span class="flex-1 text-sm text-neutral-700">{{ f.name }}</span>
          <button v-if="auth.canWrite" type="button" class="cursor-pointer inline-flex items-center gap-1 h-8 px-2.5 text-sm rounded-md border border-neutral-300 text-neutral-600 hover:bg-neutral-50" @click="restoreFolder(f)">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M4 9a8 8 0 1 1-2 5" /></svg>
            {{ t('documents.restore') }}
          </button>
        </li>
        <li v-for="d in trashDocs" :key="'td' + d.id" class="flex items-center gap-3 px-3 py-2 bg-surface border border-neutral-200 rounded-lg">
          <span :class="['shrink-0 px-1.5 py-0.5 rounded text-[10px] font-semibold', docTypeBadge(d.doc_type).class]">{{ docTypeBadge(d.doc_type).label }}</span>
          <span class="flex-1 text-sm text-neutral-700 truncate">{{ d.title }}</span>
          <button v-if="auth.canWrite" type="button" class="cursor-pointer inline-flex items-center gap-1 h-8 px-2.5 text-sm rounded-md border border-neutral-300 text-neutral-600 hover:bg-neutral-50" @click="restoreDoc(d)">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M4 9a8 8 0 1 1-2 5" /></svg>
            {{ t('documents.restore') }}
          </button>
        </li>
      </ul>
    </div>

    <!-- ═══════════ SEARCH RESULTS ═══════════ -->
    <div v-else-if="searchActive">
      <p v-if="!searching && searchResults.length === 0" class="text-sm text-neutral-400">{{ t('documents.search_no_results') }}</p>
      <ul class="space-y-1">
        <li v-for="d in searchResults" :key="d.id" class="flex items-center gap-3 px-3 py-2 bg-surface border border-neutral-200 rounded-lg hover:border-primary-300 cursor-pointer" @click="openDoc(d)">
          <span :class="['shrink-0 px-1.5 py-0.5 rounded text-[10px] font-semibold', docTypeBadge(d.doc_type).class]">{{ docTypeBadge(d.doc_type).label }}</span>
          <span class="flex-1 min-w-0"><span class="block text-sm text-neutral-800 truncate">{{ d.title }}</span><span class="block text-xs text-neutral-400">{{ formatBytes(d.size_bytes) }} · {{ d.created_at.slice(0, 10) }}</span></span>
        </li>
      </ul>
    </div>

    <!-- ═══════════ BROWSER ═══════════ -->
    <div v-else>
      <!-- tag filter banner -->
      <div v-if="tagFilter" class="flex items-center gap-2 mb-3 text-sm">
        <span class="text-neutral-500">{{ t('documents.filtered_by_tag') }}</span>
        <span class="px-2 py-0.5 rounded-full bg-primary-50 text-primary-700">#{{ tagFilter }}</span>
        <button type="button" class="cursor-pointer inline-flex items-center gap-1 text-neutral-400 hover:text-neutral-700 text-xs" @click="setTagFilter('')">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          {{ t('documents.clear_filter') }}
        </button>
      </div>

      <!-- breadcrumb (pilulky) -->
      <nav v-if="!tagFilter" class="flex items-center gap-1.5 text-sm mb-3 flex-wrap">
        <button
          type="button"
          :class="['inline-flex items-center gap-1 px-2.5 h-7 rounded-full', breadcrumb.length ? 'cursor-pointer bg-neutral-100 text-neutral-600 hover:bg-neutral-200' : 'bg-primary-50 text-primary-700 font-medium']"
          @click="openFolder(null)"
        >
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10h14V10" /></svg>
          {{ t('documents.root') }}
        </button>
        <template v-for="(b, idx) in breadcrumb" :key="b.id">
          <svg class="w-3.5 h-3.5 text-neutral-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
          <button
            type="button"
            :class="['px-2.5 h-7 rounded-full truncate max-w-[220px]', idx === breadcrumb.length - 1 ? 'bg-primary-50 text-primary-700 font-medium' : 'cursor-pointer bg-neutral-100 text-neutral-600 hover:bg-neutral-200']"
            @click="openFolder(b.id)"
          >{{ b.name }}</button>
        </template>
      </nav>

      <!-- bulk toolbar -->
      <div v-if="selCount > 0" class="flex items-center gap-2 mb-3 px-3 py-2 bg-primary-50 border border-primary-200 rounded-lg text-sm flex-wrap">
        <span class="font-medium text-primary-700">{{ t('documents.selected', { n: selCount }) }}</span>
        <div class="flex-1"></div>
        <button v-if="auth.canWrite" type="button" class="cursor-pointer inline-flex items-center gap-1.5 h-8 px-2.5 text-sm font-medium rounded-md border border-neutral-300 bg-surface text-neutral-700 hover:bg-neutral-50" @click="openMove">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM13 13l3-3m0 0l-3-3m3 3H8" /></svg>
          {{ t('documents.bulk_move') }}
        </button>
        <button v-if="auth.canWrite && selDocCount > 0" type="button" class="cursor-pointer inline-flex items-center gap-1.5 h-8 px-2.5 text-sm font-medium rounded-md border border-neutral-300 bg-surface text-neutral-700 hover:bg-neutral-50" @click="bulkTag">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5a2 2 0 0 1 1.414.586l7 7a2 2 0 0 1 0 2.828l-5 5a2 2 0 0 1-2.828 0l-7-7A2 2 0 0 1 5 8V5a2 2 0 0 1 2-2z" /></svg>
          {{ t('documents.bulk_tag') }}
        </button>
        <button type="button" class="cursor-pointer inline-flex items-center gap-1.5 h-8 px-2.5 text-sm font-medium rounded-md border border-neutral-300 bg-surface text-neutral-700 hover:bg-neutral-50" @click="bulkDownload">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M7 10l5 5 5-5M12 15V3" /></svg>
          {{ t('documents.bulk_download') }}
        </button>
        <button v-if="auth.canWrite" type="button" class="cursor-pointer inline-flex items-center gap-1.5 h-8 px-2.5 text-sm font-medium rounded-md border border-danger-300 bg-surface text-danger-500 hover:bg-danger-50" @click="bulkDelete">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3" /></svg>
          {{ t('documents.bulk_delete') }}
        </button>
        <button type="button" class="cursor-pointer inline-flex items-center justify-center h-8 w-8 rounded-md text-neutral-500 hover:bg-neutral-100" :title="t('documents.clear_selection')" @click="clearSel">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
      </div>

      <div v-if="loading" class="text-sm text-neutral-400 py-8 text-center">…</div>
      <div v-else>
        <!-- folders -->
        <div v-if="folders.length" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 mb-4">
          <div
            v-for="f in folders"
            :key="f.id"
            :class="['group flex items-center gap-2 px-3 py-2.5 bg-surface border rounded-lg cursor-pointer', selectedFolders.has(f.id) ? 'border-primary-400 ring-2 ring-primary-100' : 'border-neutral-200 hover:border-primary-300']"
            @click="openFolder(f.id)"
          >
            <input type="checkbox" class="shrink-0 cursor-pointer" :checked="selectedFolders.has(f.id)" @click.stop="toggleFolderSel(f.id)" />
            <svg class="w-5 h-5 text-warning-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /></svg>
            <span class="min-w-0 flex-1">
              <span class="block text-sm text-neutral-700 truncate">{{ f.name }}</span>
              <span class="block text-xs text-neutral-400 truncate">
                <template v-if="f.subfolder_count">{{ t('documents.folder_count', { n: f.subfolder_count }) }} · </template>{{ t('documents.file_count', { n: f.file_count }) }}<template v-if="f.total_bytes"> · {{ formatBytes(f.total_bytes) }}</template>
              </span>
            </span>
            <span
              v-if="auth.canWrite"
              :class="['flex items-center gap-1 transition-opacity', revealedFolderId === f.id ? 'opacity-100' : 'opacity-0 group-hover:opacity-100']"
              @click.stop="revealFolderActions(f)"
            >
              <button type="button" :class="['text-neutral-400 hover:text-primary-600', (canHover || revealedFolderId === f.id) ? '' : 'pointer-events-none']" @click.stop="renameFolder(f)" :title="t('documents.rename')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828z" /></svg>
              </button>
              <button type="button" :class="['text-neutral-400 hover:text-danger-500', (canHover || revealedFolderId === f.id) ? '' : 'pointer-events-none']" @click.stop="deleteFolder(f)" :title="t('documents.delete')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3" /></svg>
              </button>
            </span>
          </div>
        </div>

        <!-- empty -->
        <div v-if="!folders.length && !documents.length" class="text-center py-16 border-2 border-dashed border-neutral-200 rounded-lg">
          <p class="text-sm text-neutral-400">{{ t('documents.empty_folder') }}</p>
          <p v-if="auth.canWrite" class="text-xs text-neutral-300 mt-1">{{ t('documents.drop_here') }}</p>
        </div>

        <!-- documents: grid -->
        <div v-else-if="documents.length && view === 'grid'" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
          <div
            v-for="d in sortedDocuments"
            :key="d.id"
            :class="['relative group bg-surface border rounded-lg overflow-hidden cursor-pointer', selected.has(d.id) ? 'border-primary-400 ring-2 ring-primary-100' : 'border-neutral-200 hover:border-primary-300']"
            @click="openDoc(d)"
          >
            <input type="checkbox" class="absolute top-2 left-2 z-10" :checked="selected.has(d.id)" @click.stop="toggleSel(d.id)" />
            <div :class="['bg-neutral-50 flex items-center justify-center', d.has_thumb ? 'h-28' : 'h-14']">
              <img v-if="d.has_thumb" :src="documentsApi.thumbUrl(d.id)" class="w-full h-full object-cover" alt="" />
              <span v-else :class="['px-3 py-1.5 rounded text-sm font-bold', docTypeBadge(d.doc_type).class]">{{ docTypeBadge(d.doc_type).label }}</span>
            </div>
            <div class="p-2">
              <p class="text-xs font-medium text-neutral-700 truncate" :title="d.title">{{ d.title }}</p>
              <p class="text-[10px] text-neutral-400">{{ formatBytes(d.size_bytes) }}</p>
            </div>
          </div>
        </div>

        <!-- documents: list -->
        <table v-else-if="documents.length" class="w-full text-sm">
          <thead><tr class="text-left text-xs text-neutral-400 border-b border-neutral-200">
            <th class="py-2 w-8"><input type="checkbox" :checked="selDocCount === documents.length && documents.length > 0" @change="selDocCount === documents.length ? clearSel() : selectAll()" /></th>
            <th class="py-2">
              <button type="button" class="cursor-pointer inline-flex items-center gap-1 hover:text-neutral-700" @click="setSort('name')">
                {{ t('documents.name') }}
                <span v-if="sortKey === 'name'">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
              </button>
            </th>
            <th class="py-2 w-20">{{ t('documents.type') }}</th>
            <th class="py-2 w-28 text-right pr-8">
              <button type="button" class="cursor-pointer inline-flex items-center gap-1 hover:text-neutral-700" @click="setSort('size')">
                {{ t('documents.size') }}
                <span v-if="sortKey === 'size'">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
              </button>
            </th>
            <th class="py-2 w-28 pl-2">
              <button type="button" class="cursor-pointer inline-flex items-center gap-1 hover:text-neutral-700" @click="setSort('created')">
                {{ t('documents.uploaded') }}
                <span v-if="sortKey === 'created'">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
              </button>
            </th>
          </tr></thead>
          <tbody>
            <tr v-for="d in sortedDocuments" :key="d.id" :class="['border-b border-neutral-100 hover:bg-neutral-50 cursor-pointer', selected.has(d.id) && 'bg-primary-50']" @click="openDoc(d)">
              <td class="py-2" @click.stop><input type="checkbox" :checked="selected.has(d.id)" @change="toggleSel(d.id)" /></td>
              <td class="py-2 text-neutral-700 truncate">{{ d.title }}</td>
              <td class="py-2"><span :class="['px-1.5 py-0.5 rounded text-[10px] font-semibold', docTypeBadge(d.doc_type).class]">{{ docTypeBadge(d.doc_type).label }}</span></td>
              <td class="py-2 text-right text-neutral-500 tabular-nums pr-8">{{ formatBytes(d.size_bytes) }}</td>
              <td class="py-2 text-neutral-500 pl-2">{{ d.created_at.slice(0, 10) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Drag overlay -->
    <div v-if="dragOver && auth.canWrite" class="fixed inset-0 z-40 bg-primary-500/10 border-4 border-dashed border-primary-400 flex items-center justify-center pointer-events-none">
      <span class="px-4 py-2 bg-surface rounded-lg shadow text-primary-700 font-medium">{{ t('documents.drop_here') }}</span>
    </div>

    <!-- Move modal -->
    <div v-if="moveOpen" class="fixed inset-0 z-50 bg-black/30 flex items-center justify-center p-4" @click.self="moveOpen = false">
      <div class="bg-surface rounded-lg shadow-xl max-w-md w-full p-4 max-h-[70vh] overflow-auto">
        <h3 class="text-sm font-medium text-neutral-700 mb-3">{{ t('documents.bulk_move') }} · {{ t('documents.selected', { n: selCount }) }}</h3>
        <ul class="space-y-0.5">
          <li>
            <button type="button" class="cursor-pointer w-full text-left px-3 py-2 rounded hover:bg-neutral-50 text-sm inline-flex items-center gap-1.5" @click="doMove(null)">
              <svg class="w-4 h-4 text-neutral-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10h14V10" /></svg>
              {{ t('documents.root') }}
            </button>
          </li>
          <li v-for="f in moveTreeFlat" :key="f.id">
            <button
              type="button"
              :disabled="forbiddenMoveTargets.has(f.id)"
              :style="{ paddingLeft: (f.depth * 18 + 12) + 'px' }"
              class="w-full text-left pr-3 py-2 rounded text-sm inline-flex items-center gap-1.5 disabled:opacity-40 disabled:cursor-not-allowed enabled:cursor-pointer enabled:hover:bg-neutral-50"
              @click="doMove(f.id)"
            >
              <svg class="w-4 h-4 text-warning-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /></svg>
              <span class="truncate">{{ f.name }}</span>
            </button>
          </li>
        </ul>
      </div>
    </div>

    <!-- Bulk tag modal (našeptávání tagů) -->
    <div v-if="tagModalOpen" class="fixed inset-0 z-50 bg-black/30 flex items-center justify-center p-4" @click.self="tagModalOpen = false">
      <div class="bg-surface rounded-lg shadow-xl max-w-md w-full p-4">
        <h3 class="text-sm font-medium text-neutral-700 mb-3">{{ t('documents.bulk_tag') }} · {{ t('documents.selected', { n: selDocCount }) }}</h3>
        <TagInput v-model="bulkTags" :autofocus="true" />
        <div class="flex justify-end gap-2 mt-4">
          <button type="button" class="cursor-pointer h-9 px-3 text-sm rounded-md border border-neutral-300 text-neutral-600 hover:bg-neutral-50" @click="tagModalOpen = false">{{ t('documents.cancel_job') }}</button>
          <button type="button" class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 text-sm font-medium rounded-md bg-primary-600 text-white hover:bg-primary-700" @click="applyBulkTags">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            {{ t('documents.save') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

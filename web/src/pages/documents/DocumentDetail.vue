<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { documentsApi, type DocItem, type EntityType, type LinkSearchResult } from '@/api/documents'
import { docTypeBadge, formatBytes, canInline } from '@/components/documents/docFormat'
import EntityLinkPicker from '@/components/documents/EntityLinkPicker.vue'
import TagInput from '@/components/documents/TagInput.vue'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const id = computed(() => Number(route.params.id))
const doc = ref<DocItem | null>(null)
const loading = ref(true)
const previewOpen = ref(true)
const previewOverrideId = ref<number | null>(null)
const saving = ref(false)

// editable metadata
const title = ref('')
const description = ref('')
const tags = ref<string[]>([])

async function load() {
  loading.value = true
  try {
    const d = await documentsApi.get(id.value)
    doc.value = d
    title.value = d.title
    description.value = d.description ?? ''
    tags.value = d.tags ?? []
  } catch {
    toast.error(t('documents.upload_failed'))
    router.push({ name: 'documents' })
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!doc.value) return
  saving.value = true
  try {
    const updated = await documentsApi.update(doc.value.id, {
      title: title.value.trim(),
      description: description.value.trim() || null,
      tags: tags.value,
    })
    doc.value = updated
    tags.value = updated.tags ?? []
    toast.success(t('documents.saved'))
  } catch {
    toast.error(t('documents.upload_failed'))
  } finally {
    saving.value = false
  }
}

async function onTrash() {
  if (!doc.value) return
  if (!confirm(t('documents.delete_confirm'))) return
  await documentsApi.remove(doc.value.id)
  toast.success(t('documents.saved'))
  router.push({ name: 'documents', query: doc.value.folder_id ? { folder: String(doc.value.folder_id) } : {} })
}

async function onLink(r: LinkSearchResult) {
  if (!doc.value) return
  const links = await documentsApi.addLink(doc.value.id, r.entity_type, r.entity_id)
  doc.value.links = links
}
async function onUnlink(entityType: EntityType, entityId: number) {
  if (!doc.value) return
  doc.value.links = await documentsApi.removeLink(doc.value.id, entityType, entityId)
}

// Co zobrazit v náhledu: samotný dokument (PDF/obrázek), jinak první
// náhledovatelná příloha (typicky PDF uvnitř ZFO datové zprávy).
const previewTarget = computed<DocItem | null>(() => {
  const d = doc.value
  if (!d) return null
  const all: DocItem[] = [d, ...(d.attachments ?? [])]
  // Ruční výběr (tlačítko „Zobrazit" u přílohy) má přednost.
  if (previewOverrideId.value) {
    const sel = all.find(x => x.id === previewOverrideId.value && canInline(x.doc_type))
    if (sel) return sel
  }
  if (canInline(d.doc_type)) return d
  return (d.attachments ?? []).find(a => canInline(a.doc_type)) ?? null
})
const previewUrl = computed(() => previewTarget.value ? documentsApi.previewUrl(previewTarget.value.id) : '')

// Neuložené změny metadat (název / popis / tagy).
const dirty = computed(() => {
  const d = doc.value
  if (!d) return false
  if (title.value.trim() !== d.title) return true
  if ((description.value.trim() || null) !== (d.description ?? null)) return true
  const cur = [...tags.value].sort().join('')
  const orig = [...(d.tags ?? [])].sort().join('')
  return cur !== orig
})

function showPreview(a: DocItem) {
  previewOverrideId.value = a.id
  previewOpen.value = true
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function entityLabel(tp: EntityType): string { return t(`documents.entity.${tp}`) }

function goFolder(id: number | null) {
  router.push({ name: 'documents', query: id ? { folder: String(id) } : {} })
}

function goEntity(l: { entity_type: EntityType; entity_id: number }) {
  const map: Record<EntityType, string> = {
    invoice: 'invoice-detail',
    purchase_invoice: 'purchase-invoice-detail',
    client: 'client-detail',
    project: 'project-detail',
  }
  router.push({ name: map[l.entity_type], params: { id: l.entity_id } })
}

onMounted(load)
</script>

<template>
  <div v-if="doc" class="space-y-4">
    <!-- breadcrumb (pilulky) -->
    <nav class="flex items-center gap-1.5 text-sm flex-wrap">
      <button type="button" class="cursor-pointer inline-flex items-center gap-1 px-2.5 h-7 rounded-full bg-neutral-100 text-neutral-600 hover:bg-neutral-200" @click="goFolder(null)">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10h14V10" /></svg>
        {{ t('documents.root') }}
      </button>
      <template v-for="b in (doc.breadcrumb ?? [])" :key="b.id">
        <svg class="w-3.5 h-3.5 text-neutral-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
        <button type="button" class="cursor-pointer px-2.5 h-7 rounded-full bg-neutral-100 text-neutral-600 hover:bg-neutral-200 truncate max-w-[200px]" @click="goFolder(b.id)">{{ b.name }}</button>
      </template>
      <svg class="w-3.5 h-3.5 text-neutral-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
      <span class="px-2.5 h-7 inline-flex items-center rounded-full bg-primary-50 text-primary-700 font-medium truncate max-w-[260px]">{{ doc.title }}</span>
    </nav>

    <!-- Header -->
    <div class="flex items-start gap-3">
      <button type="button" class="text-neutral-400 hover:text-neutral-700 mt-1" @click="router.back()">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
      </button>
      <span :class="['shrink-0 px-2 py-1 rounded text-xs font-semibold', docTypeBadge(doc.doc_type).class]">{{ docTypeBadge(doc.doc_type).label }}</span>
      <div class="min-w-0 flex-1">
        <h1 class="text-lg font-semibold text-neutral-800 truncate">{{ doc.title }}</h1>
        <p class="text-xs text-neutral-500">
          {{ doc.original_name }} · {{ formatBytes(doc.size_bytes) }} · {{ doc.created_at.slice(0, 16) }}
        </p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <a :href="documentsApi.downloadUrl(doc.id)" class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 text-sm font-medium rounded-md border border-neutral-300 text-neutral-700 hover:bg-neutral-50">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M7 10l5 5 5-5M12 15V3" /></svg>
          {{ t('documents.download') }}
        </a>
        <button v-if="auth.canWrite" type="button" class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 text-sm font-medium rounded-md border border-danger-300 text-danger-500 hover:bg-danger-50" @click="onTrash">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3" /></svg>
          {{ t('documents.delete') }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Left: preview -->
      <div class="lg:col-span-2 space-y-4">
        <div v-if="previewTarget" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <div class="flex items-center justify-between px-4 py-2 border-b border-neutral-100">
            <span class="text-sm font-medium text-neutral-600">
              {{ t('documents.preview') }}
              <span v-if="previewTarget.id !== doc.id" class="text-neutral-400 font-normal">· {{ previewTarget.original_name }}</span>
            </span>
            <button type="button" class="text-xs text-neutral-500 hover:text-neutral-800" @click="previewOpen = !previewOpen">
              {{ previewOpen ? '–' : '+' }}
            </button>
          </div>
          <div v-if="previewOpen" class="bg-neutral-100">
            <iframe
              v-if="previewTarget.doc_type === 'pdf'"
              :src="previewUrl + '#view=FitH'"
              class="w-full h-[72vh] border-0"
              :title="previewTarget.original_name"
            ></iframe>
            <div v-else class="flex justify-center p-4">
              <img :src="previewUrl" :alt="previewTarget.original_name" class="max-w-full max-h-[72vh] object-contain" />
            </div>
          </div>
        </div>

        <!-- DMS message panel (ZFO) -->
        <div v-if="doc.dms_message" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <h3 class="text-sm font-medium text-neutral-700 mb-3">{{ t('documents.dms.title') }}</h3>
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <div><dt class="text-neutral-400 text-xs">{{ t('documents.dms.message_id') }}</dt><dd class="text-neutral-800">{{ doc.dms_message.dm_id || '—' }}</dd></div>
            <div><dt class="text-neutral-400 text-xs">{{ t('documents.dms.direction') }}</dt><dd class="text-neutral-800">{{ doc.dms_message.direction === 'sent' ? t('documents.dms.sent') : t('documents.dms.received') }}</dd></div>
            <div><dt class="text-neutral-400 text-xs">{{ t('documents.dms.sender') }}</dt><dd class="text-neutral-800">{{ doc.dms_message.sender_name || '—' }}<span v-if="doc.dms_message.sender_box_id" class="text-neutral-400"> · {{ doc.dms_message.sender_box_id }}</span></dd></div>
            <div><dt class="text-neutral-400 text-xs">{{ t('documents.dms.recipient') }}</dt><dd class="text-neutral-800">{{ doc.dms_message.recipient_name || '—' }}<span v-if="doc.dms_message.recipient_box_id" class="text-neutral-400"> · {{ doc.dms_message.recipient_box_id }}</span></dd></div>
            <div class="sm:col-span-2"><dt class="text-neutral-400 text-xs">{{ t('documents.dms.subject') }}</dt><dd class="text-neutral-800">{{ doc.dms_message.annotation || '—' }}</dd></div>
            <div><dt class="text-neutral-400 text-xs">{{ t('documents.dms.delivered') }}</dt><dd class="text-neutral-800">{{ doc.dms_message.delivery_time || '—' }}</dd></div>
            <div><dt class="text-neutral-400 text-xs">{{ t('documents.dms.accepted') }}</dt><dd class="text-neutral-800">{{ doc.dms_message.acceptance_time || '—' }}</dd></div>
          </dl>
        </div>

        <!-- Attachments (ZFO children) -->
        <div v-if="doc.attachments && doc.attachments.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <h3 class="text-sm font-medium text-neutral-700 mb-3">{{ t('documents.attachments') }} ({{ doc.attachments.length }})</h3>
          <ul class="space-y-1">
            <li v-for="a in doc.attachments" :key="a.id" :class="['flex items-center gap-3 px-2 py-1.5 rounded', previewTarget && previewTarget.id === a.id ? 'bg-primary-50' : 'hover:bg-neutral-50']">
              <span :class="['shrink-0 px-1.5 py-0.5 rounded text-[10px] font-semibold', docTypeBadge(a.doc_type).class]">{{ docTypeBadge(a.doc_type).label }}</span>
              <button type="button" class="min-w-0 flex-1 text-left text-sm text-neutral-700 truncate hover:text-primary-600" @click="router.push({ name: 'document-detail', params: { id: a.id } })">{{ a.title }}</button>
              <span class="text-xs text-neutral-400">{{ formatBytes(a.size_bytes) }}</span>
              <button v-if="canInline(a.doc_type)" type="button" class="cursor-pointer inline-flex items-center gap-1 h-7 px-2 text-xs rounded-md border border-neutral-300 text-neutral-600 hover:bg-surface" @click="showPreview(a)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5s8.268 2.943 9.542 7c-1.274 4.057-5.065 7-9.542 7s-8.268-2.943-9.542-7z" /><circle cx="12" cy="12" r="3" /></svg>
                {{ t('documents.show') }}
              </button>
              <a :href="documentsApi.downloadUrl(a.id)" class="text-neutral-400 hover:text-primary-600" :title="t('documents.download')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M7 10l5 5 5-5M12 15V3" /></svg>
              </a>
            </li>
          </ul>
        </div>
      </div>

      <!-- Right: metadata + links -->
      <div class="space-y-4">
        <!-- Metadata -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 space-y-3">
          <h3 class="text-sm font-medium text-neutral-700">{{ t('documents.metadata') }}</h3>
          <div>
            <label class="block text-xs text-neutral-400 mb-1">{{ t('documents.name') }}</label>
            <input v-model="title" :disabled="!auth.canWrite" type="text" class="w-full px-3 py-2 text-sm border border-neutral-300 rounded-lg focus:ring-2 focus:ring-primary-200 outline-none disabled:bg-neutral-50" />
          </div>
          <div>
            <label class="block text-xs text-neutral-400 mb-1">{{ t('documents.description') }}</label>
            <textarea v-model="description" :disabled="!auth.canWrite" rows="3" class="w-full px-3 py-2 text-sm border border-neutral-300 rounded-lg focus:ring-2 focus:ring-primary-200 outline-none disabled:bg-neutral-50"></textarea>
          </div>
          <div>
            <label class="block text-xs text-neutral-400 mb-1">{{ t('documents.tags') }}</label>
            <TagInput v-if="auth.canWrite" v-model="tags" />
            <div v-else class="flex flex-wrap gap-1">
              <span v-for="tg in tags" :key="tg" class="inline-flex items-center px-2 py-0.5 rounded-full bg-primary-50 text-primary-700 text-xs">{{ tg }}</span>
              <span v-if="!tags.length" class="text-sm text-neutral-400">—</span>
            </div>
          </div>
          <div v-if="dirty && auth.canWrite" class="flex items-center gap-2 px-3 py-2 rounded-md bg-warning-50 text-warning-700 text-xs">
            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /></svg>
            {{ t('documents.unsaved_changes') }}
          </div>
          <button v-if="auth.canWrite" type="button" :disabled="saving || !dirty" class="cursor-pointer w-full inline-flex items-center justify-center gap-1.5 h-9 px-3 text-sm font-medium rounded-md bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50 disabled:cursor-default" @click="save">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            {{ t('documents.save') }}
          </button>
        </div>

        <!-- Links (oboustranné párování) -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 space-y-3">
          <h3 class="text-sm font-medium text-neutral-700">{{ t('documents.linked_to') }}</h3>
          <ul v-if="doc.links && doc.links.length" class="space-y-1">
            <li v-for="l in doc.links" :key="l.entity_type + '-' + l.entity_id" class="flex items-start gap-2 text-sm">
              <span class="mt-0.5 shrink-0 px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-neutral-100 text-neutral-500">{{ entityLabel(l.entity_type) }}</span>
              <button type="button" class="min-w-0 flex-1 text-left text-neutral-700 hover:text-primary-600 hover:underline" @click="goEntity(l)">{{ l.label }}</button>
              <button v-if="auth.canWrite" type="button" class="mt-0.5 shrink-0 text-neutral-400 hover:text-warning-600" :title="t('documents.unlink_hint')" @click="onUnlink(l.entity_type, l.entity_id)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244M3 3l18 18" /></svg>
              </button>
            </li>
          </ul>
          <p v-else class="text-sm text-neutral-400">{{ t('documents.no_links') }}</p>
          <div v-if="auth.canWrite">
            <label class="block text-xs text-neutral-400 mb-1">{{ t('documents.add_link') }}</label>
            <EntityLinkPicker @select="onLink" />
          </div>
        </div>
      </div>
    </div>
  </div>

  <div v-else-if="loading" class="p-8 text-center text-neutral-400">…</div>
</template>

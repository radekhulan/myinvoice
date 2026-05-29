<script setup lang="ts">
import { ref, watch, onMounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { documentsApi, type DocItem, type EntityType } from '@/api/documents'
import { docTypeBadge, formatBytes } from './docFormat'

const props = defineProps<{ entityType: EntityType; entityId: number }>()

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const docs = ref<DocItem[]>([])
const loading = ref(false)
const attaching = ref(false)
const query = ref('')
const candidates = ref<DocItem[]>([])
const searchInput = ref<HTMLInputElement | null>(null)
let debounce: ReturnType<typeof setTimeout> | null = null

function toggleAttach() {
  attaching.value = !attaching.value
  if (attaching.value) nextTick(() => searchInput.value?.focus())
}

async function load() {
  loading.value = true
  try {
    docs.value = await documentsApi.byEntity(props.entityType, props.entityId)
  } finally {
    loading.value = false
  }
}

watch(query, (q) => {
  if (debounce) clearTimeout(debounce)
  if (q.trim().length < 2) { candidates.value = []; return }
  debounce = setTimeout(async () => {
    const linked = new Set(docs.value.map(d => d.id))
    candidates.value = (await documentsApi.search(q.trim())).filter(d => !linked.has(d.id))
  }, 220)
})

async function attach(doc: DocItem) {
  try {
    await documentsApi.addLink(doc.id, props.entityType, props.entityId)
    query.value = ''
    candidates.value = []
    attaching.value = false
    await load()
    toast.success(t('documents.saved'))
  } catch {
    toast.error(t('documents.upload_failed'))
  }
}

async function unlink(doc: DocItem) {
  try {
    await documentsApi.removeLink(doc.id, props.entityType, props.entityId)
    await load()
  } catch {
    toast.error(t('documents.upload_failed'))
  }
}

onMounted(load)
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-medium text-neutral-700 flex items-center gap-2">
        <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 0 0 2-2V9.414a1 1 0 0 0-.293-.707l-5.414-5.414A1 1 0 0 0 12.586 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2zM9 13h6m-6 4h6" />
        </svg>
        {{ t('documents.panel_title') }}
        <span v-if="docs.length" class="text-xs text-neutral-400">({{ docs.length }})</span>
      </h3>
      <button
        v-if="auth.canWrite"
        type="button"
        class="text-xs px-2 py-1 rounded border border-neutral-200 text-neutral-600 hover:bg-neutral-50"
        @click="toggleAttach"
      >
        {{ t('documents.panel_attach') }}
      </button>
    </div>

    <!-- Připojení existujícího dokumentu (fulltext) -->
    <div v-if="attaching" class="mb-3">
      <input
        ref="searchInput"
        v-model="query"
        type="text"
        class="w-full px-3 py-2 text-sm border border-neutral-300 rounded-lg focus:ring-2 focus:ring-primary-200 outline-none"
        :placeholder="t('documents.search_placeholder')"
      />
      <ul v-if="candidates.length" class="mt-1 border border-neutral-200 rounded-lg divide-y max-h-56 overflow-auto">
        <li
          v-for="c in candidates"
          :key="c.id"
          class="flex items-center gap-2 px-2 py-1.5 hover:bg-neutral-50 cursor-pointer"
          @click="attach(c)"
        >
          <span :class="['shrink-0 px-1.5 py-0.5 rounded text-[10px] font-semibold', docTypeBadge(c.doc_type).class]">{{ docTypeBadge(c.doc_type).label }}</span>
          <span class="text-sm text-neutral-700 truncate">{{ c.title }}</span>
        </li>
      </ul>
    </div>

    <div v-if="loading" class="text-sm text-neutral-400">…</div>
    <div v-else-if="docs.length === 0" class="text-sm text-neutral-400">{{ t('documents.panel_empty') }}</div>
    <ul v-else class="space-y-1">
      <li
        v-for="d in docs"
        :key="d.id"
        class="flex items-center gap-3 px-2 py-1.5 rounded hover:bg-neutral-50 group"
      >
        <img
          v-if="d.has_thumb"
          :src="documentsApi.thumbUrl(d.id)"
          class="w-8 h-8 object-cover rounded border border-neutral-200 bg-neutral-50 shrink-0"
          alt=""
        />
        <span v-else :class="['shrink-0 w-8 h-8 flex items-center justify-center rounded text-[9px] font-semibold', docTypeBadge(d.doc_type).class]">
          {{ docTypeBadge(d.doc_type).label }}
        </span>
        <button type="button" class="min-w-0 flex-1 text-left" @click="router.push({ name: 'document-detail', params: { id: d.id } })">
          <span class="block text-sm text-neutral-800 truncate hover:text-primary-600">{{ d.title }}</span>
          <span class="block text-xs text-neutral-400">{{ formatBytes(d.size_bytes) }}</span>
        </button>
        <a :href="documentsApi.downloadUrl(d.id)" class="opacity-0 group-hover:opacity-100 text-neutral-400 hover:text-primary-600" :title="t('documents.download')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M7 10l5 5 5-5M12 15V3" /></svg>
        </a>
        <button v-if="auth.canWrite" type="button" class="opacity-0 group-hover:opacity-100 text-neutral-400 hover:text-warning-600" :title="t('documents.unlink_hint')" @click="unlink(d)">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244M3 3l18 18" /></svg>
        </button>
      </li>
    </ul>
  </div>
</template>

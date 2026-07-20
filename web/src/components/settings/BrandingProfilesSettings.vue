<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type BrandingProfile } from '@/api/settings'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()
const emit = defineEmits<{ (event: 'changed'): void }>()
const profiles = ref<BrandingProfile[]>([])
const editing = ref<Partial<BrandingProfile> | null>(null)
const saving = ref(false)

const emptyProfile = (): Partial<BrandingProfile> => ({
  name: '', display_name: null, tagline: null, email: null, reply_to: null,
  phone: null, web: null, email_footer: null, accent_color: '#3B2D83',
  pdf_logo_show_name: true, is_active: true,
})

async function load() {
  profiles.value = await settingsApi.listBrandingProfiles()
}

function edit(profile?: BrandingProfile) {
  editing.value = profile ? { ...profile } : emptyProfile()
}

async function save() {
  if (!editing.value?.name?.trim()) return
  saving.value = true
  try {
    if (editing.value.id) await settingsApi.updateBrandingProfile(editing.value.id, editing.value)
    else await settingsApi.createBrandingProfile(editing.value)
    editing.value = null
    await load()
    emit('changed')
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally { saving.value = false }
}

async function remove(profile: BrandingProfile) {
  if (!confirm(t('settings.branding_profiles.delete_confirm', { name: profile.name }))) return
  await settingsApi.deleteBrandingProfile(profile.id)
  await load()
  emit('changed')
}

async function uploadLogo(profile: BrandingProfile, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  try {
    await settingsApi.uploadBrandingProfileLogo(profile.id, file)
    await load()
    emit('changed')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally { input.value = '' }
}

async function deleteLogo(profile: BrandingProfile) {
  await settingsApi.deleteBrandingProfileLogo(profile.id)
  await load()
  emit('changed')
}

onMounted(load)
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
    <div class="flex items-start justify-between gap-4 mb-4">
      <div>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('settings.branding_profiles.title') }}</h2>
        <p class="text-xs text-neutral-500 mt-1">{{ t('settings.branding_profiles.hint') }}</p>
      </div>
      <button class="h-9 px-3 rounded-md bg-primary-600 text-white text-sm" @click="edit()">
        {{ t('settings.branding_profiles.add') }}
      </button>
    </div>

    <div v-if="profiles.length" class="space-y-3">
      <article v-for="profile in profiles" :key="profile.id" class="border border-neutral-200 rounded-md p-3">
        <div class="flex items-center justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <span class="w-3 h-3 rounded-full border border-neutral-300" :style="{ backgroundColor: profile.accent_color }" />
              <strong class="text-sm truncate">{{ profile.name }}</strong>
              <span v-if="!profile.is_active" class="text-xs text-neutral-400">{{ t('settings.branding_profiles.inactive') }}</span>
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ profile.display_name || profile.email || t('settings.branding_profiles.inherits') }}</p>
          </div>
          <div class="flex gap-2">
            <label class="text-xs text-primary-700 cursor-pointer">
              {{ profile.logo_path ? t('settings.branding_profiles.change_logo') : t('settings.branding_profiles.upload_logo') }}
              <input type="file" accept="image/png,image/jpeg,image/svg+xml" class="hidden" @change="uploadLogo(profile, $event)" />
            </label>
            <button v-if="profile.logo_path" class="text-xs text-neutral-500" @click="deleteLogo(profile)">{{ t('settings.branding_profiles.remove_logo') }}</button>
            <button class="text-xs text-primary-700" @click="edit(profile)">{{ t('common.edit') }}</button>
            <button class="text-xs text-danger-600" @click="remove(profile)">{{ t('common.delete') }}</button>
          </div>
        </div>
      </article>
    </div>
    <p v-else class="text-sm text-neutral-500">{{ t('settings.branding_profiles.empty') }}</p>

    <div v-if="editing" class="mt-4 border-t border-neutral-200 pt-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <label class="text-xs font-medium text-neutral-700">{{ t('settings.branding_profiles.name') }}
          <input v-model="editing.name" maxlength="100" class="mt-1 w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        </label>
        <label class="text-xs font-medium text-neutral-700">{{ t('settings.branding_profiles.display_name') }}
          <input v-model="editing.display_name" maxlength="190" class="mt-1 w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        </label>
        <label class="text-xs font-medium text-neutral-700">{{ t('settings.branding_profiles.email') }}
          <input v-model="editing.email" type="email" maxlength="190" class="mt-1 w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        </label>
        <label class="text-xs font-medium text-neutral-700">{{ t('settings.branding_profiles.reply_to') }}
          <input v-model="editing.reply_to" type="email" maxlength="190" class="mt-1 w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        </label>
        <label class="text-xs font-medium text-neutral-700">{{ t('settings.branding_profiles.phone') }}
          <input v-model="editing.phone" maxlength="40" class="mt-1 w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        </label>
        <label class="text-xs font-medium text-neutral-700">{{ t('settings.branding_profiles.web') }}
          <input v-model="editing.web" maxlength="255" class="mt-1 w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        </label>
        <label class="text-xs font-medium text-neutral-700">{{ t('settings.branding_profiles.tagline') }}
          <input v-model="editing.tagline" maxlength="255" class="mt-1 w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        </label>
        <label class="text-xs font-medium text-neutral-700">{{ t('settings.branding_profiles.color') }}
          <input v-model="editing.accent_color" type="color" class="mt-1 w-full h-9 border border-neutral-300 rounded-md" />
        </label>
        <label class="md:col-span-2 text-xs font-medium text-neutral-700">{{ t('settings.branding_profiles.footer') }}
          <textarea v-model="editing.email_footer" rows="3" class="mt-1 w-full px-3 py-2 border border-neutral-300 rounded-md text-sm" />
        </label>
      </div>
      <div class="mt-3 flex flex-wrap gap-4 text-sm">
        <label class="flex items-center gap-2"><input v-model="editing.pdf_logo_show_name" type="checkbox" />{{ t('settings.branding_profiles.show_name') }}</label>
        <label class="flex items-center gap-2"><input v-model="editing.is_active" type="checkbox" />{{ t('settings.branding_profiles.active') }}</label>
      </div>
      <div class="flex justify-end gap-2 mt-4">
        <button class="h-9 px-3 border border-neutral-300 rounded-md text-sm" @click="editing = null">{{ t('common.cancel') }}</button>
        <button :disabled="saving || !editing.name?.trim()" class="h-9 px-3 bg-primary-600 text-white rounded-md text-sm disabled:opacity-50" @click="save">{{ t('common.save') }}</button>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'

const { t, locale } = useI18n()
import AppShell from '@/components/layout/AppShell.vue'
import { useAuthStore } from '@/stores/auth'
import { authApi, type SetupPayload } from '@/api/auth'

const router = useRouter()
const auth = useAuthStore()

const step = ref<1 | 2 | 3>(1)
const submitting = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

const admin = ref({ name: '', email: '', password: '', password_confirm: '' })
const requireTotp = ref(false)
const skipSupplier = ref(false)
const generateSample = ref(false)
const sampleResult = ref<{ clients: number; projects: number; invoices: number; credit_notes: number } | null>(null)
const sampleError = ref('')
const supplier = ref({
  company_name: '',
  display_name: '',
  ic: '',
  dic: '',
  street: '',
  city: '',
  zip: '',
  country_iso2: 'CZ',
  email: '',
  phone: '',
  web: '',
  is_vat_payer: true,
  default_currency: 'CZK',
  default_payment_due_days: 7,
  default_hourly_rate: 1500,
  bank_currency: 'CZK',
  account_number: '',
  bank_code: '',
  bank_name: '',
  iban: '',
  bic: '',
})

// ARES lookup state
const aresLoading = ref(false)
const aresMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null)

async function goToApp() {
  // Po setupu je backend session vytvořená (auto-login). Refresh auth store + hard reload na /.
  await auth.refresh()
  window.location.href = '/'
}

async function lookupAres() {
  const ic = (supplier.value.ic || '').trim()
  if (!/^\d{8}$/.test(ic)) {
    aresMessage.value = { type: 'error', text: t('supplier.ares_invalid_ic') }
    return
  }
  aresLoading.value = true
  aresMessage.value = null
  try {
    const r = await authApi.setupAresLookup(ic)
    if (!r.found || !r.data) {
      aresMessage.value = { type: 'error', text: t('supplier.ares_not_found') }
      return
    }
    const d = r.data
    supplier.value.company_name = d.company_name || supplier.value.company_name
    supplier.value.street       = d.street       || supplier.value.street
    supplier.value.city         = d.city         || supplier.value.city
    supplier.value.zip          = d.zip          || supplier.value.zip
    supplier.value.country_iso2 = d.country_iso2 || supplier.value.country_iso2 || 'CZ'
    supplier.value.ic           = d.ic           || ic
    supplier.value.dic          = d.dic          || supplier.value.dic
    supplier.value.is_vat_payer = d.is_vat_payer
    aresMessage.value = { type: 'success', text: t('supplier.ares_loaded', { name: d.company_name }) }
  } catch (e: any) {
    aresMessage.value = { type: 'error', text: e?.response?.data?.error?.message || t('supplier.ares_failed') }
  } finally {
    aresLoading.value = false
  }
}

const passwordOk = computed(() => admin.value.password.length >= 12)
const passwordMatch = computed(() => admin.value.password === admin.value.password_confirm)
const adminValid = computed(
  () =>
    admin.value.name.trim().length > 0 &&
    /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(admin.value.email) &&
    passwordOk.value &&
    passwordMatch.value,
)

onMounted(async () => {
  await auth.fetchSetupStatus()
  if (!auth.needsSetup) {
    router.replace('/login')
  }
})

function nextStep() {
  if (step.value === 1 && adminValid.value) {
    // Pre-fill supplier emailu admin emailem, pokud ho ještě uživatel needitoval.
    if (!supplier.value.email.trim()) {
      supplier.value.email = admin.value.email.trim()
    }
    step.value = 2
  }
  else if (step.value === 2) submit()
}

async function submit() {
  submitting.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    const payload: SetupPayload = {
      admin: {
        name: admin.value.name.trim(),
        email: admin.value.email.trim(),
        password: admin.value.password,
      },
      require_totp: requireTotp.value,
    }
    if (!skipSupplier.value && supplier.value.company_name.trim()) {
      payload.supplier = {
        company_name: supplier.value.company_name.trim(),
        display_name: supplier.value.display_name || undefined,
        ic: supplier.value.ic || undefined,
        dic: supplier.value.dic || undefined,
        street: supplier.value.street.trim(),
        city: supplier.value.city.trim(),
        zip: supplier.value.zip.trim(),
        country_iso2: supplier.value.country_iso2,
        email: supplier.value.email.trim(),
        phone: supplier.value.phone || undefined,
        web: supplier.value.web || undefined,
        is_vat_payer: supplier.value.is_vat_payer,
        default_currency: supplier.value.default_currency,
        default_payment_due_days: supplier.value.default_payment_due_days,
        default_hourly_rate: supplier.value.default_hourly_rate,
      }
      const hasBank =
        supplier.value.account_number || supplier.value.iban
      if (hasBank) {
        payload.supplier.bank_account = {
          currency: supplier.value.bank_currency,
          account_number: supplier.value.account_number || undefined,
          bank_code: supplier.value.bank_code || undefined,
          bank_name: supplier.value.bank_name || undefined,
          iban: supplier.value.iban || undefined,
          bic: supplier.value.bic || undefined,
        }
      }
    }
    await authApi.setup(payload)

    // Volitelně: vygenerovat sample data (jen pokud user zaškrtl + supplier vyplněn)
    if (generateSample.value && !skipSupplier.value) {
      try {
        sampleResult.value = await authApi.setupSample()
      } catch (e: any) {
        sampleError.value = e?.response?.data?.error?.message || 'Generování sample dat selhalo.'
      }
    }

    step.value = 3
  } catch (e: any) {
    const data = e?.response?.data?.error
    fieldErrors.value = data?.fields ?? {}
    if (Object.keys(fieldErrors.value).length > 0) {
      // Konkrétnější hláška: vypiš která pole jsou nevalidní (CZ/EN labels)
      const labels: Record<string, string> = {
        'admin.name':            t('users.name'),
        'admin.email':           `${t('setup.step_admin')} – ${t('auth.email')}`,
        'admin.password':        t('auth.password'),
        'supplier.company_name': t('client.company_name'),
        'supplier.street':       t('client.street'),
        'supplier.city':         t('client.city'),
        'supplier.zip':          t('client.zip'),
        'supplier.email':        `${t('settings.supplier')} – ${t('auth.email')}`,
      }
      const names = Object.keys(fieldErrors.value).map(k => labels[k] ?? k)
      error.value = (locale.value === 'cs'
        ? 'Vyplň prosím povinná pole: '
        : 'Please fill required fields: ') + names.join(', ')
    } else {
      error.value = data?.message || 'Setup selhal.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AppShell :title="t('setup.title')">
    <div class="w-full max-w-xl">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-6">
        <!-- Progress -->
        <div class="flex items-center mb-6 text-sm">
          <span :class="step >= 1 ? 'text-primary-600 font-medium' : 'text-neutral-400'">1. {{ t('setup.step_admin') }}</span>
          <div class="flex-1 h-px bg-neutral-200 mx-3"></div>
          <span :class="step >= 2 ? 'text-primary-600 font-medium' : 'text-neutral-400'">2. {{ t('settings.supplier') }}</span>
          <div class="flex-1 h-px bg-neutral-200 mx-3"></div>
          <span :class="step >= 3 ? 'text-primary-600 font-medium' : 'text-neutral-400'">3. {{ t('common.success') }}</span>
        </div>

        <!-- Step 1 -->
        <div v-if="step === 1">
          <h2 class="text-xl font-semibold mb-1">{{ t('setup.create_admin') }}</h2>
          <p class="text-sm text-neutral-500 mb-6">{{ locale === 'cs' ? 'První uživatel bude mít plná oprávnění.' : 'The first user will have full permissions.' }}</p>

          <form @submit.prevent="nextStep" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('users.name') }}</label>
              <input v-model="admin.name" required autofocus class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.email') }}</label>
              <input v-model="admin.email" type="email" required autocomplete="email" class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.password') }}</label>
              <input v-model="admin.password" type="password" required autocomplete="new-password" class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <p class="text-xs text-neutral-500 mt-1" :class="{ 'text-danger-500': admin.password && !passwordOk }">
                {{ t('auth.min_chars', { n: 12 }) }}
              </p>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password_confirm') }}</label>
              <input v-model="admin.password_confirm" type="password" required autocomplete="new-password" class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <p v-if="admin.password_confirm && !passwordMatch" class="text-xs text-danger-500 mt-1">
                {{ t('auth.passwords_dont_match') }}
              </p>
            </div>

            <div class="pt-2 border-t border-neutral-200">
              <label class="flex items-start gap-3 cursor-pointer">
                <input
                  v-model="requireTotp"
                  type="checkbox"
                  class="mt-1 h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                />
                <span class="text-sm">
                  <span class="font-medium text-neutral-800">{{ t('setup.require_totp_label') }}</span>
                  <span class="block text-xs text-neutral-500 mt-0.5">{{ t('setup.require_totp_hint') }}</span>
                </span>
              </label>
            </div>

            <button type="submit" :disabled="!adminValid" class="w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 disabled:cursor-not-allowed text-white font-medium rounded-md transition">
              {{ t('common.next') }}
            </button>
          </form>
        </div>

        <!-- Step 2 -->
        <div v-if="step === 2">
          <h2 class="text-xl font-semibold mb-1">{{ t('settings.supplier') }} <span class="text-sm font-normal text-neutral-500">{{ t('common.optional') }}</span></h2>
          <p class="text-sm text-neutral-500 mb-6">
            {{ locale === 'cs'
              ? 'Údaje na vašich fakturách. Můžeš celou sekci přeskočit a vyplnit později; pokud začneš vyplňovat, jsou pole označená * povinná.'
              : 'Details that appear on your invoices. You can skip this whole section and fill it in later; if you start filling it in, fields marked with * are required.' }}
          </p>

          <label class="flex items-center gap-2 text-sm text-neutral-700 mb-4">
            <input v-model="skipSupplier" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('setup.skip_supplier') }}
          </label>

          <div v-if="!skipSupplier" class="space-y-4">
            <!-- ARES lookup helper — vyplní pole níže podle IČO -->
            <div class="bg-primary-50/50 border border-primary-200 rounded-md p-3">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.ares_lookup') }}</label>
              <div class="flex gap-2">
                <input v-model="supplier.ic" type="text" placeholder="12345678" maxlength="8"
                  @keydown.enter.prevent="lookupAres"
                  class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                <button type="button" @click="lookupAres" :disabled="aresLoading"
                  class="cursor-pointer h-10 px-4 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md inline-flex items-center gap-1.5">
                  <svg v-if="!aresLoading" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 1 1-14 0 7 7 0 0 1 14 0z"/></svg>
                  <span v-else>…</span>
                  {{ aresLoading ? t('common.loading') : t('supplier.ares_load') }}
                </button>
              </div>
              <div v-if="aresMessage" class="mt-2 text-xs px-2 py-1 rounded"
                :class="aresMessage.type === 'success' ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-500'">
                {{ aresMessage.text }}
                <span v-if="aresMessage.type === 'success' && !supplier.email" class="block mt-0.5 text-warning-600">
                  {{ t('supplier.ares_loaded_email_hint') }}
                </span>
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.company_name') }} <span class="text-danger-500">*</span></label>
                <input v-model="supplier.company_name" required :class="['w-full h-10 px-3 border rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none', fieldErrors['supplier.company_name'] ? 'border-danger-500' : 'border-neutral-300']" />
                <p v-if="fieldErrors['supplier.company_name']" class="text-xs text-danger-500 mt-1">{{ fieldErrors['supplier.company_name'][0] }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.dic') }}</label>
                <input v-model="supplier.dic" class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.email') }} <span class="text-danger-500">*</span></label>
                <input v-model="supplier.email" type="email" required :class="['w-full h-10 px-3 border rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none', fieldErrors['supplier.email'] ? 'border-danger-500' : 'border-neutral-300']" />
                <p v-if="fieldErrors['supplier.email']" class="text-xs text-danger-500 mt-1">{{ fieldErrors['supplier.email'][0] }}</p>
              </div>
              <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.street') }} <span class="text-danger-500">*</span></label>
                <input v-model="supplier.street" required :class="['w-full h-10 px-3 border rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none', fieldErrors['supplier.street'] ? 'border-danger-500' : 'border-neutral-300']" />
                <p v-if="fieldErrors['supplier.street']" class="text-xs text-danger-500 mt-1">{{ fieldErrors['supplier.street'][0] }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.zip') }} <span class="text-danger-500">*</span></label>
                <input v-model="supplier.zip" required :class="['w-full h-10 px-3 border rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none', fieldErrors['supplier.zip'] ? 'border-danger-500' : 'border-neutral-300']" />
                <p v-if="fieldErrors['supplier.zip']" class="text-xs text-danger-500 mt-1">{{ fieldErrors['supplier.zip'][0] }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.city') }} <span class="text-danger-500">*</span></label>
                <input v-model="supplier.city" required :class="['w-full h-10 px-3 border rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none', fieldErrors['supplier.city'] ? 'border-danger-500' : 'border-neutral-300']" />
                <p v-if="fieldErrors['supplier.city']" class="text-xs text-danger-500 mt-1">{{ fieldErrors['supplier.city'][0] }}</p>
              </div>
            </div>

            <div class="border-t border-neutral-200 pt-4">
              <h3 class="text-sm font-semibold mb-3">{{ t('settings.account_cz') }} {{ t('common.optional') }}</h3>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('common.currency') }}</label>
                  <select v-model="supplier.bank_currency" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                    <option value="CZK">CZK</option>
                    <option value="EUR">EUR</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.currency_bank_name') }}</label>
                  <input v-model="supplier.bank_name" class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                </div>
                <template v-if="supplier.bank_currency === 'CZK'">
                  <div>
                    <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.currency_account_cz') }}</label>
                    <input v-model="supplier.account_number" placeholder="1000000005" class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.currency_bank_code') }}</label>
                    <input v-model="supplier.bank_code" maxlength="4" placeholder="0100" class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                  </div>
                </template>
                <template v-else>
                  <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.iban') }}</label>
                    <input v-model="supplier.iban" placeholder="CZ65 0800 0000 1920 0014 5399" class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.currency_bic') }}</label>
                    <input v-model="supplier.bic" placeholder="GIBACZPX" class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                  </div>
                </template>
              </div>
            </div>
          </div>

          <!-- Sample data toggle -->
          <div v-if="!skipSupplier" class="mt-4 p-3 border border-primary-200 rounded-md bg-primary-50/50">
            <label class="flex items-start gap-2 cursor-pointer">
              <input v-model="generateSample" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
              <div>
                <div class="text-sm font-medium text-neutral-900">{{ t('setup.sample_label') }}</div>
                <div class="text-xs text-neutral-500 mt-0.5">{{ t('setup.sample_hint') }}</div>
              </div>
            </label>
          </div>

          <div v-if="error" class="mt-4 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
            {{ error }}
          </div>

          <div class="flex gap-3 mt-6">
            <button @click="step = 1" class="px-4 h-10 border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">{{ t('common.previous') }}</button>
            <button @click="submit" :disabled="submitting" class="flex-1 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
              {{ submitting ? '…' : (locale === 'cs' ? 'Dokončit setup' : 'Finish setup') }}
            </button>
          </div>
        </div>

        <!-- Step 3 -->
        <div v-if="step === 3" class="text-center py-8">
          <div class="w-16 h-16 bg-primary-100 rounded-full mx-auto mb-4 flex items-center justify-center">
            <svg class="w-8 h-8 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <h2 class="text-xl font-semibold mb-2">{{ t('common.success') }}</h2>
          <p class="text-neutral-500 mb-2">{{ locale === 'cs' ? 'Admin účet byl vytvořen a jste přihlášen.' : 'Admin account created and you are signed in.' }}</p>

          <div v-if="requireTotp" class="mb-6 inline-block bg-warning-50 border border-warning-500/40 rounded-md px-4 py-2 text-sm text-warning-600 text-left">
            {{ locale === 'cs'
              ? 'Vynucení 2FA je aktivní. Po pokračování budeš přesměrován na nastavení TOTP.'
              : '2FA enforcement is active. You will be redirected to TOTP setup next.' }}
          </div>

          <div v-if="sampleResult" class="mb-6 inline-block bg-success-50 border border-success-500/40 rounded-md px-4 py-2 text-sm text-success-600 text-left">
            {{ t('setup.sample_done', sampleResult) }}
          </div>
          <div v-else-if="sampleError" class="mb-6 inline-block bg-warning-50 border border-warning-500/40 rounded-md px-4 py-2 text-sm text-warning-600 text-left">
            {{ sampleError }}
          </div>

          <div>
            <button @click="goToApp" class="cursor-pointer inline-block px-6 h-10 leading-10 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">
              {{ locale === 'cs' ? 'Pokračovat do aplikace' : 'Continue to app' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </AppShell>
</template>

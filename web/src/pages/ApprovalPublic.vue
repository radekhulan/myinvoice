<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { approvalApi, type PublicApprovalData } from '@/api/approval'
import { useTurnstile } from '@/composables/useTurnstile'

const route = useRoute()
const token = computed(() => String(route.params.token || ''))

const data = ref<PublicApprovalData | null>(null)
const loading = ref(true)
const loadError = ref<string>('')

// UI mode
type Mode = 'review' | 'reject_form' | 'done'
const mode = ref<Mode>('review')
const decidedBy = ref('')
const reason = ref('')          // povinný pro reject
const comment = ref('')         // volitelný pro approve
const submitting = ref(false)
const submitError = ref('')
const result = ref<{ decision: 'approved' | 'rejected'; message: string } | null>(null)

// Captcha
const turnstile = useTurnstile()
const turnstileEl = ref<HTMLElement | null>(null)
const TURNSTILE_SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js'

const wrHasDates = computed(() => !!data.value?.work_report.items.some(i => !!i.work_date))
const lang = computed(() => data.value?.invoice.language || 'cs')

function fmtMoney(n: number, currency: string): string {
  const decimals = currency === 'JPY' ? 0 : 2
  const locale = lang.value === 'en' ? 'en-US' : 'cs-CZ'
  return n.toLocaleString(locale, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + ' ' + currency
}
function fmtHours(n: number): string {
  const locale = lang.value === 'en' ? 'en-US' : 'cs-CZ'
  return n.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtDate(d: string | null): string {
  if (!d) return ''
  const parts = d.slice(0, 10).split('-')
  if (parts.length !== 3) return d
  return lang.value === 'en'
    ? `${parts[2]}.${parts[1]}.${parts[0]}`
    : `${Number(parts[2])}. ${Number(parts[1])}. ${parts[0]}`
}

function tt(cs: string, en: string): string {
  return lang.value === 'en' ? en : cs
}

onMounted(async () => {
  try {
    data.value = await approvalApi.get(token.value)
    if (lang.value === 'en') localStorage.setItem('locale', 'en')
    else localStorage.setItem('locale', 'cs')
    document.title = tt('Schválení výkazu — MyInvoice.cz', 'Approve work report — MyInvoice.cz')
  } catch (e: any) {
    loadError.value = e?.response?.data?.error?.message
      || tt('Tento odkaz není platný nebo již byl použit.',
            'This link is invalid or has already been used.')
  } finally {
    loading.value = false
  }

  // Render captcha AŽ po loading=false → hlavní section je v DOM, ref se naplní.
  if (data.value && data.value.captcha_provider === 'turnstile' && data.value.captcha_site_key) {
    await nextTick()
    if (turnstileEl.value) {
      turnstile.containerRef.value = turnstileEl.value
      await turnstile.render(data.value.captcha_site_key, TURNSTILE_SCRIPT, 'approval')
    }
  }
})

async function approve() {
  await submit('approve')
}

function startReject() {
  mode.value = 'reject_form'
}

async function submitReject() {
  if (!reason.value.trim()) {
    submitError.value = tt('Vyplň prosím důvod zamítnutí.', 'Please provide a reason for rejection.')
    return
  }
  await submit('reject')
}

async function submit(decision: 'approve' | 'reject') {
  if (!data.value) return
  submitError.value = ''

  if (data.value.captcha_provider === 'turnstile' && !turnstile.token.value) {
    submitError.value = tt('Počkej prosím, dokud se nenačte ověření CAPTCHA.',
                           'Please wait for the CAPTCHA to load.')
    return
  }

  submitting.value = true
  try {
    const r = await approvalApi.decide(token.value, {
      decision,
      decided_by_email: decidedBy.value.trim() || null,
      rejection_reason: decision === 'reject' ? reason.value.trim() : null,
      comment: decision === 'approve' ? (comment.value.trim() || null) : null,
      cf_turnstile_response: turnstile.token.value || null,
    })
    result.value = { decision: r.decision, message: r.message }
    mode.value = 'done'
  } catch (e: any) {
    submitError.value = e?.response?.data?.error?.message
      || tt('Akci se nepodařilo dokončit. Zkuste to prosím znovu.',
            'Action failed. Please try again.')
    turnstile.reset()
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-neutral-50 flex flex-col">
    <!-- Hlavička -->
    <header class="bg-surface border-b border-neutral-200 px-4 py-3">
      <div class="max-w-2xl mx-auto flex items-center gap-3">
        <div class="w-8 h-8 bg-primary-600 rounded-md flex items-center justify-center text-white font-bold">M</div>
        <div class="text-sm">
          <div class="font-semibold">My<span class="text-primary-700">Invoice</span><span class="text-neutral-500">.cz</span></div>
          <div class="text-xs text-neutral-500">{{ tt('Schválení výkazu práce', 'Work report approval') }}</div>
        </div>
      </div>
    </header>

    <main class="flex-1 px-4 py-8">
      <div class="max-w-2xl mx-auto">

        <!-- Loading -->
        <div v-if="loading" class="text-center text-neutral-500 py-16">
          {{ tt('Načítám…', 'Loading…') }}
        </div>

        <!-- Token error -->
        <div v-else-if="loadError" class="bg-surface border border-danger-500/40 rounded-xl p-8 text-center shadow-sm">
          <div class="text-4xl mb-3">⚠</div>
          <h1 class="text-xl font-semibold mb-2">{{ tt('Odkaz není platný', 'Link not valid') }}</h1>
          <p class="text-neutral-600 text-sm">{{ loadError }}</p>
          <p class="text-xs text-neutral-500 mt-4">
            {{ tt('Pokud máte dotaz, kontaktujte odesílatele emailu.', 'If you have a question, please contact the sender.') }}
          </p>
        </div>

        <!-- Confirmation screen -->
        <div v-else-if="mode === 'done' && result" class="bg-surface border rounded-xl p-8 text-center shadow-sm"
          :class="result.decision === 'approved' ? 'border-success-500/40' : 'border-warning-500/40'">
          <div class="text-5xl mb-3">{{ result.decision === 'approved' ? '✓' : '✕' }}</div>
          <h1 class="text-2xl font-semibold mb-3"
            :class="result.decision === 'approved' ? 'text-success-600' : 'text-warning-600'">
            {{ result.decision === 'approved'
                ? tt('Schváleno', 'Approved')
                : tt('Zamítnuto', 'Rejected') }}
          </h1>
          <p class="text-neutral-700">{{ result.message }}</p>
          <p v-if="result.decision === 'approved'" class="text-sm text-neutral-500 mt-4">
            {{ tt('Faktura byla automaticky vystavena a odeslána.', 'The invoice has been issued and sent automatically.') }}
          </p>
          <p v-else class="text-sm text-neutral-500 mt-4">
            {{ tt('Dodavatel byl o zamítnutí informován.', 'The supplier has been informed of the rejection.') }}
          </p>
        </div>

        <!-- Review + actions -->
        <div v-else-if="data" class="space-y-4">
          <!-- Header card -->
          <div class="bg-surface border border-neutral-200 rounded-xl p-6 shadow-sm">
            <h1 class="text-xl font-semibold mb-2">
              {{ tt('Žádost o schválení výkazu práce', 'Work report approval request') }}
            </h1>
            <div class="text-sm text-neutral-600 space-y-0.5">
              <div v-if="data.supplier_name">
                <span class="text-neutral-500">{{ tt('Od', 'From') }}:</span>
                <strong class="text-neutral-900 ml-1">{{ data.supplier_name }}</strong>
              </div>
              <div v-if="data.invoice.client_company_name">
                <span class="text-neutral-500">{{ tt('Pro', 'For') }}:</span>
                <strong class="text-neutral-900 ml-1">{{ data.invoice.client_company_name }}</strong>
              </div>
              <div v-if="data.invoice.project_name">
                <span class="text-neutral-500">{{ tt('Zakázka', 'Project') }}:</span>
                <span class="ml-1">{{ data.invoice.project_name }}</span>
              </div>
            </div>
          </div>

          <!-- Work report card -->
          <div class="bg-surface border border-neutral-200 rounded-xl shadow-sm overflow-hidden">
            <header class="px-6 py-3 border-b border-neutral-200 flex items-baseline justify-between gap-3">
              <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
                {{ tt('Výkaz', 'Report') }}
              </h2>
              <span class="text-sm text-neutral-700">{{ data.work_report.title }}</span>
            </header>
            <div class="overflow-x-auto">
              <table class="w-full text-sm table-sticky-first">
                <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                  <tr>
                    <th class="px-4 py-2 text-left font-medium">{{ tt('Popis', 'Description') }}</th>
                    <th v-if="wrHasDates" class="px-3 py-2 text-left font-medium w-28">{{ tt('Datum', 'Date') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-24 whitespace-nowrap">{{ tt('Hodin', 'Hours') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-40 whitespace-nowrap">{{ tt('Sazba', 'Rate') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="it in data.work_report.items" :key="it.id">
                    <td class="px-4 py-2 whitespace-pre-wrap text-neutral-800">{{ it.description }}</td>
                    <td v-if="wrHasDates" class="px-3 py-2 whitespace-nowrap text-neutral-600">{{ fmtDate(it.work_date) }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtHours(it.hours) }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(it.rate, data.invoice.currency) }}</td>
                  </tr>
                  <tr class="bg-neutral-50 font-semibold">
                    <td class="px-4 py-2 text-right" :colspan="wrHasDates ? 2 : 1">{{ tt('Celkem', 'Total') }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtHours(data.work_report.total_hours) }} h</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(data.work_report.total_amount, data.invoice.currency) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Decided by email + actions -->
          <div class="bg-surface border border-neutral-200 rounded-xl p-6 shadow-sm">
            <p class="text-sm text-neutral-600 mb-4">
              {{ mode === 'reject_form'
                  ? tt('Uveďte prosím důvod zamítnutí. Dodavatel ho uvidí v systému.',
                       'Please provide the reason for rejection. The supplier will see it in the system.')
                  : tt('Po schválení Vám bude obratem zaslána faktura. Vše je automatizované — nemusíte na nic odpovídat.',
                       'After approval, an invoice will be sent to you immediately. Everything is automated — no reply needed.') }}
            </p>

            <!-- Decided by email + komentář (volitelné, pro audit) -->
            <div v-if="mode === 'review'" class="mb-4 space-y-3">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">
                  {{ tt('Váš email (volitelně, pro audit)', 'Your email (optional, for audit)') }}
                </label>
                <input v-model="decidedBy" type="email" :placeholder="tt('jana@firma.cz', 'jane@company.com')"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">
                  {{ tt('Komentář ke schválení (volitelně)', 'Comment with approval (optional)') }}
                </label>
                <textarea v-model="comment" rows="2"
                  :placeholder="tt('Např. „Schvaluji, prosím vystavit fakturu až 1.6.“', 'E.g. “Approved, but please issue the invoice on June 1.”')"
                  class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
                <p class="text-xs text-neutral-500 mt-1">
                  {{ tt('Při zamítnutí je důvod povinný v dalším kroku.', 'When rejecting, a reason will be required in the next step.') }}
                </p>
              </div>
            </div>

            <!-- Reject form -->
            <div v-if="mode === 'reject_form'" class="mb-4">
              <label class="block text-sm font-medium text-neutral-700 mb-1">
                {{ tt('Důvod zamítnutí', 'Rejection reason') }} *
              </label>
              <textarea v-model="reason" rows="4" required
                :placeholder="tt('Např. počet hodin u položky XY není správný…', 'E.g. the hours for item XY are incorrect…')"
                class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
            </div>

            <!-- CAPTCHA -->
            <div v-if="data.captcha_provider === 'turnstile'" class="mb-4 flex justify-center">
              <div ref="turnstileEl"></div>
            </div>

            <div v-if="submitError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500 mb-4">
              {{ submitError }}
            </div>

            <!-- Akce: review -->
            <div v-if="mode === 'review'" class="flex flex-col gap-3">
              <button @click="approve" :disabled="submitting"
                class="cursor-pointer w-full py-4 bg-success-600 hover:bg-success-500 disabled:bg-neutral-300 text-white text-lg font-bold rounded-lg shadow-sm transition">
                {{ submitting ? tt('Odesílám…', 'Submitting…') : tt('✓ Schválit vícepráce', '✓ Approve work report') }}
              </button>
              <button @click="startReject" :disabled="submitting"
                class="cursor-pointer w-full py-2.5 border border-neutral-300 text-neutral-700 hover:bg-neutral-50 disabled:opacity-50 text-sm font-medium rounded-md">
                {{ tt('Zamítnout', 'Reject') }}
              </button>
            </div>

            <!-- Akce: reject form -->
            <div v-else-if="mode === 'reject_form'" class="flex flex-col-reverse sm:flex-row gap-3 justify-end">
              <button @click="mode = 'review'" :disabled="submitting"
                class="cursor-pointer px-4 py-2.5 border border-neutral-300 text-neutral-700 hover:bg-neutral-50 text-sm rounded-md">
                {{ tt('Zpět', 'Back') }}
              </button>
              <button @click="submitReject" :disabled="submitting"
                class="cursor-pointer px-4 py-2.5 bg-warning-500 hover:bg-warning-600 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
                {{ submitting ? tt('Odesílám…', 'Submitting…') : tt('Potvrdit zamítnutí', 'Confirm rejection') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </main>

    <footer class="border-t border-neutral-200 bg-surface px-4 py-3 text-center text-xs text-neutral-500">
      MyInvoice.cz · {{ tt('Automatizovaný systém schvalování', 'Automated approval system') }}
    </footer>
  </div>
</template>

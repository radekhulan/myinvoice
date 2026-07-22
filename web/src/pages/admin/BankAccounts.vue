<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { bankNameByCode, isKnownBankName } from '@/utils/czBankCodes'
import {
  settingsApi,
  type BankEmailAccountMapping,
  type BankEmailImapSettings,
  type BankEmailProcessedMessage,
  type BankEmailProvider,
  type CurrencyAccount,
  type Supplier,
} from '@/api/settings'
import { clientsApi, type CrpDphAccount } from '@/api/clients'
import { bankApi, type AccountBalancesResponse } from '@/api/bank'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useChartColors } from '@/composables/useTheme'
import BalanceTrendChart from '@/components/charts/BalanceTrendChart.vue'

// embedded = vykresleno jako záložky uvnitř BankPage.vue (Finance → Bankovní účty);
// hlavičku a lištu záložek pak dodává obálka, aktivní tab řídí přes ?tab=.
defineProps<{ embedded?: boolean }>()

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()

type Tab = 'accounts' | 'balances' | 'email'
const VALID_TABS: Tab[] = ['accounts', 'balances', 'email']
function initialTab(): Tab {
  const q = String(route.query.tab || '')
  return (VALID_TABS as string[]).includes(q) ? (q as Tab) : 'accounts'
}
const tab = ref<Tab>(initialTab())
// Drž aktivní záložku v URL (?tab=…) — kvůli deep-linkům a zpětné navigaci.
watch(tab, (v) => {
  if (route.query.tab !== v) router.replace({ query: { ...route.query, tab: v } })
  if (v === 'balances') loadBalances()
})
// Obrácený směr (embedded): přepnutí záložky v obálce mění jen ?tab= → propiš dovnitř.
watch(() => route.query.tab, (q) => {
  const v = String(q || '')
  if ((VALID_TABS as string[]).includes(v) && tab.value !== v) tab.value = v as Tab
})

function tabLabel(tt: Tab): string {
  return tt === 'accounts' ? t('bank_accounts.tab_accounts')
    : tt === 'balances' ? t('bank_accounts.tab_balances')
    : t('bank_accounts.tab_email_notices')
}

// Záložka „Stavy na účtech" — lazy načtení zůstatků z GPC výpisů.
const chartColors = useChartColors()
const balances = ref<AccountBalancesResponse | null>(null)
const balancesLoading = ref(false)
const balancesLoaded = ref(false)
const balancesError = ref<string | null>(null)

async function loadBalances() {
  if (balancesLoaded.value || balancesLoading.value) return
  balancesLoading.value = true
  balancesError.value = null
  try {
    balances.value = await bankApi.accountBalances()
    balancesLoaded.value = true
  } catch (e) {
    balancesError.value = apiErrorMessage(e, t('bank_accounts.balances_load_failed'))
  } finally {
    balancesLoading.value = false
  }
}

const balanceMonthLabels = (a: { months: { month: string }[] }) => a.months.map(m => m.month)
const balanceMonthValues = (a: { months: { balance: number | null }[] }) => a.months.map(m => m.balance)
// Účty začínají oranžovou a zelenou, aby se navzájem i od fialové řady Celkem
// daly rychle rozlišit. Další účty pokračují kontrastní kategorickou paletou.
const accountColorOrder = [7, 9, 0, 8, 3, 5, 2, 4, 6, 1]
const accountColor = (i: number) => chartColors.value.palette[accountColorOrder[i % accountColorOrder.length]]
const totalBalanceDatasets = computed(() => {
  const data = balances.value
  if (!data) return []
  const accounts = data.total_czk.series.map((series, i) => ({
    label: series.label,
    values: series.months.map(m => m.balance_czk),
    color: accountColor(i),
  }))
  return [
    ...accounts,
    {
      label: t('bank_accounts.balances_total_series'),
      values: data.total_czk.months.map(m => m.balance_czk),
      color: chartColors.value.primary,
      fill: true,
      emphasis: true,
    },
  ]
})

// Načti hned, když stránka startuje rovnou na téhle záložce (deep-link ?tab=balances).
onMounted(() => { if (tab.value === 'balances') loadBalances() })

const supplier = ref<Supplier | null>(null)
const currencies = ref<CurrencyAccount[]>([])
const mappings = ref<BankEmailAccountMapping[]>([])
const providers = ref<BankEmailProvider[]>([])
const imapAccounts = ref<BankEmailImapSettings[]>([])
const messages = ref<BankEmailProcessedMessage[]>([])
const messagesTotal = ref(0)
const messagesPage = ref(1)
const messagesPerPage = ref(50)
const messagesTotalPages = computed(() => Math.max(1, Math.ceil(messagesTotal.value / messagesPerPage.value)))
const messagesFrom = computed(() => (messagesTotal.value === 0 ? 0 : (messagesPage.value - 1) * messagesPerPage.value + 1))
const messagesTo = computed(() => Math.min(messagesPage.value * messagesPerPage.value, messagesTotal.value))
const loading = ref(false)
const saving = ref(false)
const testingAccountId = ref<number | null>(null)
const browsingFolders = ref(false)
const scanning = ref(false)
const editingCurrency = ref<number | null>(null)
const editingCurrencyLabel = ref('')
const currencyFormOpen = ref(false)
const imapFormOpen = ref(false)
const editingImapId = ref<number | null>(null)
const providerFormOpen = ref(false)
const parserText = ref('')
const parserSender = ref('info@rb.cz')
const parserSubject = ref('Pohyb na účtě')
const parserProviderRef = ref<string | null>(null)
const parserResult = ref<Record<string, any> | null>(null)
const scanSummary = ref<Record<string, any> | null>(null)
const bankEmailLoadError = ref<string | null>(null)
const folderOptions = ref<string[]>([])

// CRPDPH (registr plátců DPH) → bankovní účet do editované měny
const bankDraftLoading = ref(false)
const bankDraftMsg = ref<{ type: 'success' | 'error' | 'warning'; text: string } | null>(null)
const bankDraftAccounts = ref<CrpDphAccount[]>([])
// Lookup z registru DPH má smysl jen když dodavatel má vyplněné DIČ (8–10 číslic).
const supplierHasDic = computed(() => /^\d{8,10}$/.test((supplier.value?.dic || '').replace(/\D/g, '')))

const currencyDraft = reactive<Partial<CurrencyAccount>>({})
// Auto-doplnění názvu banky podle kódu (číselník ČNB). Přepíše jen prázdný
// nebo z číselníku pocházející název — ručně zadaný text nepřepisuje.
watch(() => currencyDraft.bank_code, (code) => {
  const name = bankNameByCode(code)
  if (name && (!currencyDraft.bank_name || isKnownBankName(currencyDraft.bank_name))) {
    currencyDraft.bank_name = name
  }
})
const imapDraft = reactive<Partial<BankEmailImapSettings> & { password?: string }>(defaultImapDraft())
const regexFieldDefinitions = [
  { key: 'variable_symbol', required: true },
  { key: 'amount', required: true },
  { key: 'currency', required: true },
  { key: 'posted_at', required: true },
  { key: 'recipient_account', required: true },
  { key: 'counterparty_account', required: false },
  { key: 'counterparty_name', required: false },
  { key: 'constant_symbol', required: false },
  { key: 'message', required: false },
  { key: 'bank_ref', required: false },
  { key: 'balance', required: false },
] as const
type RegexFieldKey = typeof regexFieldDefinitions[number]['key']
interface RegexProviderDraft {
  id: number | null
  name: string
  code: string
  enabled: boolean
  sender_whitelist: string
  subject_pattern: string
  body_pattern: string
  field_patterns: Record<RegexFieldKey, string>
  normalizer_config_json: string
}
const providerDraft = reactive<RegexProviderDraft>(defaultRegexProviderDraft())

function defaultImapDraft(): Partial<BankEmailImapSettings> & { password?: string } {
  return {
    id: null,
    name: '',
    enabled: true,
    host: '',
    port: 993,
    encryption: 'ssl',
    validate_cert: true,
    require_email_auth: false,
    allow_forwarded: false,
    forwarded_from: '',
    email_auth_serv_id: '',
    username: '',
    password: '',
    folder: 'INBOX',
    max_messages_per_run: 50,
    process_from_date: null,
    success_action: 'none',
    success_flag: 'MyInvoiceProcessed',
    success_move_folder: '',
    failure_action: 'none',
    failure_flag: 'MyInvoiceFailed',
    failure_move_folder: '',
    retry_failed: false,
    max_attempts: 3,
  }
}

function defaultFieldPatterns(): Record<RegexFieldKey, string> {
  return regexFieldDefinitions.reduce((acc, field) => {
    acc[field.key] = ''
    return acc
  }, {} as Record<RegexFieldKey, string>)
}

function defaultRegexProviderDraft(): RegexProviderDraft {
  return {
    id: null,
    name: '',
    code: '',
    enabled: true,
    sender_whitelist: '',
    subject_pattern: '',
    body_pattern: '',
    field_patterns: defaultFieldPatterns(),
    normalizer_config_json: '{}',
  }
}

function fieldLabel(key: RegexFieldKey): string {
  return t(`bank_accounts.field_${key}`)
}

async function load() {
  loading.value = true
  try {
    bankEmailLoadError.value = null
    const [supplierResult, currenciesResult, overviewResult] = await Promise.allSettled([
      settingsApi.getSupplier(),
      settingsApi.listCurrencies(),
      settingsApi.getBankEmailOverview(),
    ])
    if (supplierResult.status === 'fulfilled') {
      supplier.value = supplierResult.value
    }
    if (currenciesResult.status === 'fulfilled') {
      currencies.value = currenciesResult.value
    } else {
      toast.error(apiErrorMessage(currenciesResult.reason, t('bank_accounts.load_currencies_failed')))
    }
    if (overviewResult.status === 'fulfilled') {
      const overview = overviewResult.value
      mappings.value = overview.mappings.map(normalizeMappingForUi)
      providers.value = overview.providers
      imapAccounts.value = overview.imap_accounts ?? (overview.imap?.id ? [overview.imap] : [])
      messages.value = overview.messages
      messagesTotal.value = overview.messages_total ?? overview.messages.length
      messagesPage.value = 1
    } else {
      bankEmailLoadError.value = apiErrorMessage(overviewResult.reason, t('bank_accounts.load_config_failed'))
      mappings.value = []
      providers.value = []
      imapAccounts.value = []
      messages.value = []
      messagesTotal.value = 0
    }
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function loadMessagesPage(p: number) {
  const np = Math.min(Math.max(1, p), messagesTotalPages.value)
  const r = await settingsApi.listBankEmailMessages(np)
  messages.value = r.items
  messagesTotal.value = r.total
  messagesPerPage.value = r.limit
  messagesPage.value = r.page
}

function startEditCurrency(c: CurrencyAccount) {
  editingCurrency.value = c.id
  editingCurrencyLabel.value = c.label
  bankDraftMsg.value = null
  bankDraftAccounts.value = []
  currencyFormOpen.value = true
  Object.assign(currencyDraft, { ...c })
}

async function saveCurrency() {
  const payload: Partial<CurrencyAccount> = {
    label: currencyDraft.label,
    symbol: currencyDraft.symbol,
    decimals: currencyDraft.decimals,
    is_active: currencyDraft.is_active,
    is_default: currencyDraft.is_default,
    account_number: currencyDraft.account_number || null,
    bank_code: currencyDraft.bank_code || null,
    bank_name: currencyDraft.bank_name || null,
    iban: currencyDraft.iban || null,
    bic: currencyDraft.bic || null,
  }
  if (editingCurrency.value === null && String(currencyDraft.code || '').trim().length !== 3) {
    toast.error(t('bank_accounts.currency_code_invalid'))
    return
  }
  try {
    if (editingCurrency.value !== null) {
      await settingsApi.updateCurrency(editingCurrency.value, payload)
      toast.success(t('bank_accounts.account_saved'))
    } else {
      await settingsApi.createCurrency({ ...payload, code: String(currencyDraft.code || '').toUpperCase() })
      toast.success(t('bank_accounts.account_added'))
    }
    closeCurrencyForm()
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

function startNewCurrencyAccount() {
  bankDraftMsg.value = null
  bankDraftAccounts.value = []
  Object.assign(currencyDraft, {
    id: undefined,
    code: '',
    label: '',
    symbol: '',
    decimals: 2,
    is_active: true,
    is_default: false,
    account_number: null,
    bank_code: null,
    bank_name: null,
    iban: null,
    bic: null,
  })
  editingCurrency.value = null
  editingCurrencyLabel.value = ''
  currencyFormOpen.value = true
}

function closeCurrencyForm() {
  editingCurrency.value = null
  editingCurrencyLabel.value = ''
  currencyFormOpen.value = false
  bankDraftMsg.value = null
  bankDraftAccounts.value = []
  Object.keys(currencyDraft).forEach(key => delete currencyDraft[key as keyof CurrencyAccount])
}

function applyBankAccount(acc: CrpDphAccount) {
  if (acc.iban) {
    currencyDraft.iban = acc.iban
  } else {
    currencyDraft.account_number = acc.prefix ? `${acc.prefix}-${acc.number}` : acc.number
    currencyDraft.bank_code = acc.bank_code
  }
}

async function loadBankToDraft() {
  const dic = (supplier.value?.dic || '').replace(/\D/g, '')
  if (!/^\d{8,10}$/.test(dic)) {
    bankDraftMsg.value = { type: 'error', text: t('supplier.bank_lookup_no_dic') }
    return
  }
  bankDraftLoading.value = true
  bankDraftMsg.value = null
  bankDraftAccounts.value = []
  try {
    const r = await clientsApi.lookupBank(dic)
    if (r.accounts.length === 0) {
      bankDraftMsg.value = { type: 'error', text: t('supplier.bank_lookup_none') }
    } else {
      bankDraftAccounts.value = r.accounts
      // Preferuj účet odpovídající editované měně: CZK → standardní účet, jinak IBAN.
      const isCzk = (currencyDraft.code || '').toUpperCase() === 'CZK'
      const acc = (isCzk ? r.accounts.find(a => !a.iban) : r.accounts.find(a => a.iban)) || r.accounts[0]
      applyBankAccount(acc)
      bankDraftMsg.value = {
        type: 'success',
        text: r.accounts.length === 1
          ? t('supplier.bank_lookup_one')
          : t('supplier.bank_lookup_many', { n: r.accounts.length }),
      }
    }
    if (r.unreliable === true) bankDraftMsg.value = { type: 'warning', text: t('supplier.bank_lookup_unreliable') }
  } catch (e: any) {
    bankDraftMsg.value = { type: 'error', text: e?.response?.data?.error?.message || t('supplier.bank_lookup_failed') }
  } finally {
    bankDraftLoading.value = false
  }
}

async function removeCurrency(c: CurrencyAccount) {
  if (!window.confirm(t('bank_accounts.delete_account_confirm', { label: c.label }))) return
  try {
    await settingsApi.deleteCurrency(c.id)
    toast.success(t('bank_accounts.account_deleted'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

async function saveMappings() {
  try {
    await settingsApi.updateBankEmailMappings(mappings.value.map(m => ({
      currency_id: m.currency_id,
      imap_account_id: m.imap_account_id === 0 ? null : m.imap_account_id,
      provider_ref: m.provider_ref,
      enabled: m.imap_account_id === 0 ? false : m.enabled,
      amount_tolerance: m.amount_tolerance,
    })))
    toast.success(t('bank_accounts.mappings_saved'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

function normalizeMappingForUi(mapping: BankEmailAccountMapping): BankEmailAccountMapping {
  if (mapping.imap_account_id === null && !mapping.enabled) {
    return { ...mapping, imap_account_id: 0 }
  }
  return mapping
}

function onMappingImapChange(mapping: BankEmailAccountMapping, value: string) {
  if (value === '0') {
    mapping.enabled = false
  }
}

function startNewImapAccount() {
  Object.assign(imapDraft, defaultImapDraft(), { name: t('bank_accounts.new_imap_default_name') })
  editingImapId.value = null
  folderOptions.value = []
  imapFormOpen.value = true
}

function startEditImapAccount(account: BankEmailImapSettings) {
  Object.assign(imapDraft, { ...account, password: '' })
  editingImapId.value = account.id
  folderOptions.value = []
  imapFormOpen.value = true
}

function closeImapForm() {
  Object.assign(imapDraft, defaultImapDraft())
  editingImapId.value = null
  folderOptions.value = []
  imapFormOpen.value = false
}

async function saveImapAccount() {
  saving.value = true
  try {
    if (editingImapId.value !== null) {
      await settingsApi.updateBankEmailImapAccount(editingImapId.value, imapDraft)
      toast.success(t('bank_accounts.imap_saved'))
    } else {
      await settingsApi.createBankEmailImapAccount(imapDraft)
      toast.success(t('bank_accounts.imap_created'))
    }
    closeImapForm()
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('bank_accounts.imap_save_failed')))
  } finally {
    saving.value = false
  }
}

async function testImapAccount(account: BankEmailImapSettings) {
  if (account.id === null) return
  testingAccountId.value = account.id
  try {
    const r = await settingsApi.testBankEmailImapAccount(account.id)
    toast.success(`${account.name}: ${r.message}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.message || apiErrorMessage(e, t('bank_accounts.imap_test_failed')))
  } finally {
    testingAccountId.value = null
  }
}

async function browseImapFolders() {
  browsingFolders.value = true
  folderOptions.value = []
  try {
    const result = await settingsApi.browseBankEmailImapFolders(imapDraft, editingImapId.value)
    folderOptions.value = result.folders ?? []
    if (folderOptions.value.length > 0) {
      toast.success(t('bank_accounts.folders_loaded', { count: folderOptions.value.length }))
    } else {
      toast.info(t('bank_accounts.folders_none'))
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message || apiErrorMessage(e, t('bank_accounts.folders_failed')))
  } finally {
    browsingFolders.value = false
  }
}

function selectImapFolder(folder: string) {
  imapDraft.folder = folder
  folderOptions.value = []
}

async function deleteImapAccount(account: BankEmailImapSettings) {
  if (account.id === null) return
  if (!window.confirm(t('bank_accounts.delete_imap_confirm', { name: account.name }))) return
  await settingsApi.deleteBankEmailImapAccount(account.id)
  toast.success(t('bank_accounts.imap_deleted'))
  await load()
}

async function runScan() {
  scanning.value = true
  try {
    scanSummary.value = await settingsApi.scanBankEmailNotices()
    toast.success(t('bank_accounts.scan_done'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('bank_accounts.scan_failed')))
  } finally {
    scanning.value = false
  }
}

function providerOwnerLabel(provider: BankEmailProvider): string {
  return provider.supplier_id === null ? t('bank_accounts.owner_system') : t('bank_accounts.owner_supplier')
}

// Jen 'regex' je editovatelný/duplikovatelný uživatelský provider; ostatní jsou
// vestavěné kódové parsery konkrétní banky (needitují se). Fio dřív v tomhle
// výčtu chybělo a propadalo na „Regex", takže v tabulce vypadalo stejně jako
// editovatelný regex provider ČS — proto má teď taky vlastní název a neznámé
// typy padají na obecný „Vestavěný parser", ne na „Regex".
function parserTypeLabel(parserType: BankEmailProvider['parser_type']): string {
  switch (parserType) {
    case 'regex': return 'Regex'
    case 'raiffeisenbank': return 'Raiffeisenbank'
    case 'unicredit': return 'UniCredit Bank'
    case 'csob': return 'ČSOB'
    case 'fio': return 'Fio banka'
    case 'creditas': return 'Creditas'
    case 'moneta': return 'MONETA Money Bank'
    default: return t('bank_accounts.parser_builtin')
  }
}

function providerSelectLabel(provider: BankEmailProvider): string {
  const base = `${provider.name} (${providerOwnerLabel(provider)})`
  return provider.enabled ? base : `${base} — ${t('bank_accounts.provider_disabled')}`
}

// Mapování nabízí jen zapnuté providery; aktuálně vybraný vypnutý zůstává
// viditelný (se suffixem), aby uložené mapování tiše nezmizelo ze selectu.
function mappingProviderOptions(mapping: BankEmailAccountMapping): BankEmailProvider[] {
  return providers.value.filter(p => p.enabled || p.provider_ref === mapping.provider_ref)
}

async function testParser() {
  parserResult.value = null
  try {
    const r = await settingsApi.testBankEmailParser({
      provider_ref: parserProviderRef.value,
      sender: parserSender.value,
      subject: parserSubject.value,
      text: parserText.value,
    })
    parserResult.value = r.parsed
    toast.success(t('bank_accounts.parser_label', { name: r.provider.name }))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('bank_accounts.parser_failed')))
  }
}

function providerCodeFromName(name: string): string {
  return name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'provider'
}

function startNewRegexProvider() {
  Object.assign(providerDraft, defaultRegexProviderDraft())
  providerFormOpen.value = true
}

function startEditProvider(provider: BankEmailProvider) {
  if (provider.id === null || provider.supplier_id === null || provider.parser_type !== 'regex') return
  const patterns = defaultFieldPatterns()
  for (const field of regexFieldDefinitions) {
    patterns[field.key] = String(provider.field_patterns?.[field.key] ?? '')
  }
  Object.assign(providerDraft, {
    id: provider.id,
    name: provider.name,
    code: provider.code,
    enabled: provider.enabled,
    sender_whitelist: provider.sender_whitelist ?? '',
    subject_pattern: provider.subject_pattern ?? '',
    body_pattern: provider.body_pattern ?? '',
    field_patterns: patterns,
    normalizer_config_json: JSON.stringify(provider.normalizer_config ?? {}, null, 2),
  })
  providerFormOpen.value = true
}

// Duplikát libovolného (i systémového/globálního) regex provideru jako nový,
// editovatelný provider dodavatele. Systémový ČS provider sám editovat nejde,
// ale takhle si z něj uživatel udělá vlastní kopii a doladí ji (např. smaže
// body_pattern, zvolní diakritiku) a otestuje přes „Test parseru" (#158).
function startCloneProvider(provider: BankEmailProvider) {
  if (provider.parser_type !== 'regex') return
  const patterns = defaultFieldPatterns()
  for (const field of regexFieldDefinitions) {
    patterns[field.key] = String(provider.field_patterns?.[field.key] ?? '')
  }
  Object.assign(providerDraft, {
    id: null, // null → uloží se jako nový provider, originál zůstane netknutý
    name: `${provider.name} ${t('bank_accounts.provider_copy_suffix')}`,
    code: '',
    enabled: provider.enabled,
    sender_whitelist: provider.sender_whitelist ?? '',
    subject_pattern: provider.subject_pattern ?? '',
    body_pattern: provider.body_pattern ?? '',
    field_patterns: patterns,
    normalizer_config_json: JSON.stringify(provider.normalizer_config ?? {}, null, 2),
  })
  syncProviderCode() // dogeneruje unikátní code z názvu kopie
  providerFormOpen.value = true
}

function closeProviderForm() {
  Object.assign(providerDraft, defaultRegexProviderDraft())
  providerFormOpen.value = false
}

function syncProviderCode() {
  if (providerDraft.id !== null || providerDraft.code.trim() !== '') return
  providerDraft.code = providerCodeFromName(providerDraft.name)
}

async function saveProvider() {
  let normalizerConfig: Record<string, unknown>
  try {
    normalizerConfig = JSON.parse(providerDraft.normalizer_config_json || '{}')
  } catch {
    toast.error(t('bank_accounts.normalizer_invalid'))
    return
  }
  if (normalizerConfig === null || Array.isArray(normalizerConfig) || typeof normalizerConfig !== 'object') {
    toast.error(t('bank_accounts.normalizer_invalid'))
    return
  }

  const fieldPatterns: Record<string, string> = {}
  for (const field of regexFieldDefinitions) {
    const value = providerDraft.field_patterns[field.key].trim()
    if (value !== '') {
      fieldPatterns[field.key] = value
    }
  }

  const payload: Partial<BankEmailProvider> = {
    name: providerDraft.name.trim(),
    code: providerDraft.code.trim() || providerCodeFromName(providerDraft.name),
    parser_type: 'regex',
    enabled: providerDraft.enabled,
    sender_whitelist: providerDraft.sender_whitelist.trim() || null,
    subject_pattern: providerDraft.subject_pattern.trim() || null,
    body_pattern: providerDraft.body_pattern.trim() || null,
    field_patterns: fieldPatterns,
    normalizer_config: normalizerConfig,
  }

  try {
    if (providerDraft.id !== null) {
      await settingsApi.updateBankEmailProvider(providerDraft.id, payload)
      toast.success(t('bank_accounts.provider_saved'))
    } else {
      await settingsApi.createBankEmailProvider(payload)
      toast.success(t('bank_accounts.provider_created'))
    }
    closeProviderForm()
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('bank_accounts.provider_save_failed')))
  }
}

async function removeProvider(provider: BankEmailProvider) {
  if (provider.id === null || provider.supplier_id === null) return
  if (!window.confirm(t('bank_accounts.delete_provider_confirm', { name: provider.name }))) return
  try {
    await settingsApi.deleteBankEmailProvider(provider.id)
    toast.success(t('bank_accounts.provider_deleted'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

async function deleteMessage(m: BankEmailProcessedMessage) {
  if (!window.confirm(t('bank_accounts.delete_message_confirm', { id: m.id }))) return
  try {
    await settingsApi.deleteBankEmailMessage(m.id)
    toast.success(t('bank_accounts.message_deleted'))
    await loadMessagesPage(messagesPage.value)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}
</script>

<template>
  <div>
    <div v-if="!embedded" class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('bank_accounts.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('bank_accounts.subtitle') }}</p>
    </div>

    <div v-if="loading" class="text-sm text-neutral-500">{{ t('bank_accounts.loading') }}</div>

    <div v-else>
      <!-- Záložky ve stylu Číselníků / admin/emails; v embedded režimu je dodává BankPage. -->
      <div v-if="!embedded" class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
        <button v-for="tt in VALID_TABS" :key="tt"
          @click="tab = tt"
          class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
          :class="tab === tt
            ? 'border-primary-600 text-primary-700 font-medium'
            : 'border-transparent text-neutral-600 hover:text-neutral-900'">
          {{ tabLabel(tt) }}
        </button>
      </div>

      <div v-show="tab === 'accounts'" class="space-y-5">
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="min-w-0">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('bank_accounts.currencies_title') }}</h2>
            <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank_accounts.currencies_subtitle') }}</p>
          </div>
          <button type="button" @click="startNewCurrencyAccount()"
            class="cursor-pointer shrink-0 self-start sm:self-auto whitespace-nowrap inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
            + {{ t('bank_accounts.new_account') }}
          </button>
        </header>

        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_currency') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_account_cz') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_iban') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_bic') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('bank_accounts.th_default') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('bank_accounts.th_active') }}</th>
                <th class="px-3 py-2 text-right"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-if="currencies.length === 0">
                <td colspan="8" class="px-3 py-4 text-sm text-neutral-500">
                  {{ t('bank_accounts.currencies_empty') }}
                </td>
              </tr>
              <tr v-for="c in currencies" :key="c.id">
                <td class="px-3 py-2 font-mono">{{ c.code }} <span class="text-xs text-neutral-500">{{ c.symbol }}</span></td>
                <td class="px-3 py-2">{{ c.label }}</td>
                <td class="px-3 py-2 font-mono text-xs">
                  {{ c.account_number || '—' }}<span v-if="c.bank_code"> / {{ c.bank_code }}</span>
                </td>
                <td class="px-3 py-2 font-mono text-xs">{{ c.iban || '—' }}</td>
                <td class="px-3 py-2 font-mono text-xs">{{ c.bic || '—' }}</td>
                <td class="px-3 py-2 text-center">
                  <span v-if="c.is_default" class="text-primary-600">✓</span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-center">
                  <span v-if="c.is_active" class="text-success-600">✓</span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <button type="button" @click="startEditCurrency(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs">{{ t('common.edit') }}</button>
                  <button v-if="(c.invoices_count ?? 0) === 0" type="button" @click="removeCurrency(c)"
                    class="cursor-pointer text-danger-600 hover:text-danger-700 text-xs ml-2">{{ t('common.delete') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-if="currencies.length === 0" class="px-4 py-4 text-sm text-neutral-500">{{ t('bank_accounts.currencies_empty') }}</div>
          <div v-for="c in currencies" :key="`m-${c.id}`" class="p-3 space-y-1.5">
            <div class="flex items-baseline gap-2 min-w-0">
              <span class="font-mono font-semibold">{{ c.code }}</span>
              <span class="text-xs text-neutral-500">{{ c.symbol }}</span>
              <span class="text-xs text-neutral-500 truncate">· {{ c.label }}</span>
            </div>
            <div class="font-mono text-xs text-neutral-600 break-all">
              <span v-if="c.account_number">{{ c.account_number }}<span v-if="c.bank_code"> / {{ c.bank_code }}</span></span>
              <span v-else-if="c.iban">{{ c.iban }}</span>
              <span v-else class="text-neutral-400">—</span>
              <span v-if="c.bic" class="text-neutral-400"> · {{ c.bic }}</span>
            </div>
            <div class="flex items-center justify-between gap-2 text-xs">
              <span>
                <span v-if="c.is_default" class="text-primary-600">✓ {{ t('bank_accounts.th_default') }}</span>
                <span v-if="c.is_default && c.is_active" class="text-neutral-400 mx-1.5">·</span>
                <span v-if="c.is_active" class="text-success-600">✓ {{ t('bank_accounts.th_active') }}</span>
              </span>
              <div class="flex gap-2">
                <button type="button" @click="startEditCurrency(c)" class="cursor-pointer h-8 px-3 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded">{{ t('common.edit') }}</button>
                <button v-if="(c.invoices_count ?? 0) === 0" type="button" @click="removeCurrency(c)"
                  class="cursor-pointer h-8 px-3 text-xs border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded">{{ t('common.delete') }}</button>
              </div>
            </div>
          </div>
        </div>

        <div class="px-5 py-3 border-t border-neutral-200 bg-neutral-50 text-xs text-neutral-600">
          {{ t('bank_accounts.multi_account_hint') }}
        </div>
      </section>
      </div>

      <!-- Stavy na účtech — tabulka + měsíční vývoj + celkový CZK graf (dle GPC výpisů) -->
      <div v-show="tab === 'balances'" class="space-y-5">
        <div v-if="balancesLoading" class="text-sm text-neutral-500">{{ t('bank_accounts.balances_loading') }}</div>
        <div v-else-if="balancesError" class="bg-warning-50 border border-warning-200 text-warning-700 rounded-lg px-4 py-3 text-sm">
          {{ balancesError }}
        </div>
        <template v-else-if="balances">
          <!-- Přehled účtů + aktuální stav -->
          <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
            <header class="px-5 py-3 border-b border-neutral-200">
              <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('bank_accounts.balances_title') }}</h2>
              <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank_accounts.balances_subtitle') }}</p>
            </header>

            <div v-if="balances.accounts.length === 0" class="px-5 py-4 text-sm text-neutral-500">
              {{ t('bank_accounts.balances_empty') }}
            </div>

            <template v-else>
              <!-- Desktop: tabulka -->
              <div class="hidden md:block overflow-x-auto">
                <table class="w-full text-sm table-sticky-first">
                  <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                    <tr>
                      <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.balances_th_account') }}</th>
                      <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_currency') }}</th>
                      <th class="px-3 py-2 text-right font-medium">{{ t('bank_accounts.balances_th_current') }}</th>
                      <th class="px-3 py-2 text-right font-medium">{{ t('bank_accounts.balances_th_current_czk') }}</th>
                      <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.balances_th_as_of') }}</th>
                      <th class="px-3 py-2 text-right font-medium">{{ t('bank_accounts.balances_th_statements') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-neutral-100">
                    <tr v-for="(a, i) in balances.accounts" :key="a.id">
                      <td class="px-3 py-2">
                        <div class="flex items-center gap-2">
                          <span class="inline-block w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: accountColor(i) }"></span>
                          <div class="min-w-0">
                            <div class="font-medium truncate">{{ a.label }}</div>
                            <div class="text-xs text-neutral-500 font-mono">
                              {{ a.account_number }}<span v-if="a.bank_code"> / {{ a.bank_code }}</span>
                            </div>
                          </div>
                        </div>
                      </td>
                      <td class="px-3 py-2 font-mono">{{ a.code }}</td>
                      <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(a.current_balance, a.code) }}</td>
                      <td class="px-3 py-2 text-right font-mono whitespace-nowrap text-neutral-600">
                        {{ a.current_balance_czk !== null ? formatMoney(a.current_balance_czk, 'CZK') : '—' }}
                      </td>
                      <td class="px-3 py-2 text-xs whitespace-nowrap">
                        {{ formatDate(a.statement_date) }}
                        <span v-if="a.current_source === 'email_notice'" :title="t('bank_accounts.balances_source_email_hint')"
                          class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500 font-medium">
                          {{ t('bank_accounts.balances_source_email') }}
                        </span>
                      </td>
                      <td class="px-3 py-2 text-right text-xs">{{ a.statement_count }}</td>
                    </tr>
                  </tbody>
                  <tfoot class="bg-neutral-50 border-t border-neutral-200">
                    <tr>
                      <td class="px-3 py-2 font-medium" colspan="3">{{ t('bank_accounts.balances_total_czk') }}</td>
                      <td class="px-3 py-2 text-right font-mono font-semibold whitespace-nowrap">{{ formatMoney(balances.total_czk.current, 'CZK') }}</td>
                      <td class="px-3 py-2" colspan="2"></td>
                    </tr>
                  </tfoot>
                </table>
              </div>

              <!-- Mobile: karty -->
              <div class="md:hidden divide-y divide-neutral-100">
                <div v-for="(a, i) in balances.accounts" :key="`mb-${a.id}`" class="p-3 space-y-1.5">
                  <div class="flex items-center gap-2 min-w-0">
                    <span class="inline-block w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: accountColor(i) }"></span>
                    <span class="font-medium truncate">{{ a.label }}</span>
                    <span class="font-mono text-xs text-neutral-500 ml-auto">{{ a.code }}</span>
                  </div>
                  <div class="font-mono text-xs text-neutral-500 break-all">{{ a.account_number }}<span v-if="a.bank_code"> / {{ a.bank_code }}</span></div>
                  <div class="flex items-baseline justify-between gap-2">
                    <span class="font-mono font-semibold">{{ formatMoney(a.current_balance, a.code) }}</span>
                    <span v-if="a.current_balance_czk !== null && a.code !== 'CZK'" class="font-mono text-xs text-neutral-500">≈ {{ formatMoney(a.current_balance_czk, 'CZK') }}</span>
                  </div>
                  <div class="text-xs text-neutral-500">
                    {{ t('bank_accounts.balances_th_as_of') }}: {{ formatDate(a.statement_date) }}
                    <span v-if="a.current_source === 'email_notice'" :title="t('bank_accounts.balances_source_email_hint')"
                      class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500 font-medium">
                      {{ t('bank_accounts.balances_source_email') }}
                    </span>
                  </div>
                </div>
                <div class="p-3 flex items-baseline justify-between bg-neutral-50">
                  <span class="font-medium">{{ t('bank_accounts.balances_total_czk') }}</span>
                  <span class="font-mono font-semibold">{{ formatMoney(balances.total_czk.current, 'CZK') }}</span>
                </div>
              </div>
            </template>

            <div v-if="balances.missing_rates.length > 0" class="px-5 py-2 text-xs text-warning-700 bg-warning-50 border-t border-warning-200">
              {{ t('bank_accounts.balances_missing_rates', { codes: balances.missing_rates.join(', ') }) }}
            </div>
          </section>

          <!-- Měsíční vývoj — malý graf na každý účet -->
          <section v-if="balances.accounts.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
            <header class="px-5 py-3 border-b border-neutral-200">
              <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('bank_accounts.balances_monthly_title') }}</h2>
              <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank_accounts.balances_monthly_subtitle') }}</p>
            </header>
            <div class="p-5 grid gap-5 sm:grid-cols-2">
              <div v-for="(a, i) in balances.accounts" :key="`ch-${a.id}`" class="border border-neutral-200 rounded-lg p-3">
                <div class="flex items-center gap-2 mb-2">
                  <span class="inline-block w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: accountColor(i) }"></span>
                  <span class="text-sm font-medium truncate">{{ a.label }}</span>
                  <span class="text-xs text-neutral-500 font-mono ml-auto">{{ a.code }}</span>
                </div>
                <div class="h-40">
                  <BalanceTrendChart :labels="balanceMonthLabels(a)" :values="balanceMonthValues(a)" :currency="a.code" :color="accountColor(i)" />
                </div>
              </div>
            </div>
          </section>

          <!-- Celkový graf všech účtů přepočtený na CZK -->
          <section v-if="balances.total_czk.months.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
            <header class="px-5 py-3 border-b border-neutral-200">
              <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('bank_accounts.balances_total_title') }}</h2>
              <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank_accounts.balances_total_subtitle') }}</p>
            </header>
            <div class="p-5">
              <div class="h-72">
                <BalanceTrendChart
                  :labels="balances.total_czk.months.map(m => m.month)"
                  :datasets="totalBalanceDatasets"
                  currency="CZK" />
              </div>
            </div>
          </section>
        </template>
      </div>

      <!-- E-mailová bankovní avíza (IMAP) — vlastní záložka, vždy rozbalená -->
      <div v-show="tab === 'email'" class="space-y-5">
      <div v-if="bankEmailLoadError" class="bg-warning-50 border border-warning-200 text-warning-700 rounded-lg px-4 py-3 text-sm">
        {{ bankEmailLoadError }}
      </div>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="min-w-0">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('bank_accounts.mappings_title') }}</h2>
            <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank_accounts.mappings_subtitle') }}</p>
          </div>
          <button type="button" @click="saveMappings"
            class="cursor-pointer shrink-0 self-start sm:self-auto whitespace-nowrap h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
            {{ t('bank_accounts.save_mappings') }}
          </button>
        </header>

        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_bank_account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_imap_account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_parser') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_tolerance') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('bank_accounts.th_active') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-if="mappings.length === 0">
                <td colspan="5" class="px-3 py-4 text-sm text-neutral-500">
                  {{ t('bank_accounts.mappings_empty') }}
                </td>
              </tr>
              <tr v-for="mapping in mappings" :key="mapping.currency_id">
                <td class="px-3 py-2">
                  <div class="font-medium">{{ mapping.label }}</div>
                  <div class="text-xs text-neutral-500">
                    <span class="font-mono">{{ mapping.currency_code }}</span>
                    <span v-if="mapping.account_number" class="font-mono">
                      · {{ mapping.account_number }}<span v-if="mapping.bank_code"> / {{ mapping.bank_code }}</span>
                    </span>
                    <span v-if="mapping.bank_name"> · {{ mapping.bank_name }}</span>
                  </div>
                </td>
                <td class="px-3 py-2">
                  <select v-model.number="mapping.imap_account_id" @change="onMappingImapChange(mapping, ($event.target as HTMLSelectElement).value)"
                    class="h-9 w-56 px-2 bg-surface border border-neutral-300 rounded-md text-sm">
                    <option :value="0">{{ t('bank_accounts.imap_none') }}</option>
                    <option :value="null">{{ t('bank_accounts.imap_all') }}</option>
                    <option v-for="account in imapAccounts" :key="account.id ?? account.name" :value="account.id">
                      {{ account.name }}
                    </option>
                  </select>
                </td>
                <td class="px-3 py-2">
                  <select v-model="mapping.provider_ref"
                    class="h-9 w-56 px-2 bg-surface border border-neutral-300 rounded-md text-sm">
                    <option :value="null">{{ t('bank_accounts.provider_auto') }}</option>
                    <option v-for="p in mappingProviderOptions(mapping)" :key="p.provider_ref" :value="p.provider_ref">
                      {{ providerSelectLabel(p) }}
                    </option>
                  </select>
                </td>
                <td class="px-3 py-2">
                  <input v-model.number="mapping.amount_tolerance" type="number" min="0" step="0.01"
                    class="h-9 w-28 px-2 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
                </td>
                <td class="px-3 py-2 text-center">
                  <input v-model="mapping.enabled" type="checkbox" :disabled="mapping.imap_account_id === 0"
                    class="rounded border-neutral-300 text-primary-600 disabled:opacity-40" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="min-w-0">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('bank_accounts.imap_title') }}</h2>
            <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank_accounts.imap_subtitle') }}</p>
          </div>
          <button type="button" @click="startNewImapAccount"
            class="cursor-pointer shrink-0 self-start sm:self-auto whitespace-nowrap h-9 px-3 bg-surface border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
            {{ t('bank_accounts.new_imap') }}
          </button>
        </header>

        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_name') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_server') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_folder') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_limit') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_last_scan') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('bank_accounts.th_active') }}</th>
                <th class="px-3 py-2 text-right"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-if="imapAccounts.length === 0">
                <td colspan="7" class="px-3 py-4 text-sm text-neutral-500">{{ t('bank_accounts.imap_empty') }}</td>
              </tr>
              <tr v-for="account in imapAccounts" :key="account.id ?? account.name">
                <td class="px-3 py-2">
                  <div class="font-medium">{{ account.name }}</div>
                  <div class="text-xs text-neutral-500">{{ account.username || t('bank_accounts.no_username') }}</div>
                </td>
                <td class="px-3 py-2 font-mono text-xs">{{ account.host || '—' }}<span v-if="account.port">:{{ account.port }}</span></td>
                <td class="px-3 py-2">{{ account.folder || 'INBOX' }}</td>
                <td class="px-3 py-2">{{ account.max_messages_per_run }}</td>
                <td class="px-3 py-2 text-xs">
                  <div>{{ account.last_scan_at || '—' }}</div>
                  <div v-if="account.last_scan_status" :class="account.last_scan_status === 'ok' ? 'text-success-600' : 'text-danger-600'">
                    {{ account.last_scan_status }}<span v-if="account.last_scan_message"> · {{ account.last_scan_message }}</span>
                  </div>
                </td>
                <td class="px-3 py-2 text-center">{{ account.enabled ? t('common.yes') : t('common.no') }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <button type="button" @click="testImapAccount(account)" :disabled="testingAccountId === account.id"
                    class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs disabled:opacity-50">
                    {{ testingAccountId === account.id ? t('bank_accounts.testing') : t('bank_accounts.test') }}
                  </button>
                  <button type="button" @click="startEditImapAccount(account)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs ml-2">{{ t('common.edit') }}</button>
                  <button type="button" @click="deleteImapAccount(account)" class="cursor-pointer text-danger-600 hover:text-danger-700 text-xs ml-2">{{ t('common.delete') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="imapFormOpen" class="border-t border-neutral-200 p-5">
          <h3 class="text-sm font-semibold mb-3">{{ editingImapId === null ? t('bank_accounts.new_imap') : t('bank_accounts.edit_imap') }}</h3>
          <div class="grid md:grid-cols-3 gap-4">
            <label class="flex items-center gap-2 text-sm md:col-span-3">
              <input v-model="imapDraft.enabled" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('bank_accounts.imap_enable') }}
            </label>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.th_name') }}</label>
              <input v-model="imapDraft.name" type="text" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.host') }}</label>
              <input v-model="imapDraft.host" type="text" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.port') }}</label>
              <input v-model.number="imapDraft.port" type="number" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.encryption') }}</label>
              <select v-model="imapDraft.encryption" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm">
                <option value="ssl">{{ t('bank_accounts.enc_ssl') }}</option>
                <option value="tls">{{ t('bank_accounts.enc_tls') }}</option>
                <option value="none">{{ t('bank_accounts.enc_none') }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.username') }}</label>
              <input v-model="imapDraft.username" type="text" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.password') }}</label>
              <input v-model="imapDraft.password" type="password" :placeholder="imapDraft.has_password ? t('bank_accounts.password_saved_placeholder') : ''"
                class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.folder') }}</label>
              <div class="flex gap-2">
                <input v-model="imapDraft.folder" type="text" class="min-w-0 flex-1 h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
                <button type="button" @click="browseImapFolders" :disabled="browsingFolders"
                  class="cursor-pointer h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm hover:bg-neutral-50 disabled:opacity-50">
                  {{ browsingFolders ? t('bank_accounts.browsing') : t('bank_accounts.browse') }}
                </button>
              </div>
              <select v-if="folderOptions.length > 0" :value="imapDraft.folder" @change="selectImapFolder(($event.target as HTMLSelectElement).value)"
                class="mt-2 w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm">
                <option v-for="folder in folderOptions" :key="folder" :value="folder">{{ folder }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.max_per_run') }}</label>
              <input v-model.number="imapDraft.max_messages_per_run" type="number" min="1" max="500"
                class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.process_from') }}</label>
              <input v-model="imapDraft.process_from_date" type="date"
                class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <label class="flex items-center gap-2 text-sm mt-7">
              <input v-model="imapDraft.validate_cert" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('bank_accounts.validate_cert') }}
            </label>
            <label class="flex items-start gap-2 text-sm mt-7 md:col-span-2">
              <input v-model="imapDraft.require_email_auth" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
              <span>
                {{ t('bank_accounts.require_email_auth') }}
                <span class="block text-xs text-neutral-500">{{ t('bank_accounts.require_email_auth_hint') }}</span>
              </span>
            </label>
            <div v-if="imapDraft.require_email_auth" class="md:col-span-3">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.email_auth_serv_id') }}</label>
              <input v-model="imapDraft.email_auth_serv_id" type="text" placeholder="mx.mojefirma.cz"
                class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
              <p class="text-xs text-neutral-500 mt-1">{{ t('bank_accounts.email_auth_serv_id_hint') }}</p>
            </div>
            <div class="md:col-span-3 grid md:grid-cols-2 gap-4 mt-7 items-start">
              <label class="flex items-start gap-2 text-sm">
                <input v-model="imapDraft.allow_forwarded" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
                <span>
                  {{ t('bank_accounts.allow_forwarded') }}
                  <span class="block text-xs text-neutral-500">{{ t('bank_accounts.allow_forwarded_hint') }}</span>
                </span>
              </label>
              <div v-if="imapDraft.allow_forwarded">
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.forwarded_from') }}</label>
                <input v-model="imapDraft.forwarded_from" type="text" placeholder="jan@firma.cz"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
                <p class="text-xs text-neutral-500 mt-1">{{ t('bank_accounts.forwarded_from_hint') }}</p>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.on_success') }}</label>
              <select v-model="imapDraft.success_action" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm">
                <option value="none">{{ t('bank_accounts.action_none') }}</option>
                <option value="add_flag">{{ t('bank_accounts.action_add_flag') }}</option>
                <option value="move">{{ t('bank_accounts.action_move') }}</option>
                <option value="mark_seen">{{ t('bank_accounts.action_mark_seen') }}</option>
              </select>
            </div>
            <div v-if="imapDraft.success_action === 'add_flag'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.success_flag') }}</label>
              <input v-model="imapDraft.success_flag" type="text" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div v-if="imapDraft.success_action === 'move'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.target_folder') }}</label>
              <input v-model="imapDraft.success_move_folder" type="text" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div class="mt-4 flex justify-end gap-2">
            <button type="button" @click="closeImapForm"
              class="cursor-pointer h-9 px-3 bg-surface border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
              {{ t('common.cancel') }}
            </button>
            <button type="button" @click="saveImapAccount" :disabled="saving"
              class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50">
              {{ saving ? t('bank_accounts.saving') : t('bank_accounts.save_imap') }}
            </button>
          </div>
        </div>
      </section>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="min-w-0">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('bank_accounts.providers_title') }}</h2>
            <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank_accounts.providers_subtitle') }}</p>
          </div>
          <button type="button" @click="startNewRegexProvider"
            class="cursor-pointer shrink-0 self-start sm:self-auto whitespace-nowrap h-9 px-3 bg-surface border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
            {{ t('bank_accounts.new_regex_provider') }}
          </button>
        </header>
        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_name') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_owner') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_parser') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_rules') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('bank_accounts.th_active') }}</th>
                <th class="px-3 py-2 text-right"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-if="providers.length === 0">
                <td colspan="6" class="px-3 py-4 text-sm text-neutral-500">{{ t('bank_accounts.providers_empty') }}</td>
              </tr>
              <tr v-for="p in providers" :key="p.provider_ref">
                <td class="px-3 py-2">
                  <div class="font-medium">{{ p.name }}</div>
                  <div class="text-xs text-neutral-500 font-mono">{{ p.code }}</div>
                </td>
                <td class="px-3 py-2">
                  <span class="inline-flex items-center rounded-full border border-neutral-200 px-2 py-0.5 text-xs">
                    {{ providerOwnerLabel(p) }}
                  </span>
                </td>
                <td class="px-3 py-2">{{ parserTypeLabel(p.parser_type) }}</td>
                <td class="px-3 py-2 text-xs text-neutral-600">
                  <div v-if="p.sender_whitelist">{{ t('bank_accounts.rule_sender') }}: {{ p.sender_whitelist }}</div>
                  <div v-if="p.subject_pattern">{{ t('bank_accounts.rule_subject') }}: <span class="font-mono">{{ p.subject_pattern }}</span></div>
                  <div v-if="p.body_pattern">{{ t('bank_accounts.rule_body') }}: <span class="font-mono">{{ p.body_pattern }}</span></div>
                  <span v-if="!p.sender_whitelist && !p.subject_pattern && !p.body_pattern">—</span>
                </td>
                <td class="px-3 py-2 text-center">
                  <span :class="p.enabled ? 'text-success-600' : 'text-neutral-500'">{{ p.enabled ? t('common.yes') : t('common.no') }}</span>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <button v-if="p.parser_type === 'regex'" type="button" @click="startCloneProvider(p)"
                    class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs">
                    {{ t('bank_accounts.provider_clone') }}
                  </button>
                  <button v-if="p.id !== null && p.supplier_id !== null && p.parser_type === 'regex'" type="button" @click="startEditProvider(p)"
                    class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs ml-2">
                    {{ t('common.edit') }}
                  </button>
                  <button v-if="p.id !== null && p.supplier_id !== null" type="button" @click="removeProvider(p)"
                    class="cursor-pointer text-danger-600 hover:text-danger-700 text-xs ml-2">
                    {{ t('common.delete') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="p-5 border-t border-neutral-200">
            <h3 class="text-sm font-medium mb-2">{{ t('bank_accounts.parser_test_title') }}</h3>
            <select v-model="parserProviderRef" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm mb-2">
              <option :value="null">{{ t('bank_accounts.provider_auto') }}</option>
              <option v-for="p in providers" :key="p.provider_ref" :value="p.provider_ref">
                {{ providerSelectLabel(p) }}
              </option>
            </select>
            <div class="grid md:grid-cols-2 gap-3 mb-2">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.sender') }}</label>
                <input v-model="parserSender" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.subject') }}</label>
                <input v-model="parserSubject" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
              </div>
            </div>
            <textarea v-model="parserText" rows="8" class="w-full p-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono"
              :placeholder="t('bank_accounts.email_text_placeholder')"></textarea>
            <button type="button" @click="testParser"
              class="cursor-pointer mt-2 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
              {{ t('bank_accounts.run_parser_test') }}
            </button>
            <pre v-if="parserResult" class="mt-3 text-xs bg-neutral-50 border border-neutral-200 rounded-md p-3 overflow-auto">{{ parserResult }}</pre>
        </div>
      </section>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="min-w-0">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('bank_accounts.messages_title') }}</h2>
            <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank_accounts.messages_subtitle') }}</p>
          </div>
          <button type="button" @click="runScan" :disabled="scanning"
            class="cursor-pointer shrink-0 self-start sm:self-auto whitespace-nowrap h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50">
            {{ scanning ? '…' : t('bank_accounts.run_scan') }}
          </button>
        </header>
        <div v-if="scanSummary" class="px-5 py-2 text-xs border-b border-neutral-200 bg-neutral-50">
          {{ t('bank_accounts.scan_summary', {
            processed: scanSummary.processed ?? 0,
            matched: scanSummary.matched ?? 0,
            known: scanSummary.known_skipped ?? 0,
            old: scanSummary.old_skipped ?? 0,
            rejected: scanSummary.security_rejected ?? 0,
            errors: scanSummary.errors ?? 0,
          }) }}
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">ID</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_imap_account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_message_id') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_status') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_parser') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_payment') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_transaction') }}</th>
                <th class="px-3 py-2 text-right"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="m in messages" :key="m.id">
                <td class="px-3 py-2 font-mono text-xs">#{{ m.id }}<div v-if="m.imap_uid" class="text-neutral-500">UID {{ m.imap_uid }}</div></td>
                <td class="px-3 py-2 text-xs">{{ m.imap_account_name || (m.imap_account_id ? `#${m.imap_account_id}` : '—') }}</td>
                <td class="px-3 py-2 max-w-sm">
                  <div class="font-mono text-xs truncate">{{ m.message_id || m.fallback_hash }}</div>
                  <div class="text-xs text-neutral-500 truncate">{{ m.sender }} · {{ m.subject }}</div>
                </td>
                <td class="px-3 py-2">
                  <span :class="m.matched ? 'text-success-600' : (['match_failed','parse_failed','security_rejected','postprocess_failed'].includes(m.effective_status || m.status) ? 'text-danger-500' : '')">{{ m.effective_status || m.status }}</span>
                  <div v-if="m.error_message" class="text-xs text-danger-500">{{ m.error_message }}</div>
                </td>
                <td class="px-3 py-2">{{ m.provider_code || '—' }}</td>
                <td class="px-3 py-2 font-mono text-xs">
                  {{ m.parsed_payload?.variable_symbol || '—' }}
                  <div v-if="m.parsed_payload?.amount">{{ m.parsed_payload.amount }} {{ m.parsed_payload.currency }}</div>
                </td>
                <td class="px-3 py-2">
                  <span v-if="m.bank_transaction_id">#{{ m.bank_transaction_id }}</span>
                  <span v-else>—</span>
                  <div v-if="m.matched_varsymbol" class="text-xs text-success-600">{{ t('bank_accounts.matched_invoice', { vs: m.matched_varsymbol }) }}</div>
                </td>
                <td class="px-3 py-2 text-right">
                  <button type="button" @click="deleteMessage(m)" class="cursor-pointer text-danger-600 hover:text-danger-700 text-xs">{{ t('bank_accounts.delete_message') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="messagesTotal > messagesPerPage" class="px-5 py-3 border-t border-neutral-200 flex items-center justify-between gap-3 text-sm">
          <span class="text-neutral-500">{{ t('common.pagination_range', { from: messagesFrom, to: messagesTo, total: messagesTotal }) }}</span>
          <div class="flex items-center gap-1">
            <button type="button" :disabled="messagesPage <= 1" @click="loadMessagesPage(messagesPage - 1)"
              class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">‹</button>
            <span class="px-2 text-neutral-600">{{ messagesPage }} / {{ messagesTotalPages }}</span>
            <button type="button" :disabled="messagesPage >= messagesTotalPages" @click="loadMessagesPage(messagesPage + 1)"
              class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">›</button>
          </div>
        </div>
      </section>
      </div>
    </div>

    <div v-if="currencyFormOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ editingCurrency === null ? t('bank_accounts.new_account') : t('bank_accounts.edit_account_title', { label: editingCurrencyLabel }) }}</h3>
        <div class="space-y-3">
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.currency_code') }} <span v-if="editingCurrency === null">*</span></label>
              <input v-if="editingCurrency === null" v-model="currencyDraft.code" type="text" maxlength="3"
                class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono uppercase" />
              <div v-else class="h-10 px-3 flex items-center bg-neutral-50 border border-neutral-200 rounded-md text-sm font-mono">
                {{ currencyDraft.code }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.symbol') }}</label>
              <input v-model="currencyDraft.symbol" type="text"
                class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.decimals') }}</label>
              <input v-model.number="currencyDraft.decimals" type="number" min="0" max="6"
                class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
          </div>
          <div v-if="supplierHasDic" class="flex items-center justify-end">
            <button type="button" @click="loadBankToDraft" :disabled="bankDraftLoading"
              class="cursor-pointer h-8 px-3 text-xs bg-surface border border-primary-300 text-primary-700 rounded-md hover:bg-primary-50 disabled:opacity-50 inline-flex items-center gap-1.5">
              <span v-if="bankDraftLoading">…</span>
              {{ bankDraftLoading ? t('common.loading') : t('supplier.bank_lookup') }}
            </button>
          </div>
          <div v-if="bankDraftMsg" class="text-xs px-2 py-1 rounded"
            :class="{
              'bg-success-50 text-success-600': bankDraftMsg.type === 'success',
              'bg-danger-50 text-danger-500': bankDraftMsg.type === 'error',
              'bg-warning-50 text-warning-600': bankDraftMsg.type === 'warning',
            }">
            {{ bankDraftMsg.text }}
          </div>
          <div v-if="bankDraftAccounts.length > 1">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('bank_accounts.bank_lookup_pick') }}</label>
            <div class="flex flex-wrap gap-1.5">
              <button v-for="(acc, i) in bankDraftAccounts" :key="i" type="button" @click="applyBankAccount(acc)"
                class="cursor-pointer h-7 px-2 text-xs font-mono bg-surface border border-neutral-300 rounded hover:bg-primary-50 hover:border-primary-300">
                {{ acc.display }}
              </button>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.account_label') }}</label>
            <input v-model="currencyDraft.label" type="text"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.account_number') }}</label>
            <input v-model="currencyDraft.account_number" type="text"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.bank_code') }}</label>
            <input v-model="currencyDraft.bank_code" type="text"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.bank_name') }}</label>
            <input v-model="currencyDraft.bank_name" type="text"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.iban') }}</label>
            <input v-model="currencyDraft.iban" type="text"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.bic') }}</label>
            <input v-model="currencyDraft.bic" type="text"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="currencyDraft.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('bank_accounts.active_account') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="currencyDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('bank_accounts.default_for_currency') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="closeCurrencyForm"
              class="cursor-pointer px-3 h-9 text-sm bg-surface border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button type="button" @click="saveCurrency"
              class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="providerFormOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-5xl w-full max-h-[90vh] overflow-y-auto p-5">
        <h3 class="text-lg font-semibold mb-4">{{ providerDraft.id === null ? t('bank_accounts.new_regex_provider') : t('bank_accounts.edit_provider_title', { name: providerDraft.name }) }}</h3>

        <div class="space-y-5">
          <section>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('bank_accounts.basic_settings') }}</h4>
            <div class="grid md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.name') }}</label>
                <input v-model="providerDraft.name" @input="syncProviderCode" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.code') }}</label>
                <input v-model="providerDraft.code" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
              </div>
              <label class="flex items-center gap-2 text-sm mt-7">
                <input v-model="providerDraft.enabled" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                {{ t('bank_accounts.active_provider') }}
              </label>
            </div>
          </section>

          <section>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('bank_accounts.email_rules') }}</h4>
            <div class="grid md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.sender') }}</label>
                <input v-model="providerDraft.sender_whitelist" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm"
                  placeholder="info@banka.cz" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.subject_regex') }}</label>
                <input v-model="providerDraft.subject_pattern" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono"
                  placeholder="Pohyb\s+na\s+účtě" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank_accounts.body_regex') }}</label>
                <input v-model="providerDraft.body_pattern" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono"
                  placeholder="Variabilní\s+symbol" />
              </div>
            </div>
          </section>

          <section>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('bank_accounts.extracted_fields') }}</h4>
            <div class="overflow-x-auto border border-neutral-200 rounded-lg">
              <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium w-56">{{ t('bank_accounts.th_field') }}</th>
                    <th class="px-3 py-2 text-left font-medium">{{ t('bank_accounts.th_regex') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="field in regexFieldDefinitions" :key="field.key">
                    <td class="px-3 py-2">
                      <div class="font-medium">{{ fieldLabel(field.key) }}</div>
                      <div class="text-xs" :class="field.required ? 'text-warning-700' : 'text-neutral-500'">
                        {{ field.required ? t('bank_accounts.field_required') : t('bank_accounts.field_optional') }}
                      </div>
                    </td>
                    <td class="px-3 py-2">
                      <input v-model="providerDraft.field_patterns[field.key]" type="text"
                        class="w-full h-9 px-2 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('bank_accounts.normalizer_config') }}</h4>
            <textarea v-model="providerDraft.normalizer_config_json" rows="4"
              class="w-full p-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono"></textarea>
          </section>

          <div class="flex justify-end gap-2 pt-1">
            <button type="button" @click="closeProviderForm"
              class="cursor-pointer px-3 h-9 text-sm bg-surface border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button type="button" @click="saveProvider"
              class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('bank_accounts.save_provider') }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

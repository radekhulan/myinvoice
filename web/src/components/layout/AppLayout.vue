<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { RouterLink, RouterView, useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { updateApi, type PublicVersion } from '@/api/update'
import SupplierSwitcher from './SupplierSwitcher.vue'
import GlobalSearch from './GlobalSearch.vue'
import ThemeToggle from './ThemeToggle.vue'

const { t, locale } = useI18n()
function setLocale(l: 'cs' | 'en') {
  locale.value = l
  localStorage.setItem('locale', l)
}

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const supplierStore = useSupplierStore()

const mobileOpen = ref(false)

async function logout() {
  await auth.logout()
  router.push('/login')
}

interface NavItem {
  to: string
  label: string
  icon: string
  /** True = externí odkaz (otevře se v novém tabu, ne RouterLink). Např. /manual. */
  external?: boolean
}
interface NavSection {
  /** Hlavička sekce; pokud chybí, položky jsou bez visual grouping */
  title?: string
  /** Color accent pro vertikální pruh + text. Tailwind utility class group. */
  accent?: 'primary' | 'warning' | 'success' | 'danger' | 'neutral'
  items: NavItem[]
}

/** Mapování accent → soft pill (background + text) per sekce. */
const ACCENT_CLASSES: Record<NonNullable<NavSection['accent']>, string> = {
  primary: 'bg-primary-50  text-primary-700',
  warning: 'bg-warning-50  text-warning-600',
  success: 'bg-success-50  text-success-600',
  danger:  'bg-danger-50   text-danger-500',
  neutral: 'bg-neutral-100 text-neutral-600',
}

/** Outline icon paths — Heroicons style, stroke 2, viewBox 24, currentColor */
const ICONS = {
  dashboard:  'M3 12l9-9 9 9M5 10v10h14V10',
  invoices:   'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  recurring:  'M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06',
  purchase:   'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-8 2a2 2 0 1 1-4 0 2 2 0 0 1 4 0z',
  bank:       'M3 9l9-7 9 7m-2 0v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V9m4 11V13h4v7',
  stats:      'M3 3v18h18M7 14l4-4 4 4 5-5',
  crm:        'M11 3.055A9.001 9.001 0 1 0 20.945 13H11V3.055zM20.488 9H15V3.512A9.025 9.025 0 0 1 20.488 9z',
  reports:    'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2zM9 7h1',
  clients:    'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  projects:   'M3 7l9-4 9 4-9 4-9-4zM3 12l9 4 9-4M3 17l9 4 9-4',
  settings:   'M10.325 4.317a1 1 0 0 1 1.94 0l.31 1.241a7.5 7.5 0 0 1 2.106.873l1.097-.633a1 1 0 0 1 1.371.366l.97 1.683a1 1 0 0 1-.366 1.366l-1.094.632a7.5 7.5 0 0 1 0 2.428l1.094.632a1 1 0 0 1 .366 1.366l-.97 1.683a1 1 0 0 1-1.371.366l-1.097-.633a7.5 7.5 0 0 1-2.106.873l-.31 1.241a1 1 0 0 1-1.94 0l-.31-1.241a7.5 7.5 0 0 1-2.106-.873l-1.097.633a1 1 0 0 1-1.371-.366l-.97-1.683a1 1 0 0 1 .366-1.366l1.094-.632a7.5 7.5 0 0 1 0-2.428l-1.094-.632a1 1 0 0 1-.366-1.366l.97-1.683a1 1 0 0 1 1.371-.366l1.097.633a7.5 7.5 0 0 1 2.106-.873l.31-1.241zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  suppliers:  'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM23 11a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  codebooks:  'M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2m14 0V9a2 2 0 0 0-2-2M5 11V9a2 2 0 0 1 2-2m0 0V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2M7 7h10',
  imports:    'M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-8l-4-4m0 0l-4 4m4-4v12',
  exports:    'M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
  users:      'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  email:      'M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z',
  approvals:  'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0 1 12 2.944a11.955 11.955 0 0 1-8.618 3.04A12.02 12.02 0 0 0 3 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
  log:        'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 12h6m-6 4h4',
  cron:       'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  updates:    'M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06',
  api_tokens: 'M15 7a2 2 0 0 1 2 2m4 0a6 6 0 0 1-7.743 5.743L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.586a1 1 0 0 1 .293-.707l5.964-5.964A6 6 0 1 1 21 9z',
  help:       'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827V14m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  ai:         'M13 10V3L4 14h7v7l9-11h-7z',
  documents:  'M7 21h10a2 2 0 0 0 2-2V9.414a1 1 0 0 0-.293-.707l-5.414-5.414A1 1 0 0 0 12.586 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2zM9 13h6m-6 4h6',
  // Daně sekce — různé ikony pro každý report
  tax_dph:    'M3 10h18M3 14h18M5 21V3a1 1 0 011-1h12a1 1 0 011 1v18M9 7h6M9 11h6M9 15h6',
  tax_kh:     'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
  tax_shv:    'M12 21l-8-8 8-8m0 0l8 8-8 8M3 12h18',
  tax_income: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  tax_archive: 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4',
  tax_book:   'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
}

const navSections = computed<NavSection[]>(() => {
  const isAdmin = auth.user?.role === 'admin'
  const sections: NavSection[] = [
    { items: [{ to: '/', label: t('nav.dashboard'), icon: ICONS.dashboard }] },
    {
      // Vše co se týká vystavování faktur klientům — klienti/zakázky/schvalování/exporty
      // patří v životním cyklu jednoho prodeje (klient → zakázka → faktura → schválení → export pro účetní).
      title: t('nav.section_sales'),
      accent: 'primary',
      items: [
        { to: '/invoices',         label: t('nav.invoices'),   icon: ICONS.invoices },
        { to: '/recurring',        label: t('nav.recurring'),  icon: ICONS.recurring },
        { to: '/clients',          label: t('nav.clients'),    icon: ICONS.clients },
        { to: '/projects',         label: t('nav.projects'),   icon: ICONS.projects },
        ...(isAdmin ? [{ to: '/admin/approvals',          label: t('nav.approvals'),         icon: ICONS.approvals }] : []),
        // Export vidí všichni vč. readonly (export dat = čtení), daňové výkazy taktéž (sekce Daně níže).
        { to: '/admin/export',  label: t('nav.exports'),           icon: ICONS.exports   },
        ...(isAdmin ? [{ to: '/admin/import?tab=issued',  label: t('nav.imports_issued'),    icon: ICONS.imports   }] : []),
      ],
    },
    {
      title: t('nav.section_purchase'),
      accent: 'warning',
      items: [
        { to: '/purchase-invoices',          label: t('nav.purchase_invoices'),  icon: ICONS.purchase },
        { to: '/clients?role=vendors',       label: t('nav.vendors'),            icon: ICONS.suppliers },
        { to: '/purchase-invoices/export',   label: t('nav.purchase_export'),    icon: ICONS.exports },
        ...(isAdmin ? [{ to: '/admin/import?tab=purchase',  label: t('nav.imports_purchase'), icon: ICONS.imports }] : []),
        ...(isAdmin ? [{ to: '/admin/integrations?tab=ai',  label: t('nav.ai_import'),        icon: ICONS.ai }] : []),
      ],
    },
    {
      title: t('nav.section_finance'),
      accent: 'success',
      items: [
        { to: '/crm',            label: t('nav.crm'),            icon: ICONS.crm },
        { to: '/stats',          label: t('nav.stats'),          icon: ICONS.stats },
        { to: '/purchase-stats', label: t('nav.purchase_stats'), icon: ICONS.purchase },
        { to: '/bank',           label: t('nav.bank'),           icon: ICONS.bank },
      ],
    },
    {
      title: t('nav.section_documents'),
      accent: 'neutral',
      items: [
        { to: '/documents', label: t('nav.documents'), icon: ICONS.documents },
      ],
    },
    {
      title: t('nav.section_taxes'),
      accent: 'danger',
      items: [
        { to: '/reports/dph',         label: t('nav.reports_dph'),         icon: ICONS.tax_dph },
        { to: '/reports/kh',          label: t('nav.reports_kh'),          icon: ICONS.tax_kh },
        { to: '/reports/dph-book',    label: t('nav.reports_dph_book'),    icon: ICONS.tax_book },
        { to: '/reports/shv',         label: t('nav.reports_shv'),         icon: ICONS.tax_shv },
        { to: '/reports/income-tax',  label: t('nav.reports_income_tax'),  icon: ICONS.tax_income },
        { to: '/reports/submissions', label: t('nav.reports_submissions'), icon: ICONS.tax_archive },
        { to: '/reports/monthly-export', label: t('nav.reports_monthly_export'), icon: ICONS.exports },
      ],
    },
  ]

  if (isAdmin) {
    // Suppliers (multi-tenant firmy) jsou teď přístupné jako první tab v Codebooks.
    // Sjednocený "Import" pokrývá vystavené i přijaté faktury (admin/import s tabs).
    sections.push({
      title: t('nav.system'),
      accent: 'neutral',
      items: [
        { to: '/admin/settings',         label: t('nav.settings'),        icon: ICONS.settings },
        { to: '/admin/codebooks',        label: t('nav.codebooks'),       icon: ICONS.codebooks },
        { to: '/admin/integrations',     label: t('nav.integrations'),    icon: ICONS.api_tokens },
        { to: '/admin/users',            label: t('nav.users'),           icon: ICONS.users },
        { to: '/admin/email-templates',  label: t('nav.email_templates'), icon: ICONS.email },
        { to: '/admin/activity-log',     label: t('nav.log'),             icon: ICONS.log },
        { to: '/admin/cron-jobs',        label: t('nav.cron_jobs'),       icon: ICONS.cron },
        { to: '/admin/update',           label: t('nav.updates'),         icon: ICONS.updates },
        { to: '/profile/api-tokens',     label: t('nav.api_tokens'),      icon: ICONS.api_tokens },
      ],
    })
  }

  // Nápověda jako poslední (po Systému) — externí link na manuál v novém tabu.
  sections.push({
    items: [
      { to: '/manual', label: t('nav.help'), icon: ICONS.help, external: true },
    ],
  })

  return sections
})

/** Ploché položky menu pro globální search (našeptávač skáče přímo na body menu). */
const flatNavItems = computed(() =>
  navSections.value.flatMap(s => s.items.map(it => ({ to: it.to, label: it.label, icon: it.icon, external: it.external })))
)

function isActive(to: string): boolean {
  if (to === '/') return route.path === '/'
  // /admin/suppliers je nyní dostupné jako první tab v Codebooks → aktivuje Codebooks položku
  if (to === '/admin/codebooks' && route.path.startsWith('/admin/suppliers')) return true

  // Split `to` na path + query (pokud má query — např. /clients?role=vendors)
  const [toPath, toQs] = to.split('?', 2)

  // Pokud současná route NEMÁ stejný path nebo child path — určitě není aktivní.
  // Pozor: prostý startsWith by matchoval i sourozence se stejným prefixem
  // (např. /reports/dph by matchoval /reports/dph-book), proto vyžadujeme
  // přesnou shodu NEBO následující `/` (skutečný child segment).
  if (route.path !== toPath && !route.path.startsWith(toPath + '/')) return false

  // Pokud item má query, musí se shodovat key-by-key s current route query.
  if (toQs) {
    const params = new URLSearchParams(toQs)
    for (const [k, v] of params) {
      if (String(route.query[k] ?? '') !== v) return false
    }
    return true
  }

  // Item NEMÁ query — pokud current route má query a existuje JINÝ item se stejným path
  // a matchujícím query, ten druhý je aktivní, tento ne (např. /clients vs /clients?role=vendors).
  if (Object.keys(route.query).length > 0) {
    for (const section of navSections.value) {
      for (const it of section.items) {
        if (it.to === to) continue
        const [iPath, iQs] = it.to.split('?', 2)
        if (iPath !== toPath || !iQs) continue
        const iParams = new URLSearchParams(iQs)
        let match = true
        for (const [k, v] of iParams) {
          if (String(route.query[k] ?? '') !== v) { match = false; break }
        }
        if (match) return false
      }
    }
  }

  // Delší `to` v menu má prednost (např. /purchase-invoices vs /purchase-invoices/export).
  for (const section of navSections.value) {
    for (const it of section.items) {
      if (it.to !== to && it.to.startsWith(toPath + '/') && route.path.startsWith(it.to.split('?')[0])) {
        return false
      }
    }
  }
  return true
}

// Zavři mobile drawer po navigaci
watch(() => route.path, () => { mobileOpen.value = false })

const versionInfo = ref<PublicVersion | null>(null)
onMounted(async () => {
  try { versionInfo.value = await updateApi.publicVersion() } catch {}
})
</script>

<template>
  <div class="min-h-screen flex flex-col bg-neutral-50">

    <!-- ═════════════════════ TOPBAR ═════════════════════ -->
    <header class="sticky top-0 z-30 bg-surface border-b border-neutral-200">
      <div class="h-14 px-4 flex items-center justify-between gap-3">
        <!-- Logo -->
        <RouterLink to="/" class="flex items-center gap-2.5 shrink-0" @click="mobileOpen = false">
          <img src="/styles/logo.svg" alt="MyInvoice" class="w-8 h-8" />
          <span class="text-sm font-semibold leading-tight select-none">
            My<span class="text-primary-600">Invoice</span><span class="text-neutral-400 font-normal">.cz</span>
          </span>
        </RouterLink>

        <!-- Pravá strana topbaru -->
        <div class="flex items-center gap-2 text-sm">
          <!-- Jméno uživatele (desktop) — link na profil (heslo + 2FA v záložkách). -->
          <RouterLink
            to="/profile/password"
            class="hidden lg:inline text-sm text-neutral-600 hover:text-primary-700 hover:underline"
            :title="t('auth.profile_title')"
          >{{ auth.user?.name }}</RouterLink>

          <!-- Locale switcher (CZ / EN s SVG vlajkami) -->
          <div class="hidden sm:inline-flex items-center border border-neutral-200 rounded-md overflow-hidden">
            <button
              @click="setLocale('cs')" title="Čeština" aria-label="Čeština"
              class="cursor-pointer h-8 px-2 inline-flex items-center"
              :class="locale === 'cs' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60 hover:grayscale-0 hover:opacity-100'"
            >
              <svg width="22" height="15" viewBox="0 0 6 4" xmlns="http://www.w3.org/2000/svg">
                <rect width="6" height="2" fill="#ffffff"/>
                <rect y="2" width="6" height="2" fill="#d7141a"/>
                <polygon points="0,0 3,2 0,4" fill="#11457e"/>
              </svg>
            </button>
            <button
              @click="setLocale('en')" title="English" aria-label="English"
              class="cursor-pointer h-8 px-2 inline-flex items-center border-l border-neutral-200"
              :class="locale === 'en' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60 hover:grayscale-0 hover:opacity-100'"
            >
              <svg width="22" height="15" viewBox="0 0 60 30" xmlns="http://www.w3.org/2000/svg">
                <clipPath id="uk-flag-tb"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath>
                <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
                <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
                <path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#uk-flag-tb)" stroke="#C8102E" stroke-width="4"/>
                <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
                <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
              </svg>
            </button>
          </div>

          <!-- Přepínač motivu (System / Light / Dark) — na mobilu je v drawer patičce -->
          <div class="hidden sm:inline-flex">
            <ThemeToggle />
          </div>

          <!-- Nápověda -->
          <a
            href="/manual" target="_blank" rel="noopener"
            class="hidden sm:inline-flex w-8 h-8 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-primary-700"
            :title="t('nav.help')"
            :aria-label="t('nav.help')"
          >
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" />
            </svg>
          </a>

          <!-- Odhlásit (desktop) -->
          <button
            @click="logout"
            class="cursor-pointer hidden sm:inline-flex px-3 h-8 items-center text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50"
          >{{ t('nav.logout') }}</button>

          <!-- Hamburger (mobile, < lg) -->
          <button
            type="button" @click="mobileOpen = !mobileOpen"
            :aria-expanded="mobileOpen" aria-label="Menu"
            class="lg:hidden inline-flex items-center justify-center w-9 h-9 rounded-md text-neutral-700 hover:bg-neutral-100"
          >
            <svg v-if="!mobileOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Active supplier banner -->
      <div v-if="supplierStore.hasMultiple && supplierStore.currentSupplier" class="bg-primary-50 border-t border-primary-100">
        <div class="px-4 py-1.5 text-xs text-primary-700 flex items-center gap-2">
          <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4"/>
          </svg>
          <span class="flex-1 min-w-0 truncate">
            {{ t('supplier.active_label') }}: <strong class="font-semibold">{{ supplierStore.currentSupplier.company_name }}</strong>
            <span v-if="supplierStore.currentSupplier.ic" class="font-mono text-primary-600 ml-1">({{ t('common.ic') }} {{ supplierStore.currentSupplier.ic }})</span>
          </span>
          <SupplierSwitcher />
        </div>
      </div>
    </header>

    <!-- ═════════════════════ TĚLO: SIDEBAR + OBSAH ═════════════════════ -->
    <div class="flex flex-1 min-h-0">

      <!-- Mobile backdrop -->
      <div
        v-if="mobileOpen" @click="mobileOpen = false"
        class="lg:hidden fixed inset-0 bg-black/50 z-20"
        aria-hidden="true"
      ></div>

      <!-- ── SIDEBAR ── -->
      <aside
        :class="[
          'fixed lg:sticky top-14 z-30 lg:z-auto',
          'h-[calc(100vh-3.5rem)] w-60 shrink-0',
          'bg-surface border-r border-neutral-200',
          'flex flex-col',
          'transition-transform duration-200 ease-in-out',
          mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
        ]"
      >
        <nav class="flex-1 overflow-y-auto scrollbar-slim px-2.5 py-3">
          <!-- Globální vyhledávač (před Přehled) — našeptává menu + hledá klienty/faktury -->
          <GlobalSearch :menu-items="flatNavItems" @navigated="mobileOpen = false" />

          <template v-for="(section, si) in navSections" :key="si">
            <!-- Section title — soft pill background v barvě sekce -->
            <div v-if="section.title" :class="si === 0 ? 'pt-1 pb-1.5' : 'pt-4 pb-1.5'">
              <div
                class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider"
                :class="section.accent ? ACCENT_CLASSES[section.accent] : 'bg-neutral-100 text-neutral-600'"
              >{{ section.title }}</div>
            </div>

            <!-- Items: external (např. Nápověda → /manual v novém tabu) vs internal route -->
            <template v-for="item in section.items" :key="item.to">
              <a
                v-if="item.external"
                :href="item.to"
                target="_blank"
                rel="noopener"
                class="flex items-center gap-2.5 px-2.5 py-[7px] rounded-md text-sm transition-colors leading-tight text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100"
              >
                <svg class="w-[15px] h-[15px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                </svg>
                {{ item.label }}
                <svg class="w-3 h-3 ml-auto text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
              </a>
              <RouterLink
                v-else
                :to="item.to"
                active-class=""
                exact-active-class=""
                class="flex items-center gap-2.5 px-2.5 py-[7px] rounded-md text-sm transition-colors leading-tight"
                :class="isActive(item.to)
                  ? 'bg-primary-50 text-primary-700 font-medium'
                  : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'"
              >
                <svg class="w-[15px] h-[15px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                </svg>
                {{ item.label }}
              </RouterLink>
            </template>
          </template>
        </nav>

        <!-- Verze + odkaz na projekt (dole) -->
        <div v-if="versionInfo" class="px-4 py-2.5 border-t border-neutral-100 flex items-center gap-2">
          <a href="https://myinvoice.cz/" target="_blank" rel="noopener"
             class="text-xs text-neutral-500 hover:text-primary-700 hover:underline transition-colors"
             title="MyInvoice.cz">MyInvoice.cz</a>
          <RouterLink
            v-if="auth.user?.role === 'admin'"
            to="/admin/update"
            class="inline-flex items-center gap-1.5 text-xs text-neutral-400 hover:text-neutral-600 transition-colors"
            :title="t('updates.title')"
          >
            <span>v{{ versionInfo.current }}</span>
            <span
              v-if="versionInfo.has_update"
              class="inline-flex items-center gap-1 rounded-full bg-primary-100 text-primary-700 px-1.5 py-0.5 text-[10px] font-semibold leading-none"
            >
              <svg class="w-2 h-2" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="6"/></svg>
              v{{ versionInfo.latest }}
            </span>
          </RouterLink>
          <span v-else class="text-xs text-neutral-400">v{{ versionInfo.current }}</span>
        </div>

        <!-- Mobile only: uživatel + jazyk + odhlásit (na dně sidebaru) -->
        <div class="lg:hidden border-t border-neutral-200 px-4 py-3 bg-neutral-50 space-y-3">
          <div class="flex items-center justify-between">
            <div class="text-sm">
              <div class="font-medium text-neutral-900">{{ auth.user?.name }}</div>
              <div class="text-xs text-neutral-500">{{ auth.user?.email }} · {{ auth.user?.role }}</div>
            </div>
            <a
              href="/manual" target="_blank" rel="noopener"
              class="inline-flex w-9 h-9 items-center justify-center rounded-md text-neutral-600 hover:bg-surface"
              :title="t('nav.help')"
            >
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" />
              </svg>
            </a>
          </div>
          <!-- Přepínač motivu (System / Light / Dark) — mobilní varianta -->
          <div class="flex">
            <ThemeToggle />
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="inline-flex items-center border border-neutral-200 bg-surface rounded-md overflow-hidden">
              <button
                @click="setLocale('cs')" title="Čeština"
                class="cursor-pointer h-9 px-3 inline-flex items-center"
                :class="locale === 'cs' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60'"
              >
                <svg width="22" height="15" viewBox="0 0 6 4" xmlns="http://www.w3.org/2000/svg">
                  <rect width="6" height="2" fill="#ffffff"/>
                  <rect y="2" width="6" height="2" fill="#d7141a"/>
                  <polygon points="0,0 3,2 0,4" fill="#11457e"/>
                </svg>
              </button>
              <button
                @click="setLocale('en')" title="English"
                class="cursor-pointer h-9 px-3 inline-flex items-center border-l border-neutral-200"
                :class="locale === 'en' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60'"
              >
                <svg width="22" height="15" viewBox="0 0 60 30" xmlns="http://www.w3.org/2000/svg">
                  <clipPath id="uk-flag-mob"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath>
                  <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
                  <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
                  <path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#uk-flag-mob)" stroke="#C8102E" stroke-width="4"/>
                  <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
                  <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
                </svg>
              </button>
            </div>
            <button
              @click="logout"
              class="cursor-pointer px-4 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface"
            >{{ t('nav.logout') }}</button>
          </div>
        </div>
      </aside>

      <!-- ── HLAVNÍ OBSAH ── -->
      <div class="flex-1 min-w-0 flex flex-col">
        <main class="flex-1 px-5 sm:px-8 py-6 w-full">
          <RouterView />
        </main>

        <footer class="px-5 sm:px-8 py-5 border-t border-neutral-200 text-xs text-neutral-500 flex flex-wrap items-center gap-x-1.5 gap-y-1 leading-none">
          <span>Developed by</span>
          <a href="https://mywebdesign.cz" target="_blank" rel="noopener" class="hover:text-neutral-700">MyWebdesign.cz s.r.o.</a>
          <span aria-hidden="true">·</span>
          <a href="https://github.com/radekhulan/myinvoice" target="_blank" rel="noopener"
             class="inline-flex items-center gap-1 hover:text-neutral-700">
            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/>
            </svg>
            <span>GitHub</span>
          </a>
        </footer>
      </div>
    </div>
  </div>
</template>

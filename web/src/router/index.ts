import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: () => import('@/components/layout/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '',                       name: 'home',           component: () => import('@/pages/Dashboard.vue') },
      { path: 'clients',                name: 'clients',        component: () => import('@/pages/clients/ClientList.vue') },
      { path: 'clients/new',            name: 'client-new',     component: () => import('@/pages/clients/ClientForm.vue'), meta: { requiresWrite: true } },
      { path: 'clients/:id(\\d+)',      name: 'client-detail',  component: () => import('@/pages/clients/ClientDetail.vue') },
      { path: 'clients/:id(\\d+)/edit', name: 'client-edit',    component: () => import('@/pages/clients/ClientForm.vue'), meta: { requiresWrite: true } },
      { path: 'projects',               name: 'projects',       component: () => import('@/pages/projects/ProjectList.vue') },
      { path: 'projects/new',           name: 'project-new',    component: () => import('@/pages/projects/ProjectForm.vue'), meta: { requiresWrite: true } },
      { path: 'projects/:id(\\d+)',     name: 'project-detail', component: () => import('@/pages/projects/ProjectDetail.vue') },
      { path: 'projects/:id(\\d+)/edit', name: 'project-edit',  component: () => import('@/pages/projects/ProjectForm.vue'), meta: { requiresWrite: true } },
      { path: 'invoices',               name: 'invoices',       component: () => import('@/pages/invoices/InvoiceList.vue') },
      { path: 'invoices/new',           name: 'invoice-new',    component: () => import('@/pages/invoices/InvoiceEditor.vue'), meta: { requiresWrite: true } },
      { path: 'invoices/:id(\\d+)',     name: 'invoice-detail', component: () => import('@/pages/invoices/InvoiceDetail.vue') },
      { path: 'invoices/:id(\\d+)/edit', name: 'invoice-edit',  component: () => import('@/pages/invoices/InvoiceEditor.vue'), meta: { requiresWrite: true } },
      // Přijaté faktury (fáze 1 integrace forku)
      { path: 'purchase-invoices',                 name: 'purchase-invoices',        component: () => import('@/pages/purchase-invoices/InvoiceList.vue') },
      { path: 'purchase-invoices/export',          name: 'purchase-invoices-export', component: () => import('@/pages/purchase-invoices/Export.vue') },
      { path: 'purchase-invoices/new',             name: 'purchase-invoice-new',     component: () => import('@/pages/purchase-invoices/InvoiceEditor.vue'), meta: { requiresWrite: true } },
      { path: 'purchase-invoices/:id(\\d+)',       name: 'purchase-invoice-detail',  component: () => import('@/pages/purchase-invoices/InvoiceDetail.vue') },
      { path: 'purchase-invoices/:id(\\d+)/edit',  name: 'purchase-invoice-edit',    component: () => import('@/pages/purchase-invoices/InvoiceEditor.vue'), meta: { requiresWrite: true } },
      // Dokumenty (sekce Dokumenty — plán source/11)
      { path: 'documents',              name: 'documents',        component: () => import('@/pages/documents/DocumentsBrowser.vue') },
      { path: 'documents/:id(\\d+)',    name: 'document-detail',  component: () => import('@/pages/documents/DocumentDetail.vue') },
      { path: 'stats',                  name: 'stats',           component: () => import('@/pages/Stats.vue') },
      { path: 'purchase-stats',         name: 'purchase-stats',  component: () => import('@/pages/PurchaseStats.vue') },
      { path: 'bank',                   name: 'bank-statements', component: () => import('@/pages/bank/StatementList.vue') },
      { path: 'bank/:id(\\d+)',         name: 'bank-detail',     component: () => import('@/pages/bank/StatementDetail.vue') },
      // Admin (M6)
      { path: 'admin/activity-log',     name: 'activity-log',   component: () => import('@/pages/admin/ActivityLog.vue'), meta: { adminOnly: true } },
      { path: 'admin/sent-emails',      name: 'sent-emails',    component: () => import('@/pages/admin/SentEmails.vue'), meta: { adminOnly: true } },
      { path: 'admin/cron-jobs',        name: 'cron-jobs',      component: () => import('@/pages/admin/CronJobs.vue'),    meta: { adminOnly: true } },
      { path: 'admin/users',            name: 'admin-users',    component: () => import('@/pages/admin/Users.vue'),       meta: { adminOnly: true } },
      { path: 'admin/settings',         name: 'admin-settings', component: () => import('@/pages/admin/Settings.vue'),    meta: { adminOnly: true } },
      // /admin/suppliers byla samostatná stránka — Suppliers jsou nyní embedded jako první tab v Codebooks.
      // Redirect zachovává bookmarks / staré odkazy.
      { path: 'admin/suppliers',        name: 'admin-suppliers', redirect: '/admin/codebooks' },
      { path: 'admin/codebooks',        name: 'admin-codebooks', component: () => import('@/pages/admin/Codebooks.vue'),  meta: { adminOnly: true } },
      { path: 'admin/electronic-signatures', name: 'admin-electronic-signatures', component: () => import('@/pages/admin/ElectronicSignatures.vue'), meta: { requiresWrite: true, signingProfiles: true } },
      { path: 'admin/export',           name: 'admin-export',    component: () => import('@/pages/admin/Export.vue') },
      { path: 'admin/import',           name: 'admin-import',    component: () => import('@/pages/admin/Imports.vue'),    meta: { adminOnly: true } },
      { path: 'admin/integrations',     name: 'admin-integrations', component: () => import('@/pages/admin/Integrations.vue'), meta: { adminOnly: true } },
      { path: 'crm',                    name: 'crm-dashboard',      component: () => import('@/pages/crm/CrmDashboard.vue') },
      { path: 'reports/dph',            name: 'reports-dph',        component: () => import('@/pages/reports/DphPriznaniReport.vue') },
      { path: 'reports/kh',             name: 'reports-kh',         component: () => import('@/pages/reports/KontrolniHlaseniReport.vue') },
      { path: 'reports/dph-book',       name: 'reports-dph-book',   component: () => import('@/pages/reports/DphBookReport.vue') },
      { path: 'reports/shv',            name: 'reports-shv',        component: () => import('@/pages/reports/SouhrnneHlaseniReport.vue') },
      { path: 'reports/income-tax',     name: 'reports-income-tax', component: () => import('@/pages/reports/IncomeTaxReport.vue') },
      { path: 'reports/submissions',    name: 'reports-submissions', component: () => import('@/pages/reports/TaxSubmissions.vue') },
      { path: 'reports/monthly-export', name: 'reports-monthly-export', component: () => import('@/pages/reports/MonthlyExportReport.vue') },
      { path: 'tax',                    name: 'tax-optimizer',      component: () => import('@/pages/tax/TaxOptimizer.vue') },
      { path: 'admin/email-templates',  name: 'admin-email-templates', component: () => import('@/pages/admin/EmailTemplates.vue'), meta: { adminOnly: true } },
      { path: 'admin/approvals',        name: 'admin-approvals', component: () => import('@/pages/admin/Approvals.vue'), meta: { adminOnly: true } },
      { path: 'recurring',              name: 'recurring',        component: () => import('@/pages/recurring/RecurringList.vue') },
      { path: 'recurring/new',          name: 'recurring-new',    component: () => import('@/pages/recurring/RecurringForm.vue'), meta: { requiresWrite: true } },
      { path: 'recurring/:id(\\d+)',    name: 'recurring-detail', component: () => import('@/pages/recurring/RecurringDetail.vue') },
      { path: 'recurring/:id(\\d+)/edit', name: 'recurring-edit', component: () => import('@/pages/recurring/RecurringForm.vue'), meta: { requiresWrite: true } },
      { path: 'admin/update',           name: 'admin-update',    component: () => import('@/pages/admin/Update.vue'),    meta: { adminOnly: true } },
      // /profile/totp je zachován pro BC (staré bookmarks, force-TOTP middleware redirect),
      // ale UI ho merge-uje do /profile/password (tabs). Redirect zachovává query stringy.
      { path: 'profile/totp',           name: 'profile-totp',          redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'totp' } }) },
      { path: 'profile/password',       name: 'profile-password',      component: () => import('@/pages/PasswordChange.vue') },
      { path: 'profile/api-tokens',     name: 'profile-api-tokens',    component: () => import('@/pages/ApiTokens.vue') },
      { path: 'profile/signing-profiles', name: 'profile-signing-profiles', redirect: '/admin/electronic-signatures' },
    ],
  },
  { path: '/login',  name: 'login',  component: () => import('@/pages/Login.vue'),          meta: { public: true } },
  { path: '/setup',  name: 'setup',  component: () => import('@/pages/Setup.vue'),          meta: { public: true } },
  { path: '/setup-totp', name: 'setup-totp', component: () => import('@/pages/ForcedTotpSetup.vue'), meta: { requiresAuth: true, totpSetupOnly: true } },
  { path: '/forgot', name: 'forgot', component: () => import('@/pages/ForgotPassword.vue'), meta: { public: true } },
  { path: '/reset',  name: 'reset',  component: () => import('@/pages/ResetPassword.vue'),  meta: { public: true } },
  { path: '/approval/:token([a-f0-9]{32,128})', name: 'approval',
    component: () => import('@/pages/ApprovalPublic.vue'), meta: { public: true } },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/pages/NotFound.vue'),
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  // Scroll-to-top při navigaci sidebar linky; respektuj #hash a back/forward
  scrollBehavior(_to, _from, savedPosition) {
    if (savedPosition) return savedPosition
    if (_to.hash) return { el: _to.hash, behavior: 'smooth' }
    return { top: 0, left: 0 }
  },
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (auth.setupStatus === null) {
    try {
      await auth.fetchSetupStatus()
    } catch {
      // ignore
    }
  }

  if (auth.needsSetup && to.name !== 'setup') {
    return { name: 'setup' }
  }
  if (!auth.needsSetup && to.name === 'setup') {
    return { name: 'login' }
  }

  const requiresAuth = to.matched.some((r) => r.meta.requiresAuth)
  if (requiresAuth && !auth.isAuthenticated) {
    const ok = await auth.refresh()
    if (!ok) return { name: 'login' }
  }

  // Vynucení 2FA: pokud cfg.auth.require_totp = true a uživatel nemá TOTP,
  // přesměruj všechny privátní routes na /setup-totp (kromě samotné /setup-totp).
  if (auth.isAuthenticated && auth.mustSetupTotp && to.name !== 'setup-totp' && requiresAuth) {
    return { name: 'setup-totp' }
  }
  // Naopak když TOTP aktivovaný, /setup-totp už nedává smysl.
  if (auth.isAuthenticated && !auth.mustSetupTotp && to.name === 'setup-totp') {
    return { name: 'home' }
  }

  // Admin-only stránky
  const adminOnly = to.matched.some((r) => r.meta.adminOnly)
  if (adminOnly && auth.user?.role !== 'admin') {
    return { name: 'home' }
  }

  // Zápisové stránky (zakládání/editace dokladů, klientů, zakázek, recurring).
  // readonly smí jen číst/exportovat → na write routes ho přesměrujeme na dashboard.
  const requiresWrite = to.matched.some((r) => r.meta.requiresWrite)
  if (requiresWrite && !auth.canWrite) {
    return { name: 'home' }
  }

  const signingProfiles = to.matched.some((r) => r.meta.signingProfiles)
  if (signingProfiles && auth.user?.role !== 'admin' && auth.user?.role !== 'accountant') {
    return { name: 'home' }
  }

  return true
})

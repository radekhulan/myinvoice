<?php

declare(strict_types=1);

namespace MyInvoice;

use MyInvoice\Action\AresVies\AresLookupAction;
use MyInvoice\Action\AresVies\CrpDphLookupAction;
use MyInvoice\Action\AresVies\ViesLookupAction;
use MyInvoice\Action\Auth\ChangePasswordAction;
use MyInvoice\Action\Client\ArchiveClientAction;
use MyInvoice\Action\Client\CreateClientAction;
use MyInvoice\Action\Client\DeleteClientAction;
use MyInvoice\Action\Client\GetClientAction;
use MyInvoice\Action\Client\ClientVatStatusAction;
use MyInvoice\Action\Client\ListClientsAction;
use MyInvoice\Action\Client\UpdateClientAction;
use MyInvoice\Action\Codebook\CodebookAction;
use MyInvoice\Action\Admin\ApprovalListAction;
use MyInvoice\Action\Admin\EmailTemplateAction;
use MyInvoice\Action\Approval\PublicApprovalDecideAction;
use MyInvoice\Action\Approval\PublicApprovalGetAction;
use MyInvoice\Action\Approval\RequestApprovalAction;
use MyInvoice\Action\Approval\RequestApprovalTestAction;
use MyInvoice\Action\Approval\UpdateApprovalStatusAction;
use MyInvoice\Action\Admin\ExportAction;
use MyInvoice\Action\Admin\ImportAction;
use MyInvoice\Action\Admin\Import\StartIdokladImportAction;
use MyInvoice\Action\Admin\Import\StartFakturoidImportAction;
use MyInvoice\Action\Admin\Import\ImportJobStatusAction;
use MyInvoice\Action\Admin\Import\CancelImportJobAction;
use MyInvoice\Action\Admin\Import\IdokladCredentialsAction;
use MyInvoice\Action\Admin\Import\FakturoidCredentialsAction;
use MyInvoice\Action\Admin\Import\AnthropicCredentialsAction;
use MyInvoice\Action\Admin\Import\AiExtractPdfAction;
use MyInvoice\Action\Crm\CrmDashboardAction;
use MyInvoice\Action\Report\DphPriznaniAction;
use MyInvoice\Action\Report\KontrolniHlaseniAction;
use MyInvoice\Action\Report\DphBookAction;
use MyInvoice\Action\Report\MonthlyExportAction;
use MyInvoice\Action\Report\SouhrnneHlaseniAction;
use MyInvoice\Action\Report\IncomeTaxAction;
use MyInvoice\Action\Admin\InvoicesZipAction;
use MyInvoice\Action\Admin\CronJobsAction;
use MyInvoice\Action\Admin\RunCronJobAction;
use MyInvoice\Action\Admin\ListActivityLogAction;
use MyInvoice\Action\Admin\ListSentEmailsAction;
use MyInvoice\Action\Admin\UserAdminAction;
use MyInvoice\Action\Settings\EmailBrandingAction;
use MyInvoice\Action\Settings\PdfSigningDiagnosticsAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Action\Settings\SignatureDocumentSelectionAction;
use MyInvoice\Action\Settings\SigningProfilesAction;
use MyInvoice\Action\Bank\BankEmailNoticeAction;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Action\Dashboard\PurchaseSummaryAction;
use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Action\Invoice\ExportCsvAction;
use MyInvoice\Action\Invoice\InvoiceActivityAction;
use MyInvoice\Action\Invoice\GetInvoiceAction;
use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Action\Invoice\ListInvoicesAction;
use MyInvoice\Action\Invoice\PreviewVarsymbolAction;
use MyInvoice\Action\Invoice\MarkPaidAction;
use MyInvoice\Action\Invoice\UnmarkPaidAction;
use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Action\Invoice\CloneInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\AdvanceCandidatesAction;
use MyInvoice\Action\PurchaseInvoice\SettlementCandidatesAction;
use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\DeletePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\DeletePurchaseInvoicePdfAction;
use MyInvoice\Action\PurchaseInvoice\DismissAdvanceSuggestionAction;
use MyInvoice\Action\PurchaseInvoice\DismissExtractionWarningAction;
use MyInvoice\Action\PurchaseInvoice\LinkAdvancePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\UnlinkAdvancePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\DownloadPurchaseInvoicePdfAction;
use MyInvoice\Action\PurchaseInvoice\OurPdfPurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\ExportPurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\ExportPurchaseInvoicesAction;
use MyInvoice\Action\PurchaseInvoice\GetPurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\ListPurchaseInvoicesAction;
use MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceActivityAction;
use MyInvoice\Action\PurchaseInvoice\ScanInboxAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceExchangeRateAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceItemsAction;
use MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\UploadPurchaseInvoicePdfAction;
use MyInvoice\Action\Recurring\RecurringTemplateAction;
use MyInvoice\Action\Invoice\IssueFinalFromProformaAction;
use MyInvoice\Action\Invoice\AdvanceCandidatesAction as InvoiceAdvanceCandidatesAction;
use MyInvoice\Action\Invoice\FinalCandidatesAction;
use MyInvoice\Action\Invoice\LinkAdvanceAction as LinkInvoiceAdvanceAction;
use MyInvoice\Action\Invoice\UnlinkAdvanceAction as UnlinkInvoiceAdvanceAction;
use MyInvoice\Action\Invoice\PdfAction;
use MyInvoice\Action\Invoice\ListPdfsAction;
use MyInvoice\Action\Invoice\DownloadArchivedPdfAction;
use MyInvoice\Action\Invoice\Attachment\ListAttachmentsAction;
use MyInvoice\Action\Invoice\Attachment\UploadAttachmentAction;
use MyInvoice\Action\Invoice\Attachment\DeleteAttachmentAction;
use MyInvoice\Action\Invoice\Attachment\DownloadAttachmentAction;
use MyInvoice\Action\Invoice\GetRecipientsAction;
use MyInvoice\Action\Invoice\SendEmailAction;
use MyInvoice\Action\Invoice\SendReminderAction;
use MyInvoice\Action\Invoice\BulkSendRemindersAction;
use MyInvoice\Action\Invoice\SendTestEmailAction;
use MyInvoice\Action\Invoice\SendTestReminderAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Action\WorkReport\GetWorkReportAction;
use MyInvoice\Action\WorkReport\SaveWorkReportAction;
use MyInvoice\Action\WorkReport\DeleteWorkReportAction;
use MyInvoice\Action\Project\ArchiveProjectAction;
use MyInvoice\Action\Project\CreateProjectAction;
use MyInvoice\Action\Project\DeleteProjectAction;
use MyInvoice\Action\Project\GetProjectAction;
use MyInvoice\Action\Project\ListProjectsAction;
use MyInvoice\Action\Project\ProjectStatsAction;
use MyInvoice\Action\Project\UpdateProjectAction;
use MyInvoice\Action\Auth\ApiMeAction;
use MyInvoice\Action\Auth\ForgotPasswordAction;
use MyInvoice\Action\Auth\LoginAction;
use MyInvoice\Action\Auth\LogoutAction;
use MyInvoice\Action\Auth\MeAction;
use MyInvoice\Action\Auth\ResetPasswordAction;
use MyInvoice\Action\Auth\SetupAction;
use MyInvoice\Action\Auth\SetupAresLookupAction;
use MyInvoice\Action\Auth\SetupCrpDphLookupAction;
use MyInvoice\Action\Auth\SetupSampleAction;
use MyInvoice\Action\Auth\SetupStatusAction;
use MyInvoice\Action\Auth\Tokens\CreateTokenAction;
use MyInvoice\Action\Auth\Tokens\ListTokensAction;
use MyInvoice\Action\Auth\Tokens\RevokeTokenAction;
use MyInvoice\Action\Auth\TotpAction;
use MyInvoice\Action\Document\FoldersAction;
use MyInvoice\Action\Document\DocumentsAction;
use MyInvoice\Action\Document\UploadDocumentAction;
use MyInvoice\Action\Document\DocumentFileAction;
use MyInvoice\Action\Document\LinkSearchAction;
use MyInvoice\Action\Document\DocumentJobsAction;
use MyInvoice\Action\System\HealthAction;
use MyInvoice\Action\System\OpenApiAction;
use MyInvoice\Action\System\VersionAction;
use MyInvoice\Action\Admin\UpdateAction;
use Slim\App;

final class Routes
{
    public static function register(App $app): void
    {
        $app->get('/api/health',  HealthAction::class);
        $app->get('/api/version', VersionAction::class);

        // Public REST API v1 — dokumentace
        $app->get('/api/openapi.yaml', [OpenApiAction::class, 'spec']);
        $app->get('/api/docs',         [OpenApiAction::class, 'docs']);       // Swagger UI (Try it out)
        $app->get('/api/reference',    [OpenApiAction::class, 'reference']);  // Redoc (pretty static)

        // Admin — kontrola a upgrade nové verze (M9, issue „Kontrola a upgrade")
        $app->get  ('/api/admin/update/status',  [UpdateAction::class, 'status']);
        $app->post ('/api/admin/update/refresh', [UpdateAction::class, 'refresh']);
        $app->post ('/api/admin/update/trigger', [UpdateAction::class, 'trigger']);
        $app->post ('/api/admin/update/cancel',  [UpdateAction::class, 'cancel']);

        $app->group('/api/auth', function ($g) {
            $g->get ('/setup-status',    SetupStatusAction::class);
            $g->post('/setup',           SetupAction::class);
            $g->post('/setup-ares-lookup', SetupAresLookupAction::class);  // public ARES proxy během setup wizardu
            $g->post('/setup-crpdph-lookup', SetupCrpDphLookupAction::class);  // public proxy do registru plátců DPH (účty z DIČ)
            $g->post('/setup-sample',    SetupSampleAction::class);         // public sample data generator (jen pokud nejsou data)
            $g->post('/login',           LoginAction::class);
            $g->post('/logout',          LogoutAction::class);
            $g->get ('/me',              MeAction::class);
            $g->get ('/api-me',          ApiMeAction::class);  // connection-test pro bearer i session
            $g->post('/change-password', ChangePasswordAction::class);
            $g->post('/forgot',          ForgotPasswordAction::class);
            $g->post('/reset',           ResetPasswordAction::class);
            // TOTP (2FA)
            $g->get ('/totp/status',     [TotpAction::class, 'status']);
            $g->post('/totp/setup',      [TotpAction::class, 'setup']);
            $g->post('/totp/enable',     [TotpAction::class, 'enable']);
            // API tokeny (Personal Access Tokens) — správa jen ze session auth
            $g->get   ('/tokens',                  ListTokensAction::class);
            $g->post  ('/tokens',                  CreateTokenAction::class);
            $g->delete('/tokens/{id:[0-9]+}',      RevokeTokenAction::class);
        });

        // ARES + VIES lookups (vyžadují auth)
        $app->post('/api/clients/lookup-ares', AresLookupAction::class);
        $app->post('/api/clients/lookup-vies', ViesLookupAction::class);
        $app->post('/api/clients/lookup-bank', CrpDphLookupAction::class);  // účty z DIČ přes registr plátců DPH

        // Globální vyhledávač pro sidebar (klienti/dodavatelé + vydané/přijaté faktury)
        $app->get('/api/search', \MyInvoice\Action\Search\GlobalSearchAction::class);

        // Codebooks
        $app->get('/api/codebooks/countries',  [CodebookAction::class, 'countries']);
        $app->get('/api/codebooks/currencies', [CodebookAction::class, 'currencies']);
        $app->get('/api/codebooks/vat-rates',  [CodebookAction::class, 'vatRates']);
        $app->get('/api/codebooks/units',      [CodebookAction::class, 'units']);
        $app->get('/api/codebooks/years',      [CodebookAction::class, 'years']);
        $app->get('/api/codebooks/cnb-rate',   \MyInvoice\Action\Codebook\CnbRateAction::class);

        // Expense categories (pro rozpad nákladů v CRM dashboardu)
        $app->get   ('/api/expense-categories',                  [\MyInvoice\Action\Codebook\ExpenseCategoriesAction::class, 'list']);
        $app->post  ('/api/expense-categories',                  [\MyInvoice\Action\Codebook\ExpenseCategoriesAction::class, 'create']);
        $app->put   ('/api/expense-categories/{id:[0-9]+}',      [\MyInvoice\Action\Codebook\ExpenseCategoriesAction::class, 'update']);
        $app->delete('/api/expense-categories/{id:[0-9]+}',      [\MyInvoice\Action\Codebook\ExpenseCategoriesAction::class, 'delete']);

        // Revenue categories (pro rozpad tržeb v CRM dashboardu + Stats)
        $app->get   ('/api/revenue-categories',                  [\MyInvoice\Action\Codebook\RevenueCategoriesAction::class, 'list']);
        $app->post  ('/api/revenue-categories',                  [\MyInvoice\Action\Codebook\RevenueCategoriesAction::class, 'create']);
        $app->put   ('/api/revenue-categories/{id:[0-9]+}',      [\MyInvoice\Action\Codebook\RevenueCategoriesAction::class, 'update']);
        $app->delete('/api/revenue-categories/{id:[0-9]+}',      [\MyInvoice\Action\Codebook\RevenueCategoriesAction::class, 'delete']);

        // Roční daňové konstanty (globální číselník, override defaultů z TaxConstants; migrace 0079)
        $app->get   ('/api/codebooks/tax-constants',                [\MyInvoice\Action\Codebook\TaxConstantsAction::class, 'list']);
        $app->put   ('/api/codebooks/tax-constants/{year:[0-9]+}',  [\MyInvoice\Action\Codebook\TaxConstantsAction::class, 'update']);
        $app->delete('/api/codebooks/tax-constants/{year:[0-9]+}',  [\MyInvoice\Action\Codebook\TaxConstantsAction::class, 'reset']);

        // VAT klasifikační kódy (pro DPHDP3 + KH)
        $app->get   ('/api/vat-classifications',                 [\MyInvoice\Action\Codebook\VatClassificationsAction::class, 'list']);
        $app->post  ('/api/vat-classifications',                 [\MyInvoice\Action\Codebook\VatClassificationsAction::class, 'create']);
        $app->put   ('/api/vat-classifications/{id:[0-9]+}',     [\MyInvoice\Action\Codebook\VatClassificationsAction::class, 'update']);
        $app->delete('/api/vat-classifications/{id:[0-9]+}',     [\MyInvoice\Action\Codebook\VatClassificationsAction::class, 'delete']);

        // Clients
        $app->get   ('/api/clients',                 ListClientsAction::class);
        $app->post  ('/api/clients',                 CreateClientAction::class);
        $app->get   ('/api/clients/{id:[0-9]+}',     GetClientAction::class);
        $app->get   ('/api/clients/{id:[0-9]+}/vat-status', ClientVatStatusAction::class);  // online ARES/VIES plátcovství
        $app->put   ('/api/clients/{id:[0-9]+}',     UpdateClientAction::class);
        $app->post  ('/api/clients/{id:[0-9]+}/archive',   ArchiveClientAction::class);
        $app->post  ('/api/clients/{id:[0-9]+}/unarchive', ArchiveClientAction::class);
        $app->delete('/api/clients/{id:[0-9]+}',           DeleteClientAction::class);

        // Projects
        $app->get   ('/api/clients/{client_id:[0-9]+}/projects', ListProjectsAction::class);
        $app->get   ('/api/projects/stats',          ProjectStatsAction::class);
        $app->get   ('/api/projects',                ListProjectsAction::class);
        $app->post  ('/api/projects',                CreateProjectAction::class);
        $app->get   ('/api/projects/{id:[0-9]+}',    GetProjectAction::class);
        $app->put   ('/api/projects/{id:[0-9]+}',    UpdateProjectAction::class);
        $app->post  ('/api/projects/{id:[0-9]+}/archive', ArchiveProjectAction::class);
        $app->delete('/api/projects/{id:[0-9]+}',         DeleteProjectAction::class);

        // Invoices (M3 — draft + editor + sumace; vystavení/odeslání/PDF přijde v M4)
        $app->get    ('/api/invoices',              ListInvoicesAction::class);
        $app->get    ('/api/invoices/export.csv',   ExportCsvAction::class);
        $app->get    ('/api/invoices/preview-varsymbol', PreviewVarsymbolAction::class);
        $app->post   ('/api/invoices',              CreateInvoiceAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}',  GetInvoiceAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/activity', InvoiceActivityAction::class);
        $app->put    ('/api/invoices/{id:[0-9]+}',  UpdateInvoiceAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}',  DeleteInvoiceAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/issue',     IssueInvoiceAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/mark-paid', MarkPaidAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/unmark-paid', UnmarkPaidAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/cancel',    CancelInvoiceAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/pdf',       PdfAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/pdfs',      ListPdfsAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/pdfs/{archiveId:[0-9]+}', DownloadArchivedPdfAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/attachments', ListAttachmentsAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/attachments', UploadAttachmentAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/attachments/{attId:[0-9]+}', DownloadAttachmentAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}/attachments/{attId:[0-9]+}', DeleteAttachmentAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/recipients', GetRecipientsAction::class);  // #86 vyřešení příjemců pro modal
        $app->post   ('/api/invoices/{id:[0-9]+}/send',      SendEmailAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/send-test', SendTestEmailAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/reminder',  SendReminderAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/reminder-test', SendTestReminderAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/issue-final', IssueFinalFromProformaAction::class);
        // Zpětné propojení daňového dokladu se zálohovou fakturou (proforma)
        $app->get    ('/api/invoices/{id:[0-9]+}/advance-candidates', InvoiceAdvanceCandidatesAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/final-candidates',   FinalCandidatesAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/link-advance',       LinkInvoiceAdvanceAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}/link-advance',       UnlinkInvoiceAdvanceAction::class);
        $app->post   ('/api/invoices/bulk-reissue',          BulkReissueAction::class);
        $app->post   ('/api/invoices/bulk-reminder',         BulkSendRemindersAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/clone',     CloneInvoiceAction::class);
        $app->get    ('/api/documents/{entity_type:invoice|work_report}/{id:[0-9]+}/signature-selection', [SignatureDocumentSelectionAction::class, 'get']);
        $app->put    ('/api/documents/{entity_type:invoice|work_report}/{id:[0-9]+}/signature-selection', [SignatureDocumentSelectionAction::class, 'put']);
        $app->delete ('/api/documents/{entity_type:invoice|work_report}/{id:[0-9]+}/signature-selection', [SignatureDocumentSelectionAction::class, 'delete']);

        // Přijaté faktury (purchase invoices) — fáze 1 integrace forku.
        // Všechny chráněné AuthMiddleware + SupplierScopeMiddleware (skrz globální group).
        // scan-inbox je admin/accountant only (check v Action).
        $app->post   ('/api/purchase-invoices/scan-inbox',                ScanInboxAction::class);
        $app->get    ('/api/purchase-invoices/export',                     ExportPurchaseInvoicesAction::class);
        $app->get    ('/api/purchase-invoices',                           ListPurchaseInvoicesAction::class);
        $app->post   ('/api/purchase-invoices',                           CreatePurchaseInvoiceAction::class);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}',                GetPurchaseInvoiceAction::class);
        $app->put    ('/api/purchase-invoices/{id:[0-9]+}',                UpdatePurchaseInvoiceAction::class);
        $app->delete ('/api/purchase-invoices/{id:[0-9]+}',                DeletePurchaseInvoiceAction::class);
        $app->put    ('/api/purchase-invoices/{id:[0-9]+}/items',          SetPurchaseInvoiceItemsAction::class);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/exchange-rate', SetPurchaseInvoiceExchangeRateAction::class);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/transition',     TransitionPurchaseInvoiceStatusAction::class);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/dismiss-extraction-warning', DismissExtractionWarningAction::class);
        // Propojení se zálohovou fakturou (advance) — proti dvojímu započtení nákladu
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/advance-candidates', AdvanceCandidatesAction::class);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/settlement-candidates', SettlementCandidatesAction::class);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/link-advance',     LinkAdvancePurchaseInvoiceAction::class);
        $app->delete ('/api/purchase-invoices/{id:[0-9]+}/link-advance',     UnlinkAdvancePurchaseInvoiceAction::class);
        $app->delete ('/api/purchase-invoices/{id:[0-9]+}/advance-suggestion', DismissAdvanceSuggestionAction::class);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/pdf',            UploadPurchaseInvoicePdfAction::class);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/pdf',            DownloadPurchaseInvoicePdfAction::class);
        $app->delete ('/api/purchase-invoices/{id:[0-9]+}/pdf',            DeletePurchaseInvoicePdfAction::class);
        // Our generated PDF + Pohoda/ISDOC export pro přijatou
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/our-pdf',        OurPdfPurchaseInvoiceAction::class);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/isdoc',          [ExportPurchaseInvoiceAction::class, 'isdoc']);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/pohoda',         [ExportPurchaseInvoiceAction::class, 'pohoda']);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/activity',       PurchaseInvoiceActivityAction::class);

        // Pravidelné fakturace (recurring templates)
        $app->get    ('/api/recurring',                       [RecurringTemplateAction::class, 'list']);
        $app->post   ('/api/recurring',                       [RecurringTemplateAction::class, 'create']);
        $app->get    ('/api/recurring/{id:[0-9]+}',           [RecurringTemplateAction::class, 'get']);
        $app->get    ('/api/recurring/{id:[0-9]+}/invoices',  [RecurringTemplateAction::class, 'invoices']);
        $app->put    ('/api/recurring/{id:[0-9]+}',           [RecurringTemplateAction::class, 'update']);
        $app->delete ('/api/recurring/{id:[0-9]+}',           [RecurringTemplateAction::class, 'delete']);
        $app->post   ('/api/recurring/{id:[0-9]+}/pause',     [RecurringTemplateAction::class, 'pause']);
        $app->post   ('/api/recurring/{id:[0-9]+}/resume',    [RecurringTemplateAction::class, 'resume']);
        $app->post   ('/api/recurring/{id:[0-9]+}/run-now',   [RecurringTemplateAction::class, 'runNow']);

        // Work reports — výkaz víceprací (M5)
        $app->get    ('/api/invoices/{id:[0-9]+}/work-report', GetWorkReportAction::class);
        $app->put    ('/api/invoices/{id:[0-9]+}/work-report', SaveWorkReportAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}/work-report', DeleteWorkReportAction::class);

        // Schvalování výkazu zákazníkem (M8)
        $app->post   ('/api/invoices/{id:[0-9]+}/request-approval',      RequestApprovalAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/request-approval-test', RequestApprovalTestAction::class);
        $app->put    ('/api/invoices/{id:[0-9]+}/approval-status',       UpdateApprovalStatusAction::class);

        // Public schvalovací endpointy (bez auth, jen token)
        $app->get    ('/api/public/approval/{token:[a-f0-9]{32,128}}',          PublicApprovalGetAction::class);
        $app->post   ('/api/public/approval/{token:[a-f0-9]{32,128}}/decide',   PublicApprovalDecideAction::class);

        // Dashboard
        $app->get ('/api/dashboard/summary',          SummaryAction::class);
        $app->get ('/api/dashboard/purchase-summary', PurchaseSummaryAction::class);

        // Admin (M6)
        $app->get    ('/api/admin/activity-log',    ListActivityLogAction::class);
        $app->get    ('/api/admin/sent-emails',     ListSentEmailsAction::class);
        $app->get    ('/api/admin/cron-jobs',       CronJobsAction::class);
        $app->post   ('/api/admin/cron-jobs/{script:cron-[a-z0-9-]+}/run', RunCronJobAction::class);
        $app->get    ('/api/admin/invoices-zip',    InvoicesZipAction::class);  // legacy — drží se kvůli historickým bookmark URL
        $app->get    ('/api/admin/export',          ExportAction::class);       // generic export (?format=pdf-zip|isdoc|pohoda&month=YYYY-MM nebo period=quarterly)
        $app->post   ('/api/admin/import',          ImportAction::class);       // import vystavených faktur z Pohoda XML / ISDOC (single nebo ZIP)

        // iDoklad API import (fáze 2a) — credentials + background job lifecycle
        $app->get    ('/api/admin/imports/idoklad/credentials', [IdokladCredentialsAction::class, 'status']);
        $app->put    ('/api/admin/imports/idoklad/credentials', [IdokladCredentialsAction::class, 'update']);
        $app->delete ('/api/admin/imports/idoklad/credentials', [IdokladCredentialsAction::class, 'delete']);
        $app->post   ('/api/admin/imports/idoklad/start',       StartIdokladImportAction::class);

        // Fakturoid (fáze 2b) — credentials + start
        $app->get    ('/api/admin/imports/fakturoid/credentials', [FakturoidCredentialsAction::class, 'status']);
        $app->put    ('/api/admin/imports/fakturoid/credentials', [FakturoidCredentialsAction::class, 'update']);
        $app->delete ('/api/admin/imports/fakturoid/credentials', [FakturoidCredentialsAction::class, 'delete']);
        $app->post   ('/api/admin/imports/fakturoid/start',       StartFakturoidImportAction::class);

        // Anthropic Claude AI extraction (fáze 2c) — BYOK + synchronní PDF extract
        $app->get    ('/api/admin/imports/anthropic/credentials', [AnthropicCredentialsAction::class, 'status']);
        $app->put    ('/api/admin/imports/anthropic/credentials', [AnthropicCredentialsAction::class, 'update']);
        $app->delete ('/api/admin/imports/anthropic/credentials', [AnthropicCredentialsAction::class, 'delete']);
        $app->post   ('/api/admin/imports/ai-extract-pdf',        AiExtractPdfAction::class);

        // CRM dashboard (fáze 5)
        $app->get    ('/api/crm/overview',     [CrmDashboardAction::class, 'overview']);
        $app->get    ('/api/crm/monthly',      [CrmDashboardAction::class, 'monthly']);
        $app->get    ('/api/crm/top-clients',  [CrmDashboardAction::class, 'topClients']);
        $app->get    ('/api/crm/top-vendors',  [CrmDashboardAction::class, 'topVendors']);
        $app->get    ('/api/crm/aging-receivables', [CrmDashboardAction::class, 'agingReceivables']);
        $app->get    ('/api/crm/aging-payables',    [CrmDashboardAction::class, 'agingPayables']);
        $app->get    ('/api/crm/yearly',            [CrmDashboardAction::class, 'yearly']);
        $app->get    ('/api/crm/dso',               [CrmDashboardAction::class, 'dso']);
        $app->get    ('/api/crm/payment-punctuality', [CrmDashboardAction::class, 'punctuality']);
        $app->get    ('/api/crm/concentration',     [CrmDashboardAction::class, 'concentration']);
        $app->get    ('/api/crm/vendor-concentration', [CrmDashboardAction::class, 'vendorConcentration']);
        $app->get    ('/api/crm/dpo',               [CrmDashboardAction::class, 'dpo']);
        $app->get    ('/api/crm/expense-breakdown', [CrmDashboardAction::class, 'expenseBreakdown']);
        $app->get    ('/api/crm/revenue-breakdown', [CrmDashboardAction::class, 'revenueBreakdown']);
        $app->get    ('/api/crm/churn-risk',        [CrmDashboardAction::class, 'churnRisk']);
        $app->get    ('/api/crm/action-items',      [CrmDashboardAction::class, 'actionItems']);
        $app->post   ('/api/crm/action-items/dismiss', [CrmDashboardAction::class, 'dismissActionItem']);
        $app->post   ('/api/crm/action-items/restore', [CrmDashboardAction::class, 'restoreActionItem']);
        $app->post   ('/api/crm/action-items/restore-all', [CrmDashboardAction::class, 'restoreAllActionItems']);
        $app->get    ('/api/crm/cash-flow-forecast', [CrmDashboardAction::class, 'cashFlowForecast']);
        $app->get    ('/api/crm/late-risk',         [CrmDashboardAction::class, 'lateRisk']);
        $app->get    ('/api/crm/reminder-effectiveness', [CrmDashboardAction::class, 'reminderEffectiveness']);
        $app->get    ('/api/crm/payment-time-histogram', [CrmDashboardAction::class, 'paymentTimeHistogram']);
        $app->post   ('/api/crm/recompute',    [CrmDashboardAction::class, 'recompute']);

        // EPO výkazy (fáze 6) — DPH přiznání DPHDP3
        $app->get    ('/api/reports/dphdp3/settings', [DphPriznaniAction::class, 'settings']);
        $app->get    ('/api/reports/dphdp3/preview',  [DphPriznaniAction::class, 'preview']);
        $app->get    ('/api/reports/dphdp3/trend',    [DphPriznaniAction::class, 'trend']);
        $app->get    ('/api/reports/dphdp3/drafts-prediction', [DphPriznaniAction::class, 'draftsPrediction']);
        $app->get    ('/api/reports/dphdp3',          [DphPriznaniAction::class, 'download']);
        // Kontrolní hlášení DPHKH1 (vždy měsíční)
        $app->get    ('/api/reports/dphkh1/preview',  [KontrolniHlaseniAction::class, 'preview']);
        $app->get    ('/api/reports/dphkh1',          [KontrolniHlaseniAction::class, 'download']);
        // Kniha DPH (interní VAT žurnál — NE EPO podání, vždy měsíční)
        $app->get    ('/api/reports/dph-book/preview', [DphBookAction::class, 'preview']);
        $app->get    ('/api/reports/dph-book',         [DphBookAction::class, 'download']);
        // Měsíční export — background job: jeden ZIP s vybranými exporty za měsíc
        // (VF/PF PDF+ISDOC, výpisy PDF+GPC, Kniha DPH). Běží na pozadí (import_jobs).
        $app->get    ('/api/reports/monthly-export/preview',                  [MonthlyExportAction::class, 'preview']);
        $app->post   ('/api/reports/monthly-export/start',                    [MonthlyExportAction::class, 'start']);
        $app->get    ('/api/reports/monthly-export/jobs',                     [MonthlyExportAction::class, 'list']);
        $app->get    ('/api/reports/monthly-export/jobs/{id:[0-9]+}',          [MonthlyExportAction::class, 'jobStatus']);
        $app->get    ('/api/reports/monthly-export/jobs/{id:[0-9]+}/download', [MonthlyExportAction::class, 'download']);
        $app->post   ('/api/reports/monthly-export/jobs/{id:[0-9]+}/cancel',   [MonthlyExportAction::class, 'cancel']);
        $app->delete ('/api/reports/monthly-export/jobs/{id:[0-9]+}',          [MonthlyExportAction::class, 'delete']);
        // Souhrnné hlášení DPHSHV (EU dodání, měsíční — podávají i identifikované osoby)
        $app->get    ('/api/reports/dphshv/preview',  [SouhrnneHlaseniAction::class, 'preview']);
        $app->get    ('/api/reports/dphshv',          [SouhrnneHlaseniAction::class, 'download']);
        // Daň z příjmů FO/PO (MVP foundation — kostra XML s warning)
        $app->get    ('/api/reports/income-tax/preview', [IncomeTaxAction::class, 'preview']);
        $app->get    ('/api/reports/income-tax',         [IncomeTaxAction::class, 'download']);
        // Tax submission archive (historie všech generovaných EPO XML)
        $app->get    ('/api/reports/submissions',                 [\MyInvoice\Action\Report\TaxSubmissionAction::class, 'list']);
        $app->get    ('/api/reports/submissions/{id:[0-9]+}',     [\MyInvoice\Action\Report\TaxSubmissionAction::class, 'detail']);
        $app->get    ('/api/reports/submissions/{id:[0-9]+}/xml', [\MyInvoice\Action\Report\TaxSubmissionAction::class, 'downloadXml']);
        $app->delete ('/api/reports/submissions/{id:[0-9]+}',     [\MyInvoice\Action\Report\TaxSubmissionAction::class, 'delete']);

        $app->get    ('/api/admin/imports/{id:[0-9]+}',         ImportJobStatusAction::class);
        $app->post   ('/api/admin/imports/{id:[0-9]+}/cancel',  CancelImportJobAction::class);
        $app->delete ('/api/admin/imports/{id:[0-9]+}',         \MyInvoice\Action\Admin\Import\DeleteImportJobAction::class);
        $app->get    ('/api/admin/users',           [UserAdminAction::class, 'list']);
        $app->post   ('/api/admin/users',           [UserAdminAction::class, 'create']);
        $app->put    ('/api/admin/users/{id:[0-9]+}', [UserAdminAction::class, 'update']);
        $app->delete ('/api/admin/users/{id:[0-9]+}', [UserAdminAction::class, 'delete']);

        // Approval inbox (admin only) — globální seznam schvalování
        $app->get    ('/api/admin/approvals',       ApprovalListAction::class);

        // Email šablony (admin only)
        $app->get    ('/api/admin/email-templates',                                  [EmailTemplateAction::class, 'list']);
        $app->get    ('/api/admin/email-templates/{code:[a-z_]+}/{locale:cs|en}',    [EmailTemplateAction::class, 'get']);
        $app->put    ('/api/admin/email-templates/{code:[a-z_]+}/{locale:cs|en}',    [EmailTemplateAction::class, 'put']);
        $app->delete ('/api/admin/email-templates/{code:[a-z_]+}/{locale:cs|en}',    [EmailTemplateAction::class, 'delete']);

        // Multi-supplier (M7)
        $app->get    ('/api/suppliers',                     [SettingsAction::class, 'listSuppliers']);
        $app->post   ('/api/suppliers',                     [SettingsAction::class, 'createSupplier']);
        $app->get    ('/api/suppliers/{id:[0-9]+}',         [SettingsAction::class, 'getSupplierById']);
        $app->put    ('/api/suppliers/{id:[0-9]+}',         [SettingsAction::class, 'updateSupplierById']);
        $app->delete ('/api/suppliers/{id:[0-9]+}',         [SettingsAction::class, 'deleteSupplierById']);

        // Settings (M6) — aktuální supplier (z X-Supplier-Id)
        $app->get ('/api/settings/supplier',                [SettingsAction::class, 'getSupplier']);
        $app->put ('/api/settings/supplier',                [SettingsAction::class, 'updateSupplier']);
        $app->get    ('/api/settings/pdf-signing/diagnostics', PdfSigningDiagnosticsAction::class);
        $app->get    ('/api/settings/pdf-signing',          [SigningProfilesAction::class, 'pdfSettings']);
        $app->post   ('/api/settings/pdf-signing/test',     [SigningProfilesAction::class, 'testPdfSigning']);
        $app->put    ('/api/settings/pdf-signing/output-settings/{output_type:[a-z_]+}', [SigningProfilesAction::class, 'updatePdfOutputSetting']);
        $app->get    ('/api/settings/pdf-signing/user-defaults', [SigningProfilesAction::class, 'userDefaults']);
        $app->put    ('/api/settings/pdf-signing/user-defaults/{output_type:[a-z_]+}', [SigningProfilesAction::class, 'updateUserDefault']);
        $app->get    ('/api/settings/signing',              [SigningProfilesAction::class, 'settings']);
        $app->put    ('/api/settings/signing',              [SigningProfilesAction::class, 'updateSettings']);
        $app->get    ('/api/settings/signing/profiles',              [SigningProfilesAction::class, 'listProfiles']);
        $app->post   ('/api/settings/signing/profiles',              [SigningProfilesAction::class, 'createProfile']);
        $app->get    ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/certificate', [SigningProfilesAction::class, 'credentialCertificate']);
        $app->post   ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/certificate', [SigningProfilesAction::class, 'uploadCredentialCertificate']);
        $app->put    ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/certificate', [SigningProfilesAction::class, 'updateCredentialCertificate']);
        $app->delete ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/certificate', [SigningProfilesAction::class, 'deleteCredentialCertificate']);
        $app->get    ('/api/settings/signing/profiles/{id:[0-9]+}', [SigningProfilesAction::class, 'getProfile']);
        $app->put    ('/api/settings/signing/profiles/{id:[0-9]+}', [SigningProfilesAction::class, 'updateProfile']);
        $app->delete ('/api/settings/signing/profiles/{id:[0-9]+}', [SigningProfilesAction::class, 'deleteProfile']);
        $app->get    ('/api/settings/currencies',                     [SettingsAction::class, 'listCurrencies']);
        $app->post   ('/api/settings/currencies',                     [SettingsAction::class, 'createCurrency']);
        $app->put    ('/api/settings/currencies/{id:[0-9]+}',         [SettingsAction::class, 'updateCurrency']);
        $app->delete ('/api/settings/currencies/{id:[0-9]+}',         [SettingsAction::class, 'deleteCurrency']);
        $app->get    ('/api/settings/bank-email-notices',             [BankEmailNoticeAction::class, 'overview']);
        $app->put    ('/api/settings/bank-email-notices/imap',        [BankEmailNoticeAction::class, 'updateImap']);
        $app->post   ('/api/settings/bank-email-notices/imap/test',   [BankEmailNoticeAction::class, 'testImap']);
        $app->post   ('/api/settings/bank-email-notices/imap-accounts', [BankEmailNoticeAction::class, 'createImapAccount']);
        $app->post   ('/api/settings/bank-email-notices/imap-accounts/folders', [BankEmailNoticeAction::class, 'browseImapFolders']);
        $app->put    ('/api/settings/bank-email-notices/imap-accounts/{id:[0-9]+}', [BankEmailNoticeAction::class, 'updateImapAccount']);
        $app->delete ('/api/settings/bank-email-notices/imap-accounts/{id:[0-9]+}', [BankEmailNoticeAction::class, 'deleteImapAccount']);
        $app->post   ('/api/settings/bank-email-notices/imap-accounts/{id:[0-9]+}/test', [BankEmailNoticeAction::class, 'testImapAccount']);
        $app->post   ('/api/settings/bank-email-notices/imap-accounts/{id:[0-9]+}/folders', [BankEmailNoticeAction::class, 'browseImapFolders']);
        $app->post   ('/api/settings/bank-email-notices/providers',   [BankEmailNoticeAction::class, 'createProvider']);
        $app->put    ('/api/settings/bank-email-notices/providers/{id:[0-9]+}', [BankEmailNoticeAction::class, 'updateProvider']);
        $app->delete ('/api/settings/bank-email-notices/providers/{id:[0-9]+}', [BankEmailNoticeAction::class, 'deleteProvider']);
        $app->put    ('/api/settings/bank-email-notices/mappings',    [BankEmailNoticeAction::class, 'updateMappings']);
        $app->post   ('/api/settings/bank-email-notices/parser/test', [BankEmailNoticeAction::class, 'testParser']);
        $app->post   ('/api/settings/bank-email-notices/scan',        [BankEmailNoticeAction::class, 'scan']);
        $app->get    ('/api/settings/bank-email-notices/messages',    [BankEmailNoticeAction::class, 'messages']);
        $app->delete ('/api/settings/bank-email-notices/messages/{id:[0-9]+}', [BankEmailNoticeAction::class, 'deleteMessage']);

        $app->get    ('/api/settings/vat-rates',                      [SettingsAction::class, 'listVatRates']);
        $app->post   ('/api/settings/vat-rates',                      [SettingsAction::class, 'createVatRate']);
        $app->put    ('/api/settings/vat-rates/{id:[0-9]+}',          [SettingsAction::class, 'updateVatRate']);
        $app->delete ('/api/settings/vat-rates/{id:[0-9]+}',          [SettingsAction::class, 'deleteVatRate']);

        $app->get    ('/api/settings/countries',                      [SettingsAction::class, 'listCountries']);
        $app->post   ('/api/settings/countries',                      [SettingsAction::class, 'createCountry']);
        $app->put    ('/api/settings/countries/{id:[0-9]+}',          [SettingsAction::class, 'updateCountry']);
        $app->delete ('/api/settings/countries/{id:[0-9]+}',          [SettingsAction::class, 'deleteCountry']);

        // Email branding (M16) — per-supplier logo + accent color v hlavičce odchozích emailů
        $app->post   ('/api/settings/email-branding/logo',            [EmailBrandingAction::class, 'uploadLogo']);
        $app->delete ('/api/settings/email-branding/logo',            [EmailBrandingAction::class, 'deleteLogo']);
        $app->get    ('/api/settings/email-branding/preview',         [EmailBrandingAction::class, 'preview']);

        $app->get    ('/api/settings/units',                          [SettingsAction::class, 'listUnits']);
        $app->post   ('/api/settings/units',                          [SettingsAction::class, 'createUnit']);
        $app->put    ('/api/settings/units/{id:[0-9]+}',              [SettingsAction::class, 'updateUnit']);
        $app->delete ('/api/settings/units/{id:[0-9]+}',              [SettingsAction::class, 'deleteUnit']);

        // Tax optimizer — daňový optimalizátor (srovnání režimů + predikce limitů)
        $app->get ('/api/tax/analysis',  [\MyInvoice\Action\Tax\TaxAction::class, 'analysis']);
        $app->put ('/api/tax/profile',   [\MyInvoice\Action\Tax\TaxAction::class, 'updateProfile']);

        // Bank statements (M5b)
        $app->post ('/api/bank-statements/upload',           [BankStatementAction::class, 'upload']);
        $app->post ('/api/bank-statements/scan',             [BankStatementAction::class, 'scan']);
        $app->get  ('/api/bank-statements',                  [BankStatementAction::class, 'list']);
        $app->get  ('/api/bank-statements/{id:[0-9]+}',      [BankStatementAction::class, 'detail']);
        $app->get  ('/api/bank-statements/{id:[0-9]+}/download', [BankStatementAction::class, 'download']);
        $app->post ('/api/bank-statements/{id:[0-9]+}/pdf',  [BankStatementAction::class, 'uploadPdf']);
        $app->get  ('/api/bank-statements/{id:[0-9]+}/pdf',  [BankStatementAction::class, 'downloadPdf']);
        $app->delete('/api/bank-statements/{id:[0-9]+}/pdf', [BankStatementAction::class, 'deletePdf']);
        $app->delete('/api/bank-statements/{id:[0-9]+}',     [BankStatementAction::class, 'delete']);
        $app->post ('/api/bank-statements/{id:[0-9]+}/rematch', [BankStatementAction::class, 'rematch']);
        $app->get  ('/api/bank-transactions/{id:[0-9]+}/match-candidates', [BankStatementAction::class, 'matchCandidates']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/match',   [BankStatementAction::class, 'manualMatch']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/unmatch', [BankStatementAction::class, 'unmatch']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/ignore',  [BankStatementAction::class, 'ignore']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/create-purchase-invoice', [BankStatementAction::class, 'createPurchaseInvoice']);

        // Dokumenty (sekce Dokumenty — plán source/11)
        // Specifické cesty PŘED {id:[0-9]+}, aby je fast-route nepohltil.
        $app->get   ('/api/document-folders',                     [FoldersAction::class, 'list']);
        $app->post  ('/api/document-folders',                     [FoldersAction::class, 'create']);
        $app->patch ('/api/document-folders/{id:[0-9]+}',         [FoldersAction::class, 'rename']);
        $app->post  ('/api/document-folders/{id:[0-9]+}/move',    [FoldersAction::class, 'move']);
        $app->post  ('/api/document-folders/{id:[0-9]+}/restore', [FoldersAction::class, 'restore']);
        $app->delete('/api/document-folders/{id:[0-9]+}',         [FoldersAction::class, 'delete']);

        $app->get   ('/api/documents/search',         [DocumentsAction::class, 'search']);
        $app->get   ('/api/documents/link-search',    LinkSearchAction::class);
        // Background joby (rozbalení ZIP importu / ZIP export)
        $app->post  ('/api/documents/zip-import',     [DocumentJobsAction::class, 'zipImport']);
        $app->post  ('/api/documents/export',         [DocumentJobsAction::class, 'export']);
        // Chunkovaný upload (obchází PHP post_max_size) — velký ZIP / složka / velký soubor
        $app->post  ('/api/documents/upload/start',       [DocumentJobsAction::class, 'uploadStart']);
        $app->post  ('/api/documents/upload/chunk-bytes', [DocumentJobsAction::class, 'uploadChunkBytes']);
        $app->post  ('/api/documents/upload/chunk-files', [DocumentJobsAction::class, 'uploadChunkFiles']);
        $app->post  ('/api/documents/upload/finish',      [DocumentJobsAction::class, 'uploadFinish']);
        $app->get   ('/api/documents/jobs',           [DocumentJobsAction::class, 'list']);
        $app->get   ('/api/documents/jobs/{id:[0-9]+}',          [DocumentJobsAction::class, 'status']);
        $app->get   ('/api/documents/jobs/{id:[0-9]+}/download', [DocumentJobsAction::class, 'download']);
        $app->post  ('/api/documents/jobs/{id:[0-9]+}/cancel',   [DocumentJobsAction::class, 'cancel']);
        $app->delete('/api/documents/jobs/{id:[0-9]+}',          [DocumentJobsAction::class, 'delete']);
        $app->get   ('/api/documents/tags',           [DocumentsAction::class, 'listTags']);
        $app->get   ('/api/documents/trash',          [DocumentsAction::class, 'trash']);
        $app->post  ('/api/documents/trash/empty',    [DocumentsAction::class, 'emptyTrash']);
        $app->post  ('/api/documents/bulk',           [DocumentsAction::class, 'bulk']);
        $app->get   ('/api/documents/bulk-download',  [DocumentFileAction::class, 'bulkDownload']);
        $app->get   ('/api/documents/by-entity/{type:[a-z_]+}/{id:[0-9]+}', [DocumentsAction::class, 'byEntity']);
        $app->get   ('/api/documents',                [DocumentsAction::class, 'list']);
        $app->post  ('/api/documents',                UploadDocumentAction::class);
        $app->get   ('/api/documents/{id:[0-9]+}',            [DocumentsAction::class, 'get']);
        $app->patch ('/api/documents/{id:[0-9]+}',            [DocumentsAction::class, 'update']);
        $app->post  ('/api/documents/{id:[0-9]+}/move',       [DocumentsAction::class, 'move']);
        $app->post  ('/api/documents/{id:[0-9]+}/restore',    [DocumentsAction::class, 'restore']);
        $app->post  ('/api/documents/{id:[0-9]+}/links',      [DocumentsAction::class, 'addLink']);
        $app->delete('/api/documents/{id:[0-9]+}/links',      [DocumentsAction::class, 'removeLink']);
        $app->delete('/api/documents/{id:[0-9]+}',            [DocumentsAction::class, 'delete']);
        $app->get   ('/api/documents/{id:[0-9]+}/download',   [DocumentFileAction::class, 'download']);
        $app->get   ('/api/documents/{id:[0-9]+}/preview',    [DocumentFileAction::class, 'preview']);
        $app->get   ('/api/documents/{id:[0-9]+}/thumb',      [DocumentFileAction::class, 'thumb']);

        // 404 fallback pro /api/*
        $app->any('/api/{path:.*}', function ($req, $res) {
            return \MyInvoice\Http\Json::error($res, 'not_found', 'Route not found', 404);
        });
    }
}

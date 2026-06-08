<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Static source-level guard for FR-44 signing profile selection.
 *
 * If PDF signing is mapped to the signed-in user, every interactive/export path
 * with a known user must pass that user id into InvoicePdfRenderer::render().
 */
final class PdfSigningUserContextTest extends TestCase
{
    public function testInvoicePdfExportPathsPassUserIdToRenderer(): void
    {
        $root = dirname(__DIR__, 3);
        $expectations = [
            '/api/src/Action/Admin/ExportAction.php' => [
                'buildPdfZip($ids, $period, $type, $userId)',
                '$this->pdf->render($id, false, $userId)',
            ],
            '/api/src/Action/Admin/InvoicesZipAction.php' => [
                '$this->pdf->render((int) $inv[\'id\'], false, $userId)',
            ],
            '/api/src/Service/Export/MonthlyExportService.php' => [
                '$this->invoicePdf->render($id, false, $userId)',
            ],
            '/api/src/Service/Invoice/AutoIssueAndSendService.php' => [
                '$this->renderer->render($invoiceId, false, $userId)',
            ],
            '/api/src/Service/Mail/PaymentThanksMailer.php' => [
                '$this->renderer->render($invoiceId, false, $userId)',
            ],
        ];

        foreach ($expectations as $rel => $needles) {
            $code = file_get_contents($root . $rel);
            self::assertIsString($code, "Nenalezen $rel");
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $code, "$rel nepředává userId do PDF rendereru.");
            }
        }
    }

    public function testInvoicePdfExportPathsDoNotUseContextlessRendererCall(): void
    {
        $root = dirname(__DIR__, 3);
        $forbidden = [
            '/api/src/Action/Admin/ExportAction.php' => [
                '$this->pdf->render($id);',
            ],
            '/api/src/Action/Admin/InvoicesZipAction.php' => [
                '$this->pdf->render((int) $inv[\'id\']);',
            ],
            '/api/src/Service/Export/MonthlyExportService.php' => [
                '$this->invoicePdf->render($id);',
            ],
            '/api/src/Service/Invoice/AutoIssueAndSendService.php' => [
                '$this->renderer->render($invoiceId);',
            ],
            '/api/src/Service/Mail/PaymentThanksMailer.php' => [
                '$this->renderer->render($invoiceId);',
            ],
        ];

        foreach ($forbidden as $rel => $needles) {
            $code = file_get_contents($root . $rel);
            self::assertIsString($code, "Nenalezen $rel");
            foreach ($needles as $needle) {
                self::assertStringNotContainsString($needle, $code, "$rel volá PDF renderer bez userId.");
            }
        }
    }

    public function testPromptOnUseIsDisabledInCurrentPdfUiAndApi(): void
    {
        $root = dirname(__DIR__, 3);

        $action = file_get_contents($root . '/api/src/Action/Settings/SigningProfilesAction.php');
        self::assertIsString($action);
        self::assertStringContainsString('prompt_on_use_unsupported', $action);

        $ui = file_get_contents($root . '/web/src/pages/admin/ElectronicSignatures.vue');
        self::assertIsString($ui);
        self::assertStringNotContainsString('<option value="prompt_on_use"', $ui);
    }

    public function testPdfSigningOutputSettingsInvalidateCachedInvoicePdfs(): void
    {
        $root = dirname(__DIR__, 3);
        $action = file_get_contents($root . '/api/src/Action/Settings/SigningProfilesAction.php');
        self::assertIsString($action);

        self::assertStringContainsString('$before = $this->profiles->outputSetting($supplierId, $outputType, $usage);', $action);
        self::assertStringContainsString("if (\$usage === 'pdf' && \$this->pdfOutputSettingChanged(\$before, \$updated))", $action);
        self::assertStringContainsString('invalidatePdfCacheForOutputSetting($supplierId, $outputType)', $action);
        self::assertStringContainsString('invalidate_signature_config', $action);
        self::assertStringContainsString('work_reports wr WHERE wr.invoice_id = i.id', $action);
    }
}

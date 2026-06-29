<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IdokladClient;
use PHPUnit\Framework\TestCase;

/**
 * iDoklad v2 GetPdf vrací PDF jako bare base64 JSON string — celé tělo je
 * "JVBERi0..." (NE objekt s polem Data). Dřív se PDF stahovalo z neexistujícího
 * v3 /Document endpointu (404 → null), takže vydaným fakturám se nikdy
 * nearchivovalo PDF (imported_pdf_path zůstalo NULL, bez chyby).
 */
final class IdokladIssuedPdfDecodeTest extends TestCase
{
    public function testDecodesBareBase64JsonStringToPdf(): void
    {
        $pdf  = "%PDF-1.4\n%\xC2\xA3\ntest body\n%%EOF";
        $body = json_encode(base64_encode($pdf)); // "JVBERi0..."

        self::assertSame($pdf, IdokladClient::decodeIssuedPdfBody((string) $body));
    }

    public function testRejectsJsonObjectInsteadOfString(): void
    {
        // Kdyby přišel envelope { "Data": "..." } místo bare stringu.
        $body = json_encode(['Data' => base64_encode('%PDF-1.4 x')]);

        self::assertNull(IdokladClient::decodeIssuedPdfBody((string) $body));
    }

    public function testRejectsValidBase64ThatIsNotPdf(): void
    {
        $body = json_encode(base64_encode('not a pdf at all'));

        self::assertNull(IdokladClient::decodeIssuedPdfBody((string) $body));
    }

    public function testRejectsNonJsonBody(): void
    {
        self::assertNull(IdokladClient::decodeIssuedPdfBody('<html>404</html>'));
    }

    public function testRejectsEmptyBody(): void
    {
        self::assertNull(IdokladClient::decodeIssuedPdfBody(''));
        self::assertNull(IdokladClient::decodeIssuedPdfBody('""'));
    }
}

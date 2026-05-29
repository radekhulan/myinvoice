<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Pdf;

use MyInvoice\Service\Pdf\Asn1;
use PHPUnit\Framework\TestCase;

final class Asn1Test extends TestCase
{
    public function testEncodeLenShortAndLong(): void
    {
        self::assertSame("\x00", Asn1::encodeLen(0));
        self::assertSame("\x7F", Asn1::encodeLen(127));
        self::assertSame("\x81\x80", Asn1::encodeLen(128));      // long form 1 byte
        self::assertSame("\x82\x01\x00", Asn1::encodeLen(256));  // long form 2 bytes
    }

    public function testDecodeLen(): void
    {
        self::assertSame([127, 1], Asn1::decodeLen("\x7F", 0));
        self::assertSame([128, 2], Asn1::decodeLen("\x81\x80", 0));
        self::assertSame([256, 3], Asn1::decodeLen("\x82\x01\x00", 0));
    }

    public function testOidEncoding(): void
    {
        // id-aa-timeStampToken 1.2.840.113549.1.9.16.2.14
        $der = Asn1::oid('1.2.840.113549.1.9.16.2.14');
        self::assertSame('2a864886f70d010910020e', bin2hex($der));
        // sha256 2.16.840.1.101.3.4.2.1
        self::assertSame('608648016503040201', bin2hex(Asn1::oid('2.16.840.1.101.3.4.2.1')));
    }

    public function testRoundTripOnRealCertificate(): void
    {
        // self-signed cert → DER (reálná složitá ASN.1 struktura) → decode → encode == identita
        $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'RT Test', 'countryName' => 'CZ'], $pkey);
        $x509 = openssl_csr_sign($csr, null, $pkey, 365);
        openssl_x509_export($x509, $pem);
        // PEM → DER
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
        self::assertNotFalse($der);

        $off = 0;
        $tree = Asn1::decode($der, $off, strlen($der));
        $reencoded = Asn1::encode($tree);

        self::assertSame(bin2hex($der), bin2hex($reencoded), 'round-trip decode→encode není identita');
    }

    public function testNestedTlvRoundTrip(): void
    {
        // ručně sestavená vnořená struktura SEQ { INT 1, OCTET "hi", SEQ { BOOL true } }
        $inner = Asn1::tlv(0x30, Asn1::tlv(0x01, "\xFF"));
        $der = Asn1::tlv(0x30, Asn1::tlv(0x02, "\x01") . Asn1::tlv(0x04, 'hi') . $inner);
        $off = 0;
        $tree = Asn1::decode($der, $off, strlen($der));
        self::assertSame(bin2hex($der), bin2hex(Asn1::encode($tree)));
        // strom: SEQ se 3 dětmi
        self::assertCount(3, $tree[0]['children']);
        self::assertSame(0x02, $tree[0]['children'][0]['tag']);
    }
}

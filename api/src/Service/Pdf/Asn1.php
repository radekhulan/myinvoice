<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Minimální DER (ASN.1) parser/encoder — jen pro potřeby PAdES-T (vložení RFC 3161
 * timestamp tokenu do CMS SignerInfo). NE obecná ASN.1 knihovna.
 *
 * Model: strom uzlů ['tag'=>int, 'constructed'=>bool, 'raw'=>string, 'children'=>?array].
 * decode() rozparsuje DER do stromu, encode() ho serializuje zpět a PŘEPOČÍTÁ všechny
 * délky — proto lze strom modifikovat (přidat uzel) bez ručního posouvání offsetů.
 */
final class Asn1
{
    /** Rozparsuje sekvenci TLV z $der[$off..$end) do pole uzlů. $off se posouvá. */
    public static function decode(string $der, int &$off, int $end): array
    {
        $nodes = [];
        while ($off < $end) {
            $tag = ord($der[$off]);
            $off++;
            [$len, $lenBytes] = self::decodeLen($der, $off);
            $off += $lenBytes;
            $content = substr($der, $off, $len);
            $off += $len;
            $constructed = ($tag & 0x20) !== 0;
            $node = ['tag' => $tag, 'constructed' => $constructed, 'raw' => $content];
            if ($constructed) {
                $o2 = 0;
                $node['children'] = self::decode($content, $o2, strlen($content));
            }
            $nodes[] = $node;
        }
        return $nodes;
    }

    /** Serializuje strom uzlů zpět do DER (přepočítá délky). */
    public static function encode(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $n) {
            $content = (!empty($n['constructed']) && isset($n['children']))
                ? self::encode($n['children'])
                : $n['raw'];
            $out .= chr($n['tag']) . self::encodeLen(strlen($content)) . $content;
        }
        return $out;
    }

    /** Dekóduje DER délku od offsetu. @return array{0:int,1:int} [délka, počet bajtů délky] */
    public static function decodeLen(string $der, int $off): array
    {
        $first = ord($der[$off]);
        if ($first < 0x80) {
            return [$first, 1];
        }
        $numBytes = $first & 0x7F;
        $len = 0;
        for ($i = 1; $i <= $numBytes; $i++) {
            $len = ($len << 8) | ord($der[$off + $i]);
        }
        return [$len, 1 + $numBytes];
    }

    /** Zakóduje délku do DER (short nebo long form). */
    public static function encodeLen(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $bytes = '';
        while ($n > 0) {
            $bytes = chr($n & 0xFF) . $bytes;
            $n >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /** Sestaví jeden TLV: tag + délka + obsah. */
    public static function tlv(int $tag, string $content): string
    {
        return chr($tag) . self::encodeLen(strlen($content)) . $content;
    }

    /** Zakóduje OID (dot-notace) do DER obsahu (bez TLV obalu). */
    public static function oid(string $dotted): string
    {
        $parts = array_map('intval', explode('.', $dotted));
        $out = chr($parts[0] * 40 + $parts[1]);
        $count = count($parts);
        for ($i = 2; $i < $count; $i++) {
            $v = $parts[$i];
            if ($v < 0x80) {
                $out .= chr($v);
                continue;
            }
            $stack = [];
            $stack[] = $v & 0x7F;
            $v >>= 7;
            while ($v > 0) {
                $stack[] = ($v & 0x7F) | 0x80;
                $v >>= 7;
            }
            $out .= implode('', array_map('chr', array_reverse($stack)));
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace BancontactPro\Signing;

use InvalidArgumentException;

/**
 * Converts between OpenSSL's DER-encoded ECDSA signatures and the raw `r || s` form (IEEE P1363) that JWS ES256 uses.
 * For P-256 each coordinate is exactly 32 bytes.
 */
final class EcdsaSignature
{
    public const COORDINATE_BYTES = 32;

    public static function derToRaw(string $der): string
    {
        $offset = 0;
        if (self::byte($der, $offset++) !== 0x30) {
            throw new InvalidArgumentException('Not a DER signature.');
        }
        self::readLength($der, $offset);
        $r = self::readInteger($der, $offset);
        $s = self::readInteger($der, $offset);

        return self::pad($r) . self::pad($s);
    }

    public static function rawToDer(string $raw): string
    {
        if (strlen($raw) !== 2 * self::COORDINATE_BYTES) {
            throw new InvalidArgumentException('A raw ES256 signature is exactly 64 bytes.');
        }
        $r = self::encodeInteger(substr($raw, 0, self::COORDINATE_BYTES));
        $s = self::encodeInteger(substr($raw, self::COORDINATE_BYTES));
        $body = $r . $s;

        return "\x30" . self::encodeLength(strlen($body)) . $body;
    }

    private static function pad(string $integer): string
    {
        $integer = ltrim($integer, "\x00");
        if (strlen($integer) > self::COORDINATE_BYTES) {
            throw new InvalidArgumentException('Signature coordinate is too large for P-256.');
        }

        return str_pad($integer, self::COORDINATE_BYTES, "\x00", STR_PAD_LEFT);
    }

    private static function encodeInteger(string $unsigned): string
    {
        $unsigned = ltrim($unsigned, "\x00");
        if ($unsigned === '' || ord($unsigned[0]) >= 0x80) {
            $unsigned = "\x00" . $unsigned;
        }

        return "\x02" . self::encodeLength(strlen($unsigned)) . $unsigned;
    }

    private static function encodeLength(int $length): string
    {
        return $length < 0x80 ? chr($length & 0xFF) : "\x81" . chr($length & 0xFF);
    }

    private static function byte(string $data, int $offset): int
    {
        if ($offset >= strlen($data)) {
            throw new InvalidArgumentException('Truncated DER signature.');
        }

        return ord($data[$offset]);
    }

    private static function readLength(string $data, int &$offset): int
    {
        $first = self::byte($data, $offset++);
        if ($first < 0x80) {
            return $first;
        }
        $count = $first & 0x7F;
        if ($count < 1 || $count > 2) {
            throw new InvalidArgumentException('Unsupported DER length.');
        }
        $length = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | self::byte($data, $offset++);
        }

        return $length;
    }

    private static function readInteger(string $data, int &$offset): string
    {
        if (self::byte($data, $offset++) !== 0x02) {
            throw new InvalidArgumentException('Expected a DER integer.');
        }
        $length = self::readLength($data, $offset);
        if ($offset + $length > strlen($data)) {
            throw new InvalidArgumentException('Truncated DER signature.');
        }
        $value = substr($data, $offset, $length);
        $offset += $length;

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace BancontactPro\Signing;

use InvalidArgumentException;

final class Base64Url
{
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Strict unpadded base64url; throws on anything else. */
    public static function decode(string $text): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/D', $text) !== 1 || strlen($text) % 4 === 1) {
            throw new InvalidArgumentException('Not valid unpadded base64url.');
        }
        $decoded = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Not valid unpadded base64url.');
        }

        return $decoded;
    }
}

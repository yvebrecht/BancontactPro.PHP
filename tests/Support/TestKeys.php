<?php

declare(strict_types=1);

namespace BancontactPro\Tests\Support;

use BancontactPro\Signing\Keys;
use OpenSSLAsymmetricKey;
use RuntimeException;

final class TestKeys
{
    public static function generate(string $curve = 'prime256v1'): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['curve_name' => $curve, 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new RuntimeException('Could not generate a test key.');
        }

        return $key;
    }

    public static function pem(OpenSSLAsymmetricKey $key): string
    {
        $pem = '';
        if (!openssl_pkey_export($key, $pem) || !is_string($pem)) {
            throw new RuntimeException('Could not export the test key.');
        }

        return $pem;
    }

    /** The public half, rebuilt from its JWK the way a verifier would get it. */
    public static function publicOf(OpenSSLAsymmetricKey $key): OpenSSLAsymmetricKey
    {
        $jwk = json_decode(Keys::toPublicJwkJson($key, 'k'), true, 8, JSON_THROW_ON_ERROR);
        $public = is_array($jwk) ? Keys::fromJwk($jwk) : null;

        return $public ?? throw new RuntimeException('Could not derive the public key.');
    }
}

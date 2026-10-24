<?php

declare(strict_types=1);

namespace BancontactPro\Signing;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;

/** Loading, converting and exporting the P-256 keys this library handles. */
final class Keys
{
    /** DER prefix of a SubjectPublicKeyInfo for an uncompressed P-256 point (id-ecPublicKey, prime256v1). */
    private const SPKI_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Parses a PEM-encoded EC (P-256) private key (PKCS#8 or SEC1). */
    public static function loadPrivateKeyPem(string $pem): OpenSSLAsymmetricKey
    {
        $key = @openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new InvalidArgumentException('The signing key is not a valid PEM-encoded private key.');
        }
        if (self::ecDetails($key)['curve'] !== 'prime256v1') {
            throw new InvalidArgumentException('The signing key must be an EC private key on the P-256 curve.');
        }

        return $key;
    }

    /**
     * Parses a PEM or DER (base64-decoded) SubjectPublicKeyInfo into a P-256 public key.
     */
    public static function loadPublicKeyPem(string $pem): OpenSSLAsymmetricKey
    {
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new InvalidArgumentException('Not a valid PEM-encoded public key.');
        }

        return $key;
    }

    public static function publicKeyFromSpkiDer(string $der): OpenSSLAsymmetricKey
    {
        return self::loadPublicKeyPem(self::pemFromDer($der));
    }

    /**
     * Converts an EC/P-256 JWK to a public key, or null if it is some other kind of key.
     *
     * @param array<mixed> $jwk
     */
    public static function fromJwk(array $jwk): ?OpenSSLAsymmetricKey
    {
        if (($jwk['kty'] ?? null) !== 'EC' || ($jwk['crv'] ?? null) !== 'P-256') {
            return null;
        }
        $xText = $jwk['x'] ?? null;
        $yText = $jwk['y'] ?? null;
        if (!is_string($xText) || $xText === '' || !is_string($yText) || $yText === '') {
            return null;
        }
        try {
            $x = Base64Url::decode($xText);
            $y = Base64Url::decode($yText);
        } catch (InvalidArgumentException) {
            return null;
        }
        if (strlen($x) !== EcdsaSignature::COORDINATE_BYTES || strlen($y) !== EcdsaSignature::COORDINATE_BYTES) {
            return null;
        }
        $der = (string) hex2bin(self::SPKI_P256_PREFIX) . "\x04" . $x . $y;
        $key = @openssl_pkey_get_public(self::pemFromDer($der));

        return $key === false ? null : $key;
    }

    /**
     * Exports the public half of a P-256 key as a JWK, for Bancontact to host and fetch.
     */
    public static function toPublicJwkJson(OpenSSLAsymmetricKey|string $key, string $kid): string
    {
        if (is_string($key)) {
            $key = self::loadPrivateKeyPem($key);
        }
        $details = self::ecDetails($key);
        if ($details['curve'] !== 'prime256v1') {
            throw new InvalidArgumentException("Only P-256 (ES256) keys are supported; got curve '{$details['curve']}'.");
        }

        return json_encode([
            'kty' => 'EC',
            'crv' => 'P-256',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'ES256',
            'x' => Base64Url::encode(str_pad($details['x'], EcdsaSignature::COORDINATE_BYTES, "\x00", STR_PAD_LEFT)),
            'y' => Base64Url::encode(str_pad($details['y'], EcdsaSignature::COORDINATE_BYTES, "\x00", STR_PAD_LEFT)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{curve: string, x: string, y: string} */
    private static function ecDetails(OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        $ec = is_array($details) && $details['type'] === OPENSSL_KEYTYPE_EC && is_array($details['ec'] ?? null) ? $details['ec'] : null;
        if ($ec === null) {
            throw new InvalidArgumentException('The key is not an EC key.');
        }
        $curve = $ec['curve_name'] ?? '';
        $x = $ec['x'] ?? '';
        $y = $ec['y'] ?? '';

        return [
            'curve' => is_string($curve) ? $curve : '',
            'x' => is_string($x) ? $x : '',
            'y' => is_string($y) ? $y : '',
        ];
    }

    private static function pemFromDer(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}

<?php

declare(strict_types=1);

namespace BancontactPro\Signing;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Detached JWS (RFC 7797) signing and verification, ES256 over P-256.
 *
 * The compact form has an empty payload segment: `base64url(header) . ".." . base64url(signature)`, where the
 * signature covers `base64url(header) . "." . base64url(body)`. The separator in the signing input is not stated by
 * Bancontact's callback guide (it writes `+`); `.` is the RFC 7797 convention and is an open item to confirm with
 * devsupport@bancontact.com against a real sample (BancontactPro.Net#1, #2). The header carries five custom claims
 * under a `https://payconiq.com/` namespace, all listed in `crit`.
 */
final class DetachedJws
{
    public const NAMESPACE_URI = 'https://payconiq.com/';

    /** @return list<string> */
    public static function critical(): array
    {
        return array_map(
            static fn(string $name): string => self::NAMESPACE_URI . $name,
            ['sub', 'iss', 'iat', 'jti', 'path'],
        );
    }

    public static function formatIssuedAt(\DateTimeInterface $value): string
    {
        return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function parseIssuedAt(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) !== 1) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $value, new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed;
    }

    public static function signingInput(string $encodedHeader, string $body): string
    {
        return $encodedHeader . '.' . Base64Url::encode($body);
    }

    /**
     * Splits `header..signature` (RFC 7797, empty payload segment), as documented by Bancontact's callback guide and
     * API specs. The single-dot form `header.signature` is tolerated on input.
     *
     * @return array{string, string}|null
     */
    public static function trySplit(string $detachedJws): ?array
    {
        $parts = explode('.', $detachedJws);
        if (count($parts) === 3 && $parts[1] === '') {
            return [$parts[0], $parts[2]];
        }
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        return null;
    }

    /** Signs `$body` and returns the detached JWS for the `Signature` header. */
    public static function sign(JoseHeader $header, string $body, OpenSSLAsymmetricKey $privateKey): string
    {
        $ns = self::NAMESPACE_URI;
        $claims = [
            'typ' => 'jose+json',
            'kid' => $header->kid,
            'alg' => 'ES256',
            $ns . 'sub' => $header->merchantProfileId,
            $ns . 'iss' => $header->issuer,
            $ns . 'iat' => self::formatIssuedAt($header->issuedAt),
            $ns . 'jti' => $header->requestId,
            $ns . 'path' => $header->path,
            'crit' => self::critical(),
        ];
        // No escaped slashes and no whitespace: the URI-shaped claim names must round-trip byte-for-byte.
        $encodedHeader = Base64Url::encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $der = '';
        if (!openssl_sign(self::signingInput($encodedHeader, $body), $der, $privateKey, OPENSSL_ALGO_SHA256) || !is_string($der)) {
            throw new RuntimeException('The request could not be signed.');
        }

        return $encodedHeader . '..' . Base64Url::encode(EcdsaSignature::derToRaw($der));
    }

    /** Checks a raw `r || s` signature over `$signingInput`. Never throws. */
    public static function verifyRaw(OpenSSLAsymmetricKey $publicKey, string $rawSignature, string $signingInput): bool
    {
        try {
            $der = EcdsaSignature::rawToDer($rawSignature);
        } catch (InvalidArgumentException) {
            return false;
        }

        return openssl_verify($signingInput, $der, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /** Pure signature check (no header policy). Never throws on malformed input. */
    public static function verify(string $detachedJws, string $body, OpenSSLAsymmetricKey $publicKey): bool
    {
        $parts = self::trySplit($detachedJws);
        if ($parts === null) {
            return false;
        }
        [$encodedHeader, $encodedSignature] = $parts;
        try {
            $signature = Base64Url::decode($encodedSignature);
        } catch (InvalidArgumentException) {
            return false;
        }

        return self::verifyRaw($publicKey, $signature, self::signingInput($encodedHeader, $body));
    }
}

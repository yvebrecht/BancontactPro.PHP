<?php

declare(strict_types=1);

namespace BancontactPro\Signing;

use InvalidArgumentException;
use JsonException;

/**
 * Verifies an inbound detached JWS and its structural policy.
 *
 * Returns the parsed claims on success and null on any failure: malformed or malicious inbound data is an expected,
 * non-exceptional occurrence for anything facing the public internet.
 */
final class SignatureVerifier
{
    public function __construct(private readonly KeySource $keys) {}

    public function verify(string $signatureHeader, string $body, string $path): ?JoseHeader
    {
        $parts = DetachedJws::trySplit($signatureHeader);
        if ($parts === null) {
            return null;
        }
        [$encodedHeader, $encodedSignature] = $parts;

        $ns = DetachedJws::NAMESPACE_URI;
        try {
            $claims = json_decode(Base64Url::decode($encodedHeader), true, 8, JSON_THROW_ON_ERROR);
        } catch (InvalidArgumentException|JsonException) {
            return null;
        }
        if (!is_array($claims)) {
            return null;
        }
        $kid = $claims['kid'] ?? null;
        $sub = $claims[$ns . 'sub'] ?? null;
        $iss = $claims[$ns . 'iss'] ?? null;
        $iat = $claims[$ns . 'iat'] ?? null;
        $jti = $claims[$ns . 'jti'] ?? null;
        $claimedPath = $claims[$ns . 'path'] ?? null;
        $crit = $claims['crit'] ?? [];
        if (!is_string($kid) || !is_string($sub) || !is_string($iss) || !is_string($iat) || !is_string($jti)
            || !is_string($claimedPath) || !is_array($crit)) {
            return null;
        }

        // An unrecognised (or missing) critical extension must cause rejection, never silent ignoring.
        $expected = DetachedJws::critical();
        $critNames = array_values(array_filter($crit, is_string(...)));
        if (count($critNames) !== count($crit) || count($critNames) !== count($expected) || array_diff($critNames, $expected) !== []) {
            return null;
        }

        // Defends against a validly signed message being replayed against the wrong route.
        if ($claimedPath !== $path) {
            return null;
        }

        try {
            $signature = Base64Url::decode($encodedSignature);
        } catch (InvalidArgumentException) {
            return null;
        }

        $publicKey = $this->keys->getKey($kid);
        if ($publicKey === null) {
            return null;
        }

        if (!DetachedJws::verifyRaw($publicKey, $signature, DetachedJws::signingInput($encodedHeader, $body))) {
            return null;
        }

        $issuedAt = DetachedJws::parseIssuedAt($iat);
        if ($issuedAt === null) {
            return null;
        }

        return new JoseHeader($kid, $sub, $iss, $issuedAt, $jti, $claimedPath);
    }
}

<?php

declare(strict_types=1);

namespace BancontactPro\Http;

use BancontactPro\Signing\DetachedJws;
use BancontactPro\Signing\JoseHeader;
use DateTimeImmutable;
use OpenSSLAsymmetricKey;

/** Refund and Reconciliation: bearer API key plus a detached JWS `Signature` over the request body. */
final readonly class SigningAuth implements Auth
{
    public function __construct(
        private string $apiKey,
        private string $merchantProfileId,
        private string $issuer,
        private string $kid,
        private OpenSSLAsymmetricKey $privateKey,
    ) {}

    public function apply(array $headers, string $method, string $path, string $body): array
    {
        $header = new JoseHeader(
            kid: $this->kid,
            merchantProfileId: $this->merchantProfileId,
            issuer: $this->issuer,
            issuedAt: new DateTimeImmutable('now', new \DateTimeZone('UTC')),
            requestId: self::uuid(),
            path: $path,
        );
        $headers['Signature'] = DetachedJws::sign($header, $body, $this->privateKey);
        $headers['Authorization'] = 'Bearer ' . $this->apiKey;

        return $headers;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['kid' => $this->kid, 'apiKey' => '***', 'privateKey' => '***'];
    }

    /** A random (version 4) UUID for the `jti` claim. */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

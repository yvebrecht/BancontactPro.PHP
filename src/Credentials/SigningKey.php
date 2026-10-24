<?php

declare(strict_types=1);

namespace BancontactPro\Credentials;

/** The merchant's ES256 (P-256) signing key, needed for Refund and Reconciliation calls. */
final readonly class SigningKey
{
    public function __construct(
        public string $kid,
        public string $privateKeyPem,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['kid' => $this->kid, 'privateKeyPem' => '***'];
    }
}

<?php

declare(strict_types=1);

namespace BancontactPro\Credentials;

/** The API key and PPID (merchant profile id) Bancontact issued for one product type. */
final readonly class ProductCredentials
{
    public function __construct(
        public ?string $apiKey = null,
        public ?string $merchantProfileId = null,
    ) {}

    public function isComplete(): bool
    {
        return trim($this->apiKey ?? '') !== '' && trim($this->merchantProfileId ?? '') !== '';
    }

    /** @return array<string, string|null> */
    public function __debugInfo(): array
    {
        return ['apiKey' => '***', 'merchantProfileId' => $this->merchantProfileId];
    }
}

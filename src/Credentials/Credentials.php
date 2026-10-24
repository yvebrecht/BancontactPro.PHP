<?php

declare(strict_types=1);

namespace BancontactPro\Credentials;

use BancontactPro\Enums\Environment;
use BancontactPro\Enums\Product;

/** A snapshot of everything the library needs, read afresh on every use (never cached by the library). */
final readonly class Credentials
{
    /**
     * @param array<string, ProductCredentials> $products keyed by `Product::value`
     * @param string $issuer the `iss` claim of signed requests. The Payment and Refund specs say "Payconiq"; the
     *     Reconciliation spec templates it as the merchant id, which cannot be resolved from the documentation alone.
     */
    public function __construct(
        public Environment $environment = Environment::Preprod,
        public array $products = [],
        public ?SigningKey $signingKey = null,
        public string $issuer = 'Payconiq',
    ) {}

    public function forProduct(Product $product): ?ProductCredentials
    {
        $credentials = $this->products[$product->value] ?? null;

        return $credentials !== null && $credentials->isComplete() ? $credentials : null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'environment' => $this->environment,
            'products' => array_keys($this->products),
            'signingKey' => $this->signingKey,
            'issuer' => $this->issuer,
        ];
    }
}

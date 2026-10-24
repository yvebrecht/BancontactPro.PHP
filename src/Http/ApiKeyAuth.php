<?php

declare(strict_types=1);

namespace BancontactPro\Http;

/** Payment API: the API key alone authenticates (the spec lists the JWS scheme as an alternative). */
final readonly class ApiKeyAuth implements Auth
{
    public function __construct(private string $apiKey) {}

    public function apply(array $headers, string $method, string $path, string $body): array
    {
        $headers['Authorization'] = 'Bearer ' . $this->apiKey;

        return $headers;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['apiKey' => '***'];
    }
}

<?php

declare(strict_types=1);

namespace BancontactPro\Jwks;

use BancontactPro\Credentials\Credentials;
use BancontactPro\Endpoints;
use BancontactPro\Enums\Environment;
use BancontactPro\Exception\ApiException;
use BancontactPro\Signing\Keys;
use BancontactPro\Signing\KeySource;
use JsonException;
use OpenSSLAsymmetricKey;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Fetches and caches Bancontact's JWKS, one cache per environment.
 *
 * Follows the callback guide: keys are re-fetched when a `kid` is not found (Bancontact can rotate keys without
 * notice, publishing a new key 24 hours before the old one is removed). Keys are also dropped after `$maxAgeSeconds`
 * (the guide allows caching for up to 12 hours).
 *
 * PHP-FPM shares no memory between requests, so the in-memory copy only helps within one request. Pass a PSR-16
 * cache to share the keys across requests; without it every request that verifies a callback fetches the JWKS.
 */
final class JwksClient implements KeySource
{
    public const DEFAULT_MAX_AGE_SECONDS = 12 * 3600;

    /** @var array<string, array{fetchedAt: float, keys: array<string, array<mixed>>}> */
    private array $entries = [];

    /** @var array<string, OpenSSLAsymmetricKey|null> */
    private array $parsed = [];

    /**
     * @param callable(): Credentials $credentials
     * @param (callable(): float)|null $clock seconds, comparable across calls; defaults to wall-clock time
     */
    public function __construct(
        private $credentials,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly ?CacheInterface $cache = null,
        private readonly float $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS,
        private $clock = null,
    ) {}

    public function getKey(string $kid): ?OpenSSLAsymmetricKey
    {
        $environment = ($this->credentials)()->environment;

        $entry = $this->load($environment);
        if ($entry !== null && isset($entry['keys'][$kid])) {
            return $this->parse($environment, $kid, $entry['keys'][$kid]);
        }

        // Unknown (or expired) kid: reload once.
        $entry = $this->fetch($environment);

        return isset($entry['keys'][$kid]) ? $this->parse($environment, $kid, $entry['keys'][$kid]) : null;
    }

    /** @return array{fetchedAt: float, keys: array<string, array<mixed>>}|null */
    private function load(Environment $environment): ?array
    {
        $entry = $this->entries[$environment->value] ?? null;
        if ($entry === null && $this->cache !== null) {
            $stored = $this->cache->get($this->cacheKey($environment));
            if (is_array($stored) && isset($stored['fetchedAt'], $stored['keys']) && is_float($stored['fetchedAt']) && is_array($stored['keys'])) {
                /** @var array{fetchedAt: float, keys: array<string, array<mixed>>} $stored */
                $entry = $stored;
                $this->entries[$environment->value] = $entry;
            }
        }
        if ($entry === null || $this->now() - $entry['fetchedAt'] >= $this->maxAgeSeconds) {
            return null;
        }

        return $entry;
    }

    /** @return array{fetchedAt: float, keys: array<string, array<mixed>>} */
    private function fetch(Environment $environment): array
    {
        $request = $this->requestFactory->createRequest('GET', Endpoints::jwksUrl($environment))
            ->withHeader('Accept', 'application/json');
        $response = $this->http->sendRequest($request);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new ApiException($response->getStatusCode(), '', "Fetching the Bancontact JWKS failed with status {$response->getStatusCode()}.");
        }

        try {
            $document = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException($response->getStatusCode(), '', 'The Bancontact JWKS is not valid JSON.', null, null);
        }

        $keys = [];
        foreach (is_array($document) && is_array($document['keys'] ?? null) ? $document['keys'] : [] as $jwk) {
            if (is_array($jwk) && isset($jwk['kid']) && is_string($jwk['kid']) && Keys::fromJwk($jwk) !== null) {
                $keys[$jwk['kid']] = $jwk;
            }
        }

        $entry = ['fetchedAt' => $this->now(), 'keys' => $keys];
        $this->entries[$environment->value] = $entry;
        $this->parsed = array_filter($this->parsed, static fn(string $id): bool => !str_starts_with($id, $environment->value . '/'), ARRAY_FILTER_USE_KEY);
        $this->cache?->set($this->cacheKey($environment), $entry, (int) $this->maxAgeSeconds);

        return $entry;
    }

    /** @param array<mixed> $jwk */
    private function parse(Environment $environment, string $kid, array $jwk): ?OpenSSLAsymmetricKey
    {
        $id = $environment->value . '/' . $kid;

        return $this->parsed[$id] ??= Keys::fromJwk($jwk);
    }

    private function cacheKey(Environment $environment): string
    {
        return 'bancontactpro.jwks.' . $environment->value;
    }

    private function now(): float
    {
        return $this->clock === null ? microtime(true) : ($this->clock)();
    }
}

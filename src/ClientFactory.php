<?php

declare(strict_types=1);

namespace BancontactPro;

use BancontactPro\Clients\PaymentClient;
use BancontactPro\Clients\ReconciliationClient;
use BancontactPro\Clients\RefundClient;
use BancontactPro\Credentials\Credentials;
use BancontactPro\Credentials\ProductCredentials;
use BancontactPro\Enums\Product;
use BancontactPro\Exception\ProductNotConfiguredException;
use BancontactPro\Exception\SigningKeyNotConfiguredException;
use BancontactPro\Http\ApiKeyAuth;
use BancontactPro\Http\SigningAuth;
use BancontactPro\Http\Transport;
use BancontactPro\Jwks\JwksClient;
use BancontactPro\Signing\Keys;
use BancontactPro\Signing\SignatureVerifier;
use BancontactPro\Webhooks\CallbackVerifier;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Creates product-bound clients from the *current* credentials.
 *
 * Credentials are read on every call and never captured, so a provider that changes at runtime (a rotated key, an
 * environment switch) takes effect immediately. The returned clients keep the credentials they were created with,
 * which is why callers ask for one per operation rather than holding it. Bancontact issues credentials per product
 * type, so a client is always bound to one product.
 */
final class ClientFactory
{
    /** @var callable(): Credentials */
    private $credentials;

    private readonly ClientInterface $http;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;
    private ?JwksClient $jwks = null;

    /** @var array{string, string, OpenSSLAsymmetricKey}|null */
    private ?array $signingKeyCache = null;

    /**
     * @param Credentials|callable(): Credentials $credentials fixed credentials, or a function returning the current ones
     *     (it is called on every use, so it may read a database or secret store; cache briefly yourself if that is expensive)
     * @param ClientInterface|null $http a PSR-18 client; discovered when omitted
     * @param CacheInterface|null $cache a PSR-16 cache for the JWKS, so PHP-FPM requests share the keys
     */
    public function __construct(
        Credentials|callable $credentials,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly ?CacheInterface $cache = null,
    ) {
        $this->credentials = $credentials instanceof Credentials ? static fn(): Credentials => $credentials : $credentials;
        $this->http = $http ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function isConfigured(Product $product): bool
    {
        return ($this->credentials)()->forProduct($product) !== null;
    }

    public function payments(Product $product): PaymentClient
    {
        [$credentials, $pair] = $this->prepare($product);

        return new PaymentClient($this->transport($credentials, new ApiKeyAuth((string) $pair->apiKey)));
    }

    public function refunds(Product $product): RefundClient
    {
        return new RefundClient($this->signedTransport($product));
    }

    public function reconciliation(Product $product): ReconciliationClient
    {
        return new ReconciliationClient($this->signedTransport($product));
    }

    /** The verifier for inbound webhooks. Uses one long-lived JWKS client; never build a `JwksClient` per call. */
    public function callbackVerifier(): CallbackVerifier
    {
        $this->jwks ??= new JwksClient($this->credentials, $this->http, $this->requestFactory, $this->cache);

        return new CallbackVerifier(new SignatureVerifier($this->jwks));
    }

    /** @return array{Credentials, ProductCredentials} */
    private function prepare(Product $product): array
    {
        $credentials = ($this->credentials)();
        $pair = $credentials->forProduct($product);
        if ($pair === null) {
            throw new ProductNotConfiguredException($product);
        }

        return [$credentials, $pair];
    }

    private function signedTransport(Product $product): Transport
    {
        [$credentials, $pair] = $this->prepare($product);
        $key = $credentials->signingKey;
        if ($key === null || trim($key->kid) === '' || trim($key->privateKeyPem) === '') {
            throw new SigningKeyNotConfiguredException();
        }

        return $this->transport($credentials, new SigningAuth(
            apiKey: (string) $pair->apiKey,
            merchantProfileId: (string) $pair->merchantProfileId,
            issuer: $credentials->issuer,
            kid: $key->kid,
            privateKey: $this->parseSigningKey($key->kid, $key->privateKeyPem),
        ));
    }

    private function transport(Credentials $credentials, ApiKeyAuth|SigningAuth $auth): Transport
    {
        return new Transport($this->http, $this->requestFactory, $this->streamFactory, Endpoints::merchantApiBaseUrl($credentials->environment), $auth);
    }

    private function parseSigningKey(string $kid, string $pem): OpenSSLAsymmetricKey
    {
        // Parsing a PEM costs more than a request needs, so the last parsed key is kept, keyed on its content.
        if ($this->signingKeyCache !== null && $this->signingKeyCache[0] === $kid && $this->signingKeyCache[1] === $pem) {
            return $this->signingKeyCache[2];
        }
        try {
            $parsed = Keys::loadPrivateKeyPem($pem);
        } catch (InvalidArgumentException $e) {
            throw new SigningKeyNotConfiguredException(
                "The signing key '{$kid}' is not a valid PEM-encoded EC (P-256) private key, so Refund and Reconciliation requests cannot be signed.",
            );
        }
        $this->signingKeyCache = [$kid, $pem, $parsed];

        return $parsed;
    }
}

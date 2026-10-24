<?php

declare(strict_types=1);

namespace BancontactPro\Tests\Webhooks;

use BancontactPro\ClientFactory;
use BancontactPro\Credentials\Credentials;
use BancontactPro\Enums\Environment;
use BancontactPro\Enums\MerchantPaymentStatus;
use BancontactPro\Exception\ApiException;
use BancontactPro\Exception\InvalidCallbackException;
use BancontactPro\Jwks\JwksClient;
use BancontactPro\Signing\DetachedJws;
use BancontactPro\Signing\JoseHeader;
use BancontactPro\Signing\Keys;
use BancontactPro\Signing\KeySource;
use BancontactPro\Signing\SignatureVerifier;
use BancontactPro\Tests\Support\MockHttp;
use BancontactPro\Tests\Support\TestKeys;
use BancontactPro\Webhooks\CallbackVerifier;
use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Factory\Psr17Factory;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class WebhooksJwksTest extends TestCase
{
    private const JWKS_PREPROD = 'https://jwks.preprod.bancontact.net/';
    private const JWKS_PROD = 'https://jwks.bancontact.net/';

    private OpenSSLAsymmetricKey $key;
    private string $body;

    protected function setUp(): void
    {
        $this->key = TestKeys::generate();
        $this->body = json_encode([
            'paymentId' => '5c18cbd1296e9a26d3278518',
            'currency' => 'EUR',
            'amount' => 1000,
            'description' => 'Order #42',
            'createdAt' => '2026-01-02T03:04:05.000Z',
            'expireAt' => '2026-01-02T03:19:05.123456+00:00',
            'succeededAt' => null,
            'status' => 'AUTHORIZED',
            'debtor' => ['iban' => 'BE**********1234', 'name' => 'Jane Doe'],
        ], JSON_THROW_ON_ERROR);
    }

    private static function header(string $kid = 'bank-kid', string $path = '/callback'): JoseHeader
    {
        return new JoseHeader($kid, 'ppid', 'payconiq', new DateTimeImmutable('2026-01-02', new DateTimeZone('UTC')), 'jti-xyz', $path);
    }

    private function verifier(OpenSSLAsymmetricKey $public): CallbackVerifier
    {
        return new CallbackVerifier(new SignatureVerifier(new class ($public) implements KeySource {
            public function __construct(private readonly OpenSSLAsymmetricKey $key) {}

            public function getKey(string $kid): ?OpenSSLAsymmetricKey
            {
                return $kid === 'bank-kid' ? $this->key : null;
            }
        }));
    }

    /** @return array<string, mixed> */
    private function jwks(OpenSSLAsymmetricKey $key, string $kid): array
    {
        return ['keys' => [json_decode(Keys::toPublicJwkJson($key, $kid), true, 8, JSON_THROW_ON_ERROR), ['kty' => 'RSA', 'kid' => 'rsa'], ['kty' => 'EC']]];
    }

    private function jwksClient(MockHttp $http, Credentials $credentials = new Credentials(), ?callable $clock = null, ?CacheInterface $cache = null, float $maxAge = 43200): JwksClient
    {
        return new JwksClient(static fn(): Credentials => $credentials, $http, new Psr17Factory(), $cache, $maxAge, $clock);
    }

    public function testValidCallbackIsParsed(): void
    {
        $signature = DetachedJws::sign(self::header(), $this->body, $this->key);
        $result = $this->verifier(TestKeys::publicOf($this->key))->verifyAndParse($signature, $this->body, '/callback');

        self::assertSame('jti-xyz', $result->requestId);
        self::assertSame(MerchantPaymentStatus::Authorized, $result->payload->status);
        self::assertSame(1000, $result->payload->amount);
        self::assertSame('Jane Doe', $result->payload->debtor->name);
        self::assertNull($result->payload->succeededAt);
        self::assertSame('123456', $result->payload->expireAt?->format('u')); // preprod sends microseconds and +00:00
    }

    public function testWrongKeyTamperAndWrongPathAreRejected(): void
    {
        $verifier = $this->verifier(TestKeys::publicOf($this->key));
        $good = DetachedJws::sign(self::header(), $this->body, $this->key);

        $attempts = [
            [DetachedJws::sign(self::header(), $this->body, TestKeys::generate()), $this->body, '/callback'],
            [$good, str_replace('1000', '9999', $this->body), '/callback'],
            [$good, $this->body, '/elsewhere'],
            [DetachedJws::sign(self::header('unknown'), $this->body, $this->key), $this->body, '/callback'],
        ];
        foreach ($attempts as [$signature, $body, $path]) {
            try {
                $verifier->verifyAndParse($signature, $body, $path);
                self::fail('The callback should have been rejected.');
            } catch (InvalidCallbackException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testValidlySignedGarbageBodyRaisesInvalidCallback(): void
    {
        foreach (['not json', '{}', '[]'] as $body) {
            try {
                $this->verifier(TestKeys::publicOf($this->key))->verifyAndParse(DetachedJws::sign(self::header(), $body, $this->key), $body, '/callback');
                self::fail('The body should have been rejected.');
            } catch (InvalidCallbackException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testJwksRefreshesOnUnknownKidOnceAndCaches(): void
    {
        $http = (new MockHttp())->on('GET', self::JWKS_PREPROD, 200, $this->jwks($this->key, 'bank-kid'));
        $client = $this->jwksClient($http);

        self::assertNotNull($client->getKey('bank-kid'));
        self::assertNotNull($client->getKey('bank-kid'));
        self::assertSame(1, $http->count('GET', self::JWKS_PREPROD)); // second hit comes from the cache
        self::assertNull($client->getKey('rotated'));
        self::assertSame(2, $http->count('GET', self::JWKS_PREPROD)); // a miss triggers one refresh
        self::assertNull($client->getKey('rsa')); // non-EC keys are ignored
    }

    public function testKeysExpireAfterMaxAgeAndEnvironmentsAreSeparate(): void
    {
        $http = (new MockHttp())
            ->on('GET', self::JWKS_PREPROD, 200, $this->jwks($this->key, 'bank-kid'))
            ->on('GET', self::JWKS_PROD, 200, ['keys' => []]);
        $now = 0.0;
        $environment = Environment::Preprod;
        $client = new JwksClient(
            static function () use (&$environment): Credentials {
                return new Credentials(environment: $environment);
            },
            $http,
            new Psr17Factory(),
            null,
            100.0,
            static function () use (&$now): float {
                return $now;
            },
        );

        $client->getKey('bank-kid');
        $now = 101.0;
        $client->getKey('bank-kid');
        self::assertSame(2, $http->count('GET', self::JWKS_PREPROD)); // stale cache is refreshed
        $environment = Environment::Production;
        self::assertNull($client->getKey('bank-kid')); // preprod keys are never used for production
        self::assertSame(1, $http->count('GET', self::JWKS_PROD));
    }

    public function testKeysAreSharedAcrossInstancesThroughAPsr16Cache(): void
    {
        $cache = new class implements CacheInterface {
            /** @var array<string, mixed> */
            public array $items = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }

            public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
            {
                $this->items[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->items[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->items = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            /** @param iterable<string, mixed> $values */
            public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->items[$key]);
            }
        };
        $http = (new MockHttp())->on('GET', self::JWKS_PREPROD, 200, $this->jwks($this->key, 'bank-kid'));

        self::assertNotNull($this->jwksClient($http, cache: $cache)->getKey('bank-kid'));
        self::assertNotNull($this->jwksClient($http, cache: $cache)->getKey('bank-kid')); // a "next request"
        self::assertSame(1, $http->count('GET', self::JWKS_PREPROD));
    }

    public function testJwksFailureSurfacesAsApiException(): void
    {
        $http = (new MockHttp())->on('GET', self::JWKS_PREPROD, 503, 'down');

        $this->expectException(ApiException::class);
        $this->jwksClient($http)->getKey('bank-kid');
    }

    public function testFactoryCallbackVerifierEndToEnd(): void
    {
        $http = (new MockHttp())->on('GET', self::JWKS_PREPROD, 200, $this->jwks($this->key, 'bank-kid'));
        $factory = new ClientFactory(new Credentials(), $http, new Psr17Factory(), new Psr17Factory());

        $signature = DetachedJws::sign(self::header(), $this->body, $this->key);
        $result = $factory->callbackVerifier()->verifyAndParse($signature, $this->body, '/callback');

        self::assertSame('5c18cbd1296e9a26d3278518', $result->payload->paymentId);
    }
}

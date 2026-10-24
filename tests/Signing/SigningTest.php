<?php

declare(strict_types=1);

namespace BancontactPro\Tests\Signing;

use BancontactPro\Signing\Base64Url;
use BancontactPro\Signing\DetachedJws;
use BancontactPro\Signing\EcdsaSignature;
use BancontactPro\Signing\JoseHeader;
use BancontactPro\Signing\Keys;
use BancontactPro\Signing\KeySource;
use BancontactPro\Signing\SignatureVerifier;
use BancontactPro\Tests\Support\TestKeys;
use DateTimeImmutable;
use DateTimeZone;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SigningTest extends TestCase
{
    private const NS = 'https://payconiq.com/';

    private OpenSSLAsymmetricKey $key;

    protected function setUp(): void
    {
        $this->key = TestKeys::generate();
    }

    private static function header(string $kid = 'kid-1'): JoseHeader
    {
        return new JoseHeader($kid, 'ppid-1', 'Payconiq', new DateTimeImmutable('2026-01-02T03:04:05.678Z', new DateTimeZone('UTC')), 'jti-1', '/callback');
    }

    /** @return array<string, mixed> */
    private static function claimsOf(string $jws): array
    {
        $claims = json_decode(Base64Url::decode(explode('.', $jws)[0]), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    private function verifier(?OpenSSLAsymmetricKey $public, string $kid = 'kid-1'): SignatureVerifier
    {
        return new SignatureVerifier(new class ($public, $kid) implements KeySource {
            public function __construct(private readonly ?OpenSSLAsymmetricKey $key, private readonly string $kid) {}

            public function getKey(string $kid): ?OpenSSLAsymmetricKey
            {
                return $kid === $this->kid ? $this->key : null;
            }
        });
    }

    public function testSignatureIsHeaderDotDotSignatureWithRawRAndS(): void
    {
        $jws = DetachedJws::sign(self::header(), '{"a":1}', $this->key);
        [, $middle, $signature] = explode('.', $jws);

        self::assertSame('', $middle); // RFC 7797 detached form documented by Bancontact: empty payload segment
        self::assertSame(64, strlen(Base64Url::decode($signature))); // raw r||s, never DER
        self::assertTrue(DetachedJws::verify($jws, '{"a":1}', TestKeys::publicOf($this->key)));
    }

    public function testHeaderClaimsAndExactBytes(): void
    {
        $jws = DetachedJws::sign(self::header(), 'x', $this->key);
        $claims = self::claimsOf($jws);

        self::assertSame('jose+json', $claims['typ']);
        self::assertSame('ES256', $claims['alg']);
        self::assertSame('kid-1', $claims['kid']);
        self::assertSame('2026-01-02T03:04:05.678Z', $claims[self::NS . 'iat']);
        self::assertSame('/callback', $claims[self::NS . 'path']);
        self::assertSame(array_map(static fn(string $s): string => self::NS . $s, ['sub', 'iss', 'iat', 'jti', 'path']), $claims['crit']);
        self::assertStringContainsString('https://payconiq.com/sub', Base64Url::decode(explode('.', $jws)[0])); // slashes not escaped
    }

    public function testTamperedBodyFails(): void
    {
        $jws = DetachedJws::sign(self::header(), 'original', $this->key);

        self::assertFalse(DetachedJws::verify($jws, 'tampered', TestKeys::publicOf($this->key)));
    }

    public function testSingleDotFormIsToleratedOnVerify(): void
    {
        [$head, , $signature] = explode('.', DetachedJws::sign(self::header(), 'b', $this->key));

        self::assertTrue(DetachedJws::verify($head . '.' . $signature, 'b', TestKeys::publicOf($this->key)));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'one segment' => ['abc'];
        yield 'four segments' => ['a.b.c.d'];
        yield 'two empty middles' => ['a..b..c'];
        yield 'not base64url' => ['!!!..???'];
    }

    #[DataProvider('malformed')]
    public function testMalformedNeverThrows(string $bad): void
    {
        self::assertFalse(DetachedJws::verify($bad, 'x', TestKeys::publicOf($this->key)));
    }

    public function testDerAndRawRoundTripIncludingShortCoordinates(): void
    {
        $raw = str_repeat("\x00", 31) . "\x01" . "\xff" . str_repeat("\x10", 31);
        $der = EcdsaSignature::rawToDer($raw);

        self::assertSame($raw, EcdsaSignature::derToRaw($der));
        // A coordinate with the high bit set gets a 0x00 pad in DER; one with leading zeros is shortened.
        self::assertStringContainsString("\x02\x21\x00\xff", $der);
    }

    public function testBase64UrlIsStrict(): void
    {
        self::assertSame('_-8', Base64Url::encode("\xff\xef"));
        self::assertSame("\xff\xef", Base64Url::decode('_-8'));
        $this->expectException(\InvalidArgumentException::class);
        Base64Url::decode('ab=c');
    }

    public function testVerifierReturnsClaims(): void
    {
        $jws = DetachedJws::sign(self::header(), 'body', $this->key);
        $claims = $this->verifier(TestKeys::publicOf($this->key))->verify($jws, 'body', '/callback');

        self::assertNotNull($claims);
        self::assertSame('jti-1', $claims->requestId);
        self::assertSame('2026-01-02T03:04:05.678Z', DetachedJws::formatIssuedAt($claims->issuedAt));
    }

    public function testVerifierRejectsWrongPathUnknownKidAndWrongKey(): void
    {
        $jws = DetachedJws::sign(self::header(), 'body', $this->key);
        $ok = $this->verifier(TestKeys::publicOf($this->key));

        self::assertNull($ok->verify($jws, 'body', '/other'));
        self::assertNull($this->verifier(null)->verify($jws, 'body', '/callback'));
        self::assertNull($this->verifier(TestKeys::publicOf(TestKeys::generate()))->verify($jws, 'body', '/callback'));
        self::assertNull($ok->verify($jws, 'body', '/callback/'));
    }

    public function testVerifierRejectsUnrecognisedCriticalExtension(): void
    {
        $jws = DetachedJws::sign(self::header(), 'body', $this->key);
        $head = self::claimsOf($jws);
        $head['crit'] = [...DetachedJws::critical(), 'https://example.com/extra'];
        $forged = Base64Url::encode(json_encode($head, JSON_THROW_ON_ERROR));

        self::assertNull($this->verifier(TestKeys::publicOf($this->key))->verify($forged . '..' . explode('.', $jws)[2], 'body', '/callback'));
    }

    public function testVerifierRejectsMissingCriticalClaim(): void
    {
        $jws = DetachedJws::sign(self::header(), 'body', $this->key);
        $head = self::claimsOf($jws);
        unset($head['crit']);
        $forged = Base64Url::encode(json_encode($head, JSON_THROW_ON_ERROR));

        self::assertNull($this->verifier(TestKeys::publicOf($this->key))->verify($forged . '..' . explode('.', $jws)[2], 'body', '/callback'));
    }

    public function testJwkRoundTripAndRejectsOtherCurves(): void
    {
        $jwk = json_decode(Keys::toPublicJwkJson($this->key, 'k'), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($jwk);

        self::assertSame('EC', $jwk['kty']);
        self::assertSame('P-256', $jwk['crv']);
        self::assertSame('k', $jwk['kid']);
        self::assertNotNull(Keys::fromJwk($jwk));
        self::assertNull(Keys::fromJwk([...$jwk, 'crv' => 'P-384']));
        self::assertNull(Keys::fromJwk([...$jwk, 'x' => 'AAAA']));

        $this->expectException(\InvalidArgumentException::class);
        Keys::toPublicJwkJson(TestKeys::generate('secp384r1'), 'k');
    }

    public function testPrivateKeyPemMustBeP256(): void
    {
        Keys::loadPrivateKeyPem(TestKeys::pem($this->key));
        $this->addToAssertionCount(1);
        $this->expectException(\InvalidArgumentException::class);
        Keys::loadPrivateKeyPem(TestKeys::pem(TestKeys::generate('secp384r1')));
    }
}

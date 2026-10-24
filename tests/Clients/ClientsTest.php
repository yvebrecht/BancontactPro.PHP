<?php

declare(strict_types=1);

namespace BancontactPro\Tests\Clients;

use BancontactPro\ClientFactory;
use BancontactPro\Credentials\Credentials;
use BancontactPro\Credentials\ProductCredentials;
use BancontactPro\Credentials\SigningKey;
use BancontactPro\Enums\Environment;
use BancontactPro\Enums\MerchantPaymentStatus;
use BancontactPro\Enums\Product;
use BancontactPro\Enums\RefundStatus;
use BancontactPro\Exception\ApiException;
use BancontactPro\Exception\ProductNotConfiguredException;
use BancontactPro\Exception\RefundIdempotencyConflictException;
use BancontactPro\Exception\SigningKeyNotConfiguredException;
use BancontactPro\Models\CreatePaymentRequest;
use BancontactPro\Models\CreateRefundRequest;
use BancontactPro\Models\CreateStaticQrPaymentRequest;
use BancontactPro\Models\PaymentAcknowledgeRequest;
use BancontactPro\Models\PaymentSearchQuery;
use BancontactPro\Signing\Base64Url;
use BancontactPro\Signing\DetachedJws;
use BancontactPro\Tests\Support\MockHttp;
use BancontactPro\Tests\Support\TestKeys;
use DateTimeImmutable;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;

final class ClientsTest extends TestCase
{
    private const BASE = 'https://merchant.api.preprod.bancontact.net';
    private const PID = '5c18cbd1296e9a26d3278518';
    private const LINK = ['href' => 'https://x'];

    private OpenSSLAsymmetricKey $key;
    private Credentials $credentials;
    private MockHttp $http;

    protected function setUp(): void
    {
        $this->key = TestKeys::generate();
        $this->credentials = new Credentials(
            environment: Environment::Preprod,
            products: [Product::OnDisplay->value => new ProductCredentials('api-key', 'ppid-1')],
            signingKey: new SigningKey('my-kid', TestKeys::pem($this->key)),
        );
        $this->http = new MockHttp();
    }

    private function factory(Credentials|callable|null $credentials = null): ClientFactory
    {
        return new ClientFactory($credentials ?? $this->credentials, $this->http, new Psr17Factory(), new Psr17Factory());
    }

    /** @return array<string, mixed> */
    private static function created(): array
    {
        return [
            'paymentId' => self::PID,
            'status' => 'PENDING',
            'createdAt' => '2026-01-02T03:04:05.123456+00:00',
            'expiresAt' => '2026-01-02T03:06:05.000Z',
            'amount' => 1000,
            'currency' => 'EUR',
            'creditor' => ['name' => 'Shop'],
            '_links' => ['self' => self::LINK, 'deeplink' => self::LINK, 'qrcode' => self::LINK, 'checkout' => self::LINK],
        ];
    }

    /** @return array<string, mixed> */
    private static function payment(): array
    {
        $payment = self::created();
        $payment['expireAt'] = $payment['expiresAt'];
        unset($payment['expiresAt']);

        return $payment;
    }

    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        $decoded = json_decode((string) $this->http->last()->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testCreatePaymentSendsBearerOnlyAndOmitsUnsetFields(): void
    {
        $this->http->on('POST', self::BASE . '/v3/payments', 201, self::created());
        $response = $this->factory()->payments(Product::OnDisplay)->create(new CreatePaymentRequest(amount: 1000, reference: 'ref'));

        $request = $this->http->last();
        self::assertSame('Bearer api-key', $request->getHeaderLine('Authorization'));
        self::assertFalse($request->hasHeader('Signature')); // the Payment API is authenticated by the API key alone
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['amount' => 1000, 'reference' => 'ref'], $this->jsonBody());
        self::assertStringStartsWith('bancontactpro-php/', $request->getHeaderLine('User-Agent'));
        self::assertSame(MerchantPaymentStatus::Pending, $response->status);
        self::assertSame('https://x', $response->links->checkout?->href);
        self::assertSame('000000', $response->expiresAt->format('u'));
    }

    public function testGetCancelAndPathEscaping(): void
    {
        $payment = self::payment();
        $payment['debtor'] = ['iban' => 'BE**1234'];
        $this->http
            ->on('GET', self::BASE . '/v3/payments/' . self::PID, 200, $payment)
            ->on('DELETE', self::BASE . '/v3/payments/' . self::PID, 204)
            ->on('GET', self::BASE . '/v3/payments/a%2Fb%20c', 200, $payment);
        $client = $this->factory()->payments(Product::OnDisplay);

        $got = $client->get(self::PID);
        self::assertSame('BE**1234', $got->debtor?->iban);
        self::assertNull($got->succeededAt);
        $client->cancel(self::PID);
        self::assertSame(1, $this->http->count('DELETE', self::BASE . '/v3/payments/' . self::PID));
        $client->get('a/b c');
        self::assertSame(1, $this->http->count('GET', self::BASE . '/v3/payments/a%2Fb%20c'));
    }

    public function testSearchPagingAndBody(): void
    {
        $url = self::BASE . '/v3/payments/search?page=1&size=50';
        $this->http->on('POST', $url, 200, ['size' => 0, 'totalPages' => 0, 'totalElements' => 0, 'number' => 0, 'details' => []]);
        $result = $this->factory()->payments(Product::OnDisplay)->search(
            new PaymentSearchQuery(paymentStatuses: [MerchantPaymentStatus::Succeeded], reference: 'r'),
            page: 1,
            size: 50,
        );

        self::assertSame(['paymentStatuses' => ['SUCCEEDED'], 'reference' => 'r'], $this->jsonBody());
        self::assertSame([], $result->details);
    }

    public function testEmptySearchQueryIsAnEmptyJsonObjectAndDatesUseMilliseconds(): void
    {
        $this->http->on('POST', self::BASE . '/v3/payments/search', 200, ['size' => 0, 'totalPages' => 0, 'totalElements' => 0, 'number' => 0]);
        $client = $this->factory()->payments(Product::OnDisplay);

        $client->search();
        self::assertSame('{}', (string) $this->http->last()->getBody());

        $client->search(new PaymentSearchQuery(from: new DateTimeImmutable('2026-01-02T03:04:05.123456+02:00')));
        self::assertSame(['from' => '2026-01-02T01:04:05.123Z'], $this->jsonBody());
    }

    public function testAcknowledgeDebtorIbanAndStaticQr(): void
    {
        $this->http
            ->on('POST', self::BASE . '/v3/payments/' . self::PID . '/acknowledge', 204)
            ->on('GET', self::BASE . '/v3/payments/' . self::PID . '/debtor/refundIban', 200, ['iban' => 'BE68539007547034'])
            ->on('POST', self::BASE . '/v3/payments/pos', 201, self::created());
        $client = $this->factory()->payments(Product::OnDisplay);

        $client->acknowledge(self::PID, new PaymentAcknowledgeRequest('EUR', 1000));
        self::assertSame(['currency' => 'EUR', 'amount' => 1000], $this->jsonBody());
        self::assertSame('BE68539007547034', $client->getDebtorRefundIban(self::PID)->iban);
        $client->createStaticQrPayment(new CreateStaticQrPaymentRequest(amount: 5, posId: 'POS1', shopName: 'S'));
        self::assertSame(['amount' => 5, 'posId' => 'POS1', 'shopName' => 'S'], $this->jsonBody());
    }

    public function testUnknownStatusValueDoesNotCrash(): void
    {
        $payment = self::payment();
        $payment['status'] = 'SOMETHING_NEW';
        $this->http->on('GET', self::BASE . '/v3/payments/' . self::PID, 200, $payment);

        self::assertSame('SOMETHING_NEW', $this->factory()->payments(Product::OnDisplay)->get(self::PID)->status);
    }

    public function testPaymentErrorCarriesTraceAndSpan(): void
    {
        $this->http->on('GET', self::BASE . '/v3/payments/' . self::PID, 404, ['code' => 'PAYMENT_NOT_FOUND', 'message' => 'nope', 'traceId' => 't1', 'spanId' => 's1']);

        try {
            $this->factory()->payments(Product::OnDisplay)->get(self::PID);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame([404, 'PAYMENT_NOT_FOUND', 't1', 's1', 'nope'], [$e->statusCode, $e->errorCode, $e->traceId, $e->spanId, $e->getMessage()]);
        }
    }

    public function testNonJsonErrorFallsBackToGenericException(): void
    {
        $this->http->on('GET', self::BASE . '/v3/payments/' . self::PID, 502, '<html>Bad gateway</html>', ['WWW-Authenticate' => 'Bearer']);

        try {
            $this->factory()->payments(Product::OnDisplay)->get(self::PID);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame('', $e->errorCode);
            self::assertStringContainsString('502', $e->getMessage());
            self::assertStringContainsString('Bearer', $e->getMessage());
        }
    }

    public function testRefundIsSignedAndSendsIdempotencyKey(): void
    {
        $this->http->on('POST', self::BASE . '/v3/payments/' . self::PID . '/refunds', 201, [
            'refundId' => self::PID, 'paymentId' => self::PID, 'status' => 'PENDING', 'amount' => 500, 'currency' => 'EUR',
            'creationDate' => '2026-01-02T03:04:05Z',
        ]);
        $result = $this->factory()->refunds(Product::OnDisplay)->create(self::PID, new CreateRefundRequest(500, 'EUR'), 'key-1');

        $request = $this->http->last();
        self::assertSame('key-1', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame('Bearer api-key', $request->getHeaderLine('Authorization'));
        $signature = $request->getHeaderLine('Signature');
        self::assertTrue(DetachedJws::verify($signature, (string) $request->getBody(), TestKeys::publicOf($this->key)));
        $claims = json_decode(Base64Url::decode(explode('.', $signature)[0]), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertSame('/v3/payments/' . self::PID . '/refunds', $claims['https://payconiq.com/path']);
        self::assertSame('ppid-1', $claims['https://payconiq.com/sub']);
        self::assertSame('my-kid', $claims['kid']);
        self::assertSame('Payconiq', $claims['https://payconiq.com/iss']);
        self::assertSame(RefundStatus::Pending, $result->status);
    }

    public function testRefundWithoutKeyGetsAGeneratedUuid(): void
    {
        $this->http->on('POST', self::BASE . '/v3/payments/' . self::PID . '/refunds', 201, [
            'refundId' => self::PID, 'paymentId' => self::PID, 'status' => 'PENDING', 'amount' => 1, 'currency' => 'EUR', 'creationDate' => '2026-01-02T03:04:05Z',
        ]);
        $this->factory()->refunds(Product::OnDisplay)->create(self::PID, new CreateRefundRequest(1, 'EUR'));

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $this->http->last()->getHeaderLine('Idempotency-Key'));
    }

    public function testRefundConflictBecomesDedicatedException(): void
    {
        $this->http->on('POST', self::BASE . '/v3/payments/' . self::PID . '/refunds', 422, ['code' => 'REFUND_REQUEST_CONFLICT', 'message' => 'conflict']);

        try {
            $this->factory()->refunds(Product::OnDisplay)->create(self::PID, new CreateRefundRequest(500, 'EUR'), 'key-1');
            self::fail('Expected a conflict.');
        } catch (RefundIdempotencyConflictException $e) {
            self::assertSame('key-1', $e->idempotencyKey);
            self::assertNull($e->traceId);
            self::assertSame(422, $e->statusCode);
        }
    }

    public function testRefundRejectsOverlongIdempotencyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory()->refunds(Product::OnDisplay)->create(self::PID, new CreateRefundRequest(1, 'EUR'), str_repeat('x', 65));
    }

    public function testGetRefund(): void
    {
        $this->http->on('GET', self::BASE . '/v3/payments/' . self::PID . '/refunds/r1', 200, [
            'refundId' => 'r1', 'paymentId' => self::PID, 'status' => 'REFUNDED', 'amount' => 1, 'currency' => 'EUR', 'creationDate' => '2026-01-02T03:04:05Z',
        ]);

        self::assertSame(RefundStatus::Refunded, $this->factory()->refunds(Product::OnDisplay)->get(self::PID, 'r1')->status);
    }

    public function testReconciliationQueryStringsAndSignatureCoversPathWithoutQuery(): void
    {
        $this->http->on('GET', self::BASE . '/v3/reconciliation/payments?payout-id=' . self::PID . '&page=0', 200, [
            'size' => 0, 'totalPages' => 0, 'totalElements' => 0, 'number' => 0, 'payments' => [],
        ]);
        $this->factory()->reconciliation(Product::OnDisplay)->getPayments(payoutId: self::PID, page: 0);

        $request = $this->http->last();
        $signature = $request->getHeaderLine('Signature');
        $claims = json_decode(Base64Url::decode(explode('.', $signature)[0]), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertSame('/v3/reconciliation/payments', $claims['https://payconiq.com/path']);
        self::assertTrue(DetachedJws::verify($signature, '', TestKeys::publicOf($this->key)));
    }

    public function testReconciliationPayoutsRefundsAndDateRules(): void
    {
        $this->http
            ->on('GET', self::BASE . '/v3/reconciliation/payouts?date=2026-01-02', 200, ['size' => 0, 'totalPages' => 0, 'totalElements' => 0, 'number' => 0, 'payouts' => []])
            ->on('GET', self::BASE . '/v3/reconciliation/refunds?start-date=2026-01-01&end-date=2026-01-31&size=10000', 200, ['size' => 0, 'totalPages' => 0, 'totalElements' => 0, 'number' => 0, 'refunds' => []]);
        $client = $this->factory()->reconciliation(Product::OnDisplay);

        $client->getPayouts(new DateTimeImmutable('2026-01-02'));
        $client->getRefunds(startDate: new DateTimeImmutable('2026-01-01'), endDate: new DateTimeImmutable('2026-01-31'), size: 10000);
        self::assertSame(2, count($this->http->requests));

        try {
            $client->getPayments(startDate: new DateTimeImmutable('2026-01-01'));
            self::fail('start without end must be rejected');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        $client->getRefunds(startDate: new DateTimeImmutable('2026-01-01'), endDate: new DateTimeImmutable('2026-03-01'));
    }

    public function testMissingProductOrSigningKeyFailAtClientCreation(): void
    {
        try {
            $this->factory()->payments(Product::TopUp);
            self::fail('A product without credentials must be rejected.');
        } catch (ProductNotConfiguredException $e) {
            self::assertSame(Product::TopUp, $e->product);
        }

        $noKey = new Credentials(products: $this->credentials->products);
        self::assertTrue($this->factory($noKey)->isConfigured(Product::OnDisplay));
        self::assertFalse($this->factory($noKey)->isConfigured(Product::TopUp));
        $this->expectException(SigningKeyNotConfiguredException::class);
        $this->factory($noKey)->refunds(Product::OnDisplay);
    }

    public function testInvalidSigningKeyPemIsReportedClearly(): void
    {
        $bad = new Credentials(products: $this->credentials->products, signingKey: new SigningKey('k', 'not a pem'));

        $this->expectException(SigningKeyNotConfiguredException::class);
        $this->expectExceptionMessage("'k' is not a valid PEM-encoded EC");
        $this->factory($bad)->reconciliation(Product::OnDisplay);
    }

    public function testCredentialsAreReadPerCall(): void
    {
        $current = $this->credentials;
        $factory = $this->factory(static function () use (&$current): Credentials {
            return $current;
        });
        $this->http->on('GET', 'https://merchant.api.bancontact.net/v3/payments/' . self::PID, 200, self::payment());
        $current = new Credentials(Environment::Production, $this->credentials->products);

        $factory->payments(Product::OnDisplay)->get(self::PID);
        self::assertSame(1, $this->http->count('GET', 'https://merchant.api.bancontact.net/v3/payments/' . self::PID));
    }

    public function testSecretsDoNotLeakThroughDebugOutput(): void
    {
        $dump = print_r($this->credentials, true);
        ob_start();
        var_dump($this->credentials);
        $dump .= (string) ob_get_clean();

        self::assertStringNotContainsString('api-key', $dump);
        self::assertStringNotContainsString('PRIVATE KEY', $dump);
    }
}

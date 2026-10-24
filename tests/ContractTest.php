<?php

declare(strict_types=1);

namespace BancontactPro\Tests;

use BancontactPro\Enums\MerchantPaymentStatus;
use BancontactPro\Enums\PaymentChannel;
use BancontactPro\Enums\PayoutStatus;
use BancontactPro\Enums\RefundStatus;
use BancontactPro\Json\Wire;
use BancontactPro\Models;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract tests: the models, enums and operations must match the stored copies of Bancontact's OpenAPI specs.
 *
 * The specs in `tests/specs` are the versions below. When Bancontact publishes a newer spec these tests fail on
 * purpose, with a readable list of differences. To refresh them, see `tests/specs/README.md`.
 */
final class ContractTest extends TestCase
{
    private const VERSIONS = ['merchant-payment' => '3.6.5', 'refund-public' => '3.0.3', 'merchant-reconciliation' => '3.0.1'];

    // Fields we model as optional although the spec requires them: the Payment API's error schema requires traceId and
    // spanId, but the same class also serves the Refund and Reconciliation errors, which do not define them.
    private const OPTIONAL_HERE = ['error' => ['code', 'message', 'traceId', 'spanId'], 'ErrorResponse' => ['code', 'message']];

    // Modelled although this spec's schema lacks them: ErrorResponse is shared, and only the Payment API defines trace and span ids.
    private const EXTRA_OK = ['refund-public:ErrorResponse' => ['traceId', 'spanId']];

    // Every operation we cover: spec => "method path". A new or removed operation in a spec fails the test below.
    private const OPERATIONS = [
        'merchant-payment' => [
            'get /v3/payments/{id}', 'delete /v3/payments/{id}', 'post /v3/payments/search', 'post /v3/payments', 'post /v3/payments/pos',
            'post /v3/payments/{id}/acknowledge', 'get /v3/payments/{id}/debtor/refundIban',
            'post /callback', // served by the merchant, verified by CallbackVerifier
        ],
        'refund-public' => ['post /v3/payments/{payment-id}/refunds', 'get /v3/payments/{payment-id}/refunds/{refund-id}'],
        'merchant-reconciliation' => ['get /v3/reconciliation/payouts', 'get /v3/reconciliation/payments', 'get /v3/reconciliation/refunds'],
    ];

    /** @return array<string, mixed> */
    private static function load(string $name): array
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . "/specs/{$name}.openapi.json"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);

        /** @var array<string, mixed> $spec */
        return $spec;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private static function schema(array $spec, string $name): array
    {
        $components = $spec['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        $schema = $schemas[$name] ?? null;
        self::assertIsArray($schema);

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * Follows `$ref` and merges `allOf` into one schema with `properties` and `required`.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $schema
     * @return array{properties: array<string, mixed>, required: list<string>}
     */
    private static function resolve(array $spec, array $schema): array
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $node = $spec;
            foreach (explode('/', substr($schema['$ref'], 2)) as $part) {
                /** @var array<string, mixed> $node */
                $node = $node[$part];
            }

            return self::resolve($spec, $node);
        }
        /** @var array{properties: array<string, mixed>, required: list<string>} $merged */
        $merged = ['properties' => [], 'required' => []];
        $parts = is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [];
        foreach ($parts as $part) {
            /** @var array<string, mixed> $part */
            $sub = self::resolve($spec, $part);
            $merged['properties'] = [...$merged['properties'], ...$sub['properties']];
            $merged['required'] = [...$merged['required'], ...$sub['required']];
        }
        $own = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        /** @var array<string, mixed> $own */
        $merged['properties'] = [...$merged['properties'], ...$own];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($required as $name) {
            if (is_string($name)) {
                $merged['required'][] = $name;
            }
        }

        return $merged;
    }

    /**
     * (spec, schema, model class, spec properties deliberately not modelled)
     * totalAmount: listed as required but never defined (BancontactPro.Net#28). voucherEligibility: deprecated.
     *
     * @return iterable<string, array{string, string, class-string, list<string>}>
     */
    public static function models(): iterable
    {
        $rows = [
            ['merchant-payment', 'payment_create_request', Models\CreatePaymentRequest::class, ['voucherEligibility']],
            ['merchant-payment', 'static_qr_payment_create_request', Models\CreateStaticQrPaymentRequest::class, ['voucherEligibility']],
            ['merchant-payment', 'payment_create_response', Models\CreatePaymentResponse::class, []],
            ['merchant-payment', 'get_payment_response', Models\Payment::class, ['totalAmount']],
            ['merchant-payment', 'merchant-callback', Models\MerchantCallback::class, ['totalAmount']],
            ['merchant-payment', 'merchant-acknowledge', Models\PaymentAcknowledgeRequest::class, []],
            ['merchant-payment', 'payment-search-query', Models\PaymentSearchQuery::class, []],
            ['merchant-payment', 'PaymentSearchResponse', Models\PaymentSearchResponse::class, []],
            ['merchant-payment', 'refund-response', Models\DebtorRefundIban::class, []],
            ['merchant-payment', 'payment_creditor_response', Models\PaymentCreditor::class, []],
            ['merchant-payment', 'links', Models\PaymentLinks::class, []],
            ['merchant-payment', 'link', Models\PaymentLink::class, []],
            ['merchant-payment', 'error', Models\ErrorResponse::class, []],
            ['refund-public', 'CreateRefundRequest', Models\CreateRefundRequest::class, []],
            ['refund-public', 'RefundCreationResponse', Models\RefundCreationResponse::class, []],
            ['refund-public', 'RefundModel', Models\Refund::class, []],
            ['refund-public', 'ErrorResponse', Models\ErrorResponse::class, []],
            ['merchant-reconciliation', 'PayoutListItem', Models\Payout::class, []],
            ['merchant-reconciliation', 'PayoutListResponse', Models\PayoutListResponse::class, []],
            ['merchant-reconciliation', 'PaymentDetails', Models\ReconciliationPaymentDetails::class, []],
            ['merchant-reconciliation', 'PayoutPaymentsResponse', Models\PayoutPaymentsResponse::class, []],
            ['merchant-reconciliation', 'RefundDetails', Models\ReconciliationRefundDetails::class, []],
            ['merchant-reconciliation', 'PayoutRefundsResponse', Models\PayoutRefundsResponse::class, []],
        ];
        foreach ($rows as $row) {
            yield $row[0] . ':' . $row[1] => $row;
        }
    }

    /** @return iterable<string, array{string}> */
    public static function specNames(): iterable
    {
        foreach (array_keys(self::VERSIONS) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('specNames')]
    public function testStoredSpecVersion(string $name): void
    {
        $spec = self::load($name);
        self::assertIsArray($spec['info']);
        self::assertSame(self::VERSIONS[$name], $spec['info']['version']);
    }

    /**
     * @param class-string $class
     * @param list<string> $ignored
     */
    #[DataProvider('models')]
    public function testModelMatchesSchema(string $specName, string $schema, string $class, array $ignored): void
    {
        $spec = self::load($specName);
        $resolved = self::resolve($spec, self::schema($spec, $schema));
        $specProps = array_values(array_diff(array_keys($resolved['properties']), $ignored));
        $specRequired = array_values(array_diff($resolved['required'], $ignored));

        $fields = [];
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $fields[Wire::name($parameter->getName())] = !$parameter->isDefaultValueAvailable();
        }

        $problems = [];
        if (($missing = array_diff($specProps, array_keys($fields))) !== []) {
            $problems[] = 'in the spec but not modelled: ' . implode(', ', $missing);
        }
        $allowedExtra = self::EXTRA_OK[$specName . ':' . $schema] ?? [];
        if (($extra = array_diff(array_keys($fields), $specProps, $allowedExtra)) !== []) {
            $problems[] = 'modelled but not in the spec: ' . implode(', ', $extra);
        }
        foreach ($fields as $wire => $isRequired) {
            if (!in_array($wire, $specProps, true) || in_array($wire, self::OPTIONAL_HERE[$schema] ?? [], true)) {
                continue;
            }
            if ($isRequired !== in_array($wire, $specRequired, true)) {
                $problems[] = sprintf('%s: spec says required=%s, model says required=%s', $wire, var_export(in_array($wire, $specRequired, true), true), var_export($isRequired, true));
            }
        }
        self::assertSame([], $problems, "{$class} vs {$specName}:{$schema}: " . implode('; ', $problems));
    }

    /** @return iterable<string, array{string, string, class-string<\BackedEnum>}> */
    public static function enums(): iterable
    {
        yield 'merchant-payment-status' => ['merchant-payment', 'merchant-payment-status', MerchantPaymentStatus::class];
        yield 'RefundStatus' => ['refund-public', 'RefundStatus', RefundStatus::class];
        yield 'PayoutStatus' => ['merchant-reconciliation', 'PayoutStatus', PayoutStatus::class];
        yield 'PaymentChannel' => ['merchant-reconciliation', 'PaymentChannel', PaymentChannel::class];
    }

    /** @param class-string<\BackedEnum> $enum */
    #[DataProvider('enums')]
    public function testEnumMatchesSchema(string $specName, string $schema, string $enum): void
    {
        $spec = self::load($specName);
        /** @var list<int|string> $expected */
        $expected = self::schema($spec, $schema)['enum'];
        $actual = array_map(static fn(\BackedEnum $case): int|string => $case->value, $enum::cases());
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    #[DataProvider('specNames')]
    public function testOperationsMatchCoveredSet(string $name): void
    {
        $spec = self::load($name);
        $paths = $spec['paths'];
        self::assertIsArray($paths);
        $inSpec = [];
        foreach ($paths as $path => $item) {
            self::assertIsArray($item);
            foreach (array_keys($item) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'delete', 'patch'], true)) {
                    $inSpec[] = $method . ' ' . $path;
                }
            }
        }
        $expected = self::OPERATIONS[$name];
        sort($inSpec);
        sort($expected);

        self::assertSame($expected, $inSpec, "new in spec: " . implode(', ', array_diff($inSpec, $expected)) . '; gone from spec: ' . implode(', ', array_diff($expected, $inSpec)));
    }
}

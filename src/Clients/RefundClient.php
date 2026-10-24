<?php

declare(strict_types=1);

namespace BancontactPro\Clients;

use BancontactPro\Exception\ApiException;
use BancontactPro\Exception\RefundIdempotencyConflictException;
use BancontactPro\Http\SigningAuth;
use BancontactPro\Http\Transport;
use BancontactPro\Json\Hydrator;
use BancontactPro\Models\CreateRefundRequest;
use BancontactPro\Models\Refund;
use BancontactPro\Models\RefundCreationResponse;
use InvalidArgumentException;

/** Refund API v3. Requests are signed with the merchant's key. */
final class RefundClient
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * `POST v3/payments/{payment-id}/refunds`. Idempotent on the `Idempotency-Key` header (max 64 chars).
     *
     * Pass the same key when retrying the same refund. Generated (UUID) when omitted, in which case a retry would
     * create a second refund, so supply your own key for anything you may retry.
     */
    public function create(string $paymentId, CreateRefundRequest $request, ?string $idempotencyKey = null): RefundCreationResponse
    {
        $key = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : SigningAuth::uuid();
        if (strlen($key) > 64) {
            throw new InvalidArgumentException('The Idempotency-Key may be at most 64 characters.');
        }

        try {
            $data = $this->transport->request(
                'POST',
                'v3/payments/' . Transport::escape($paymentId) . '/refunds',
                $request,
                ['Idempotency-Key' => $key],
            );
        } catch (ApiException $e) {
            // The key was reused with different parameters: a caller bug, not a transient failure.
            if ($e->errorCode === 'REFUND_REQUEST_CONFLICT') {
                throw new RefundIdempotencyConflictException($key, $e);
            }
            throw $e;
        }

        return Hydrator::hydrate(RefundCreationResponse::class, $data);
    }

    /** `GET v3/payments/{payment-id}/refunds/{refund-id}`. */
    public function get(string $paymentId, string $refundId): Refund
    {
        return Hydrator::hydrate(
            Refund::class,
            $this->transport->request('GET', 'v3/payments/' . Transport::escape($paymentId) . '/refunds/' . Transport::escape($refundId)),
        );
    }
}

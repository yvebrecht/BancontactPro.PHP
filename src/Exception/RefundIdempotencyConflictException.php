<?php

declare(strict_types=1);

namespace BancontactPro\Exception;

/** The Idempotency-Key was reused with different parameters (`REFUND_REQUEST_CONFLICT`): a caller bug. */
final class RefundIdempotencyConflictException extends ApiException
{
    public function __construct(public readonly string $idempotencyKey, ApiException $inner)
    {
        parent::__construct(
            $inner->statusCode,
            $inner->errorCode,
            "The idempotency key '{$idempotencyKey}' was already used for a refund with different parameters. {$inner->getMessage()}",
            $inner->traceId,
            $inner->spanId,
        );
    }
}

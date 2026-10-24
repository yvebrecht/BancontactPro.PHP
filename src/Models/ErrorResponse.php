<?php

declare(strict_types=1);

namespace BancontactPro\Models;

/**
 * Payment API errors also carry traceId/spanId; Refund and Reconciliation errors do not.
 */
final readonly class ErrorResponse
{
    public function __construct(
        public ?string $code = null,
        public ?string $message = null,
        public ?string $traceId = null,
        public ?string $spanId = null,
    ) {}
}

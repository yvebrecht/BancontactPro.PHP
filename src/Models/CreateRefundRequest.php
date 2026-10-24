<?php

declare(strict_types=1);

namespace BancontactPro\Models;

/**
 * `POST v3/payments/{payment-id}/refunds`. Partial refunds are simply any amount.
 */
final readonly class CreateRefundRequest
{
    public function __construct(
        public int $amount,
        public string $currency,
        public ?string $description = null,
    ) {}
}

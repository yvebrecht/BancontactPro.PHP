<?php

declare(strict_types=1);

namespace BancontactPro\Models;

use BancontactPro\Enums\RefundStatus;
use DateTimeImmutable;

/**
 * A refund as returned by `GET v3/payments/{payment-id}/refunds/{refund-id}` (spec schema RefundModel).
 */
final readonly class Refund
{
    public function __construct(
        public string $refundId,
        public string $paymentId,
        public int $amount,
        public string $currency,
        public RefundStatus|string $status,
        public DateTimeImmutable $creationDate,
        public ?string $description = null,
    ) {}
}

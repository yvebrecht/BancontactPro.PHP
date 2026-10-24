<?php

declare(strict_types=1);

namespace BancontactPro\Models;

use BancontactPro\Enums\RefundStatus;
use DateTimeImmutable;

final readonly class RefundCreationResponse
{
    public function __construct(
        public string $refundId,
        public string $paymentId,
        public RefundStatus|string $status,
        public int $amount,
        public string $currency,
        public DateTimeImmutable $creationDate,
        public ?string $description = null,
    ) {}
}

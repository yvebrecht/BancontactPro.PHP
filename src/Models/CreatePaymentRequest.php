<?php

declare(strict_types=1);

namespace BancontactPro\Models;

/**
 * `POST v3/payments`. Only `amount` (cents, 1-999,999,999,999) is required.
 */
final readonly class CreatePaymentRequest
{
    public function __construct(
        public int $amount,
        public ?string $reference = null,
        public ?string $bulkId = null,
        public ?string $currency = null,
        public ?string $description = null,
        public ?string $identifyCallbackUrl = null,
        public ?string $callbackUrl = null,
        public ?string $returnUrl = null,
    ) {}
}

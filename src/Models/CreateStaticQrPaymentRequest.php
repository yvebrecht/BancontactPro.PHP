<?php

declare(strict_types=1);

namespace BancontactPro\Models;

/**
 * `POST v3/payments/pos`. An active payment for the same pos id/profile is replaced.
 */
final readonly class CreateStaticQrPaymentRequest
{
    public function __construct(
        public int $amount,
        public string $posId,
        public ?string $reference = null,
        public ?string $bulkId = null,
        public ?string $currency = null,
        public ?string $description = null,
        public ?string $shopId = null,
        public ?string $shopName = null,
        public ?string $identifyCallbackUrl = null,
        public ?string $callbackUrl = null,
    ) {}
}

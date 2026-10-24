<?php

declare(strict_types=1);

namespace BancontactPro\Models;

use BancontactPro\Enums\MerchantPaymentStatus;
use DateTimeImmutable;

/**
 * The webhook body. Like Payment it has no totalAmount: the spec lists it as required but never defines it.
 */
final readonly class MerchantCallback
{
    public function __construct(
        public string $paymentId,
        public string $currency,
        public int $amount,
        public DateTimeImmutable $createdAt,
        public MerchantPaymentStatus|string $status,
        public CallbackDebtor $debtor,
        public ?string $description = null,
        public ?string $reference = null,
        public ?DateTimeImmutable $expireAt = null,
        public ?DateTimeImmutable $succeededAt = null,
    ) {}
}

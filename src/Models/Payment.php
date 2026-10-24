<?php

declare(strict_types=1);

namespace BancontactPro\Models;

use BancontactPro\Enums\MerchantPaymentStatus;
use DateTimeImmutable;

/**
 * `links` is optional in the get-payment response, unlike in the create response.
 */
final readonly class Payment
{
    public function __construct(
        public string $paymentId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expireAt,
        public string $currency,
        public MerchantPaymentStatus|string $status,
        public PaymentCreditor $creditor,
        public int $amount,
        public ?PaymentLinks $links = null,
        public ?DateTimeImmutable $succeededAt = null,
        public ?PaymentDebtor $debtor = null,
        public ?string $description = null,
        public ?string $message = null,
        public ?string $reference = null,
        public ?string $bulkId = null,
    ) {}
}

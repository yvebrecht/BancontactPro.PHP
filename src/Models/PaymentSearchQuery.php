<?php

declare(strict_types=1);

namespace BancontactPro\Models;

use BancontactPro\Enums\MerchantPaymentStatus;
use BancontactPro\Json\ListOf;
use DateTimeImmutable;

/**
 * Filter body for `POST v3/payments/search`. `from` defaults server-side to yesterday.
 */
final readonly class PaymentSearchQuery
{
    public function __construct(
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        /** @var list<MerchantPaymentStatus>|null */
        #[ListOf(MerchantPaymentStatus::class)]
        public ?array $paymentStatuses = null,
        public ?string $reference = null,
    ) {}
}

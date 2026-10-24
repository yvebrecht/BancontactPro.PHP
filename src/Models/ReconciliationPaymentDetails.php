<?php

declare(strict_types=1);

namespace BancontactPro\Models;

use BancontactPro\Enums\PaymentChannel;
use DateTimeImmutable;

final readonly class ReconciliationPaymentDetails
{
    public function __construct(
        public string $paymentId,
        public string $paymentProfileId,
        public string $merchantName,
        public PaymentChannel|string $paymentChannel,
        public string $currency,
        public int $amount,
        public DateTimeImmutable $transactionDate,
        public ?string $payoutId = null,
        public ?string $reference = null,
        public ?string $description = null,
    ) {}
}

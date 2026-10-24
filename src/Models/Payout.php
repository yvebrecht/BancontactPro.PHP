<?php

declare(strict_types=1);

namespace BancontactPro\Models;

use BancontactPro\Enums\PayoutStatus;
use DateTimeImmutable;

final readonly class Payout
{
    public function __construct(
        public string $payoutId,
        public string $merchantId,
        public string $bulkId,
        public string $iban,
        public PayoutStatus|string $payoutStatus,
        public DateTimeImmutable $payoutDate,
        public string $payoutCurrency,
        public int $totalPayments,
        public int $totalRefunds,
        public int $totalPaymentAmount,
        public int $totalRefundAmount,
        public int $payoutAmount,
    ) {}
}

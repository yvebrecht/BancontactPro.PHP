<?php

declare(strict_types=1);

namespace BancontactPro\Models;

use BancontactPro\Json\ListOf;

final readonly class PayoutPaymentsResponse
{
    public function __construct(
        public int $size,
        public int $totalPages,
        public int $totalElements,
        public int $number,
        /** @var list<ReconciliationPaymentDetails> */
        #[ListOf(ReconciliationPaymentDetails::class)]
        public array $payments,
    ) {}
}

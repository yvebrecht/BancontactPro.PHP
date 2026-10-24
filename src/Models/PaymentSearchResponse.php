<?php

declare(strict_types=1);

namespace BancontactPro\Models;

use BancontactPro\Json\ListOf;

final readonly class PaymentSearchResponse
{
    public function __construct(
        public int $size,
        public int $totalPages,
        public int $totalElements,
        public int $number,
        /** @var list<Payment> not required by the spec */
        #[ListOf(Payment::class)]
        public array $details = [],
    ) {}
}

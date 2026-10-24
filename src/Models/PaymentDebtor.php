<?php

declare(strict_types=1);

namespace BancontactPro\Models;

final readonly class PaymentDebtor
{
    public function __construct(
        public ?string $name = null,
        public ?string $iban = null,
    ) {}
}

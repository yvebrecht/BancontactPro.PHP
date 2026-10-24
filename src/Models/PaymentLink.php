<?php

declare(strict_types=1);

namespace BancontactPro\Models;

final readonly class PaymentLink
{
    public function __construct(
        public string $href,
    ) {}
}

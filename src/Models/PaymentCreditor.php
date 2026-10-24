<?php

declare(strict_types=1);

namespace BancontactPro\Models;

final readonly class PaymentCreditor
{
    public function __construct(
        public ?string $profileId = null,
        public ?string $merchantId = null,
        public ?string $name = null,
        public ?string $iban = null,
        public ?string $identifyCallbackUrl = null,
        public ?string $callbackUrl = null,
    ) {}
}

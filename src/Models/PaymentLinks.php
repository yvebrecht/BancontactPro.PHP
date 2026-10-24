<?php

declare(strict_types=1);

namespace BancontactPro\Models;

final readonly class PaymentLinks
{
    public function __construct(
        public PaymentLink $self,
        public PaymentLink $deeplink,
        public PaymentLink $qrcode,
        public ?PaymentLink $cancel = null,
        public ?PaymentLink $refund = null,
        public ?PaymentLink $checkout = null,
    ) {}
}

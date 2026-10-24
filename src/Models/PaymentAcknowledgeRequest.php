<?php

declare(strict_types=1);

namespace BancontactPro\Models;

/**
 * `POST v3/payments/{id}/acknowledge` for the VOID-mode flow.
 */
final readonly class PaymentAcknowledgeRequest
{
    public function __construct(
        public string $currency,
        public int $amount,
        public ?string $reference = null,
    ) {}
}

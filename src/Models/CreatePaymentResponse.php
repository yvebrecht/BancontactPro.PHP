<?php

declare(strict_types=1);

namespace BancontactPro\Models;

use BancontactPro\Enums\MerchantPaymentStatus;
use DateTimeImmutable;

/**
 * Note `expiresAt` here against `Payment::$expireAt`: the spec spells them differently.
 */
final readonly class CreatePaymentResponse
{
    public function __construct(
        public string $paymentId,
        public MerchantPaymentStatus|string $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public int $amount,
        public string $currency,
        public PaymentCreditor $creditor,
        public PaymentLinks $links,
        public ?string $description = null,
        public ?string $reference = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace BancontactPro\Enums;

/**
 * PROCESSING was removed in Refund API v3.0.2; it is deliberately not modelled.
 */
enum RefundStatus: string
{
    case Pending = 'PENDING';
    case Refunded = 'REFUNDED';
    case Failed = 'FAILED';
}

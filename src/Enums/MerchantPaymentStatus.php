<?php

declare(strict_types=1);

namespace BancontactPro\Enums;

/**
 * Payment status (Payment API v3.6.5).
 */
enum MerchantPaymentStatus: string
{
    case Pending = 'PENDING';
    case Identified = 'IDENTIFIED';
    case Authorized = 'AUTHORIZED';
    case AuthorizationFailed = 'AUTHORIZATION_FAILED';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case PendingMerchantAcknowledgement = 'PENDING_MERCHANT_ACKNOWLEDGEMENT';
    case Voided = 'VOIDED';
}

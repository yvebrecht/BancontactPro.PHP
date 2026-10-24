<?php

declare(strict_types=1);

namespace BancontactPro\Enums;

/**
 * The four Bancontact Pro product types; Bancontact issues an API key and PPID per product.
 */
enum Product: string
{
    case OnDisplay = 'OnDisplay';
    case OnReceipt = 'OnReceipt';
    case StaticQr = 'StaticQr';
    case TopUp = 'TopUp';
}

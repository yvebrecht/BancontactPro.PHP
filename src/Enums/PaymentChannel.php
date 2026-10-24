<?php

declare(strict_types=1);

namespace BancontactPro\Enums;

enum PaymentChannel: string
{
    case Online = 'ONLINE';
    case Instore = 'INSTORE';
    case Invoice = 'INVOICE';
}

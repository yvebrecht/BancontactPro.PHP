<?php

declare(strict_types=1);

namespace BancontactPro\Enums;

enum Environment: string
{
    case Preprod = 'Preprod';
    case Production = 'Production';
}

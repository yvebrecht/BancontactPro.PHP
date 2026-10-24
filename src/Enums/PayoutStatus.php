<?php

declare(strict_types=1);

namespace BancontactPro\Enums;

enum PayoutStatus: string
{
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
}

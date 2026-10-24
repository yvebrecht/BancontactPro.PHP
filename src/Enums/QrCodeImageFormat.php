<?php

declare(strict_types=1);

namespace BancontactPro\Enums;

enum QrCodeImageFormat: string
{
    case Png = 'PNG';
    case Svg = 'SVG';
}

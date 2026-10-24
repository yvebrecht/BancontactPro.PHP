<?php

declare(strict_types=1);

namespace BancontactPro\Exception;

use BancontactPro\Enums\Product;
use LogicException;

final class ProductNotConfiguredException extends LogicException implements BancontactException
{
    public function __construct(public readonly Product $product)
    {
        parent::__construct(
            "Bancontact product '{$product->value}' is not configured: it needs both its apiKey and its "
            . 'merchantProfileId (PPID), the pair Bancontact issued for this product.',
        );
    }
}

<?php

declare(strict_types=1);

namespace BancontactPro\Json;

use Attribute;

/**
 * Declares the element type of an array property or parameter (a class name, or an enum), since PHP arrays are untyped.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class ListOf
{
    /** @param class-string $type */
    public function __construct(public readonly string $type) {}
}

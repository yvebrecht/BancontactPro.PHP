<?php

declare(strict_types=1);

namespace BancontactPro\Models;

final readonly class CallbackDebtor
{
    public function __construct(
        public string $iban,
        public ?string $name = null,
    ) {}
}

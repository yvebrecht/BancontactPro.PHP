<?php

declare(strict_types=1);

namespace BancontactPro\Models;

final readonly class DebtorRefundIban
{
    public function __construct(
        public string $iban,
    ) {}
}

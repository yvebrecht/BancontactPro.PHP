<?php

declare(strict_types=1);

namespace BancontactPro\Signing;

use DateTimeImmutable;

final readonly class JoseHeader
{
    public function __construct(
        public string $kid,
        public string $merchantProfileId,
        public string $issuer,
        public DateTimeImmutable $issuedAt,
        public string $requestId,
        public string $path,
    ) {}
}

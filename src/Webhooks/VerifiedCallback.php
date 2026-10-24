<?php

declare(strict_types=1);

namespace BancontactPro\Webhooks;

use BancontactPro\Models\MerchantCallback;

final readonly class VerifiedCallback
{
    /**
     * @param string $requestId the `jti` claim: unique per message. Bancontact retries a callback with identical
     *     headers and body, so handlers should tolerate duplicates.
     */
    public function __construct(
        public MerchantCallback $payload,
        public string $requestId,
    ) {}
}

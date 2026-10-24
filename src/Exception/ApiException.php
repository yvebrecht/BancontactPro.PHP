<?php

declare(strict_types=1);

namespace BancontactPro\Exception;

use RuntimeException;

/**
 * Bancontact returned a non-success HTTP response.
 *
 * `traceId`/`spanId` are what to hand Bancontact support; only the Payment API's errors include them.
 * `errorCode` is empty when the response body was not Bancontact's error shape.
 */
class ApiException extends RuntimeException implements BancontactException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $traceId = null,
        public readonly ?string $spanId = null,
    ) {
        parent::__construct($message, $statusCode);
    }
}

<?php

declare(strict_types=1);

namespace BancontactPro\Webhooks;

use BancontactPro\Exception\InvalidCallbackException;
use BancontactPro\Json\Hydrator;
use BancontactPro\Models\MerchantCallback;
use BancontactPro\Signing\SignatureVerifier;
use InvalidArgumentException;
use JsonException;

/**
 * The entry point for inbound webhooks: verify the signature, then parse the body.
 *
 * Failure throws `InvalidCallbackException`: "this callback cannot be trusted" should stop request processing, not be
 * a value the caller might ignore. Answer `200 OK` only after verifying and processing.
 */
final class CallbackVerifier
{
    public function __construct(private readonly SignatureVerifier $signatureVerifier) {}

    /**
     * @param string $signatureHeader the `Signature` header
     * @param string $body the raw request body (not re-serialised JSON)
     * @param string $path the request path, without the query string, as received
     * @throws InvalidCallbackException
     */
    public function verifyAndParse(string $signatureHeader, string $body, string $path): VerifiedCallback
    {
        $claims = $this->signatureVerifier->verify($signatureHeader, $body, $path);
        if ($claims === null) {
            throw new InvalidCallbackException("The callback's signature could not be verified.");
        }

        try {
            $payload = Hydrator::hydrate(MerchantCallback::class, json_decode($body, true, 32, JSON_THROW_ON_ERROR));
        } catch (JsonException|InvalidArgumentException $e) {
            throw new InvalidCallbackException('The callback body could not be parsed.', 0, $e);
        }

        return new VerifiedCallback($payload, $claims->requestId);
    }
}

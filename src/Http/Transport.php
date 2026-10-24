<?php

declare(strict_types=1);

namespace BancontactPro\Http;

use BancontactPro\Exception\ApiException;
use BancontactPro\Json\Serializer;
use BancontactPro\Version;
use JsonException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use stdClass;

/**
 * One configured connection to the merchant API: base URL, credentials, shared PSR-18 client.
 *
 * Timeouts are the PSR-18 client's own configuration; the Payment API can take a while, so do not configure much
 * below 100 seconds for `create` calls. A time-out on `create` has an unknown outcome.
 */
final class Transport
{
    public const USER_AGENT_PREFIX = 'bancontactpro-php/';

    private readonly string $baseUrl;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        string $baseUrl,
        private readonly Auth $auth,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Percent-encodes one path or query value. */
    public static function escape(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Sends the request, throws `ApiException` on a non-success status, returns decoded JSON (or null for an empty
     * body). `$relativePath` may carry an already-encoded query string.
     *
     * @param array<string, string> $headers
     */
    public function request(string $method, string $relativePath, ?object $body = null, array $headers = []): mixed
    {
        [$path, $query] = array_pad(explode('?', $relativePath, 2), 2, '');
        // An empty request model still has to be a JSON object, not an empty array.
        $content = $body === null ? '' : json_encode(Serializer::toArray($body) ?: new stdClass(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers += [
            'User-Agent' => self::USER_AGENT_PREFIX . Version::VALUE . ' (+https://github.com/yvebrecht/BancontactPro.PHP)',
            'Accept' => 'application/json',
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $headers = $this->auth->apply($headers, $method, '/' . $path, $content);

        $request = $this->requests->createRequest($method, $this->baseUrl . '/' . $path . ($query !== '' ? '?' . $query : ''));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($content !== '') {
            $request = $request->withBody($this->streams->createStream($content));
        }

        $response = $this->http->sendRequest($request);
        self::ensureSuccess($response);
        $text = (string) $response->getBody();
        if (trim($text) === '') {
            return null;
        }
        try {
            return json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException($response->getStatusCode(), '', 'Bancontact returned a success status with a body that is not JSON.', null, null);
        }
    }

    /**
     * Turns a non-success response into `ApiException`.
     *
     * `traceId`/`spanId` are only defined by the Payment API's error schema; they are null for Refund and
     * Reconciliation errors. A body that is not Bancontact's error shape (an upstream 502, say) falls back to a
     * generic exception carrying the raw status and text, never an unrelated parse error.
     */
    public static function ensureSuccess(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $text = (string) $response->getBody();
        $error = null;
        try {
            $parsed = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($parsed) && isset($parsed['code']) && is_string($parsed['code']) && $parsed['code'] !== '') {
                $error = $parsed;
            }
        } catch (JsonException) {
        }

        if ($error !== null) {
            $message = isset($error['message']) && is_string($error['message']) && $error['message'] !== '' ? $error['message'] : $text;
            throw new ApiException(
                $status,
                $error['code'],
                $message,
                isset($error['traceId']) && is_string($error['traceId']) ? $error['traceId'] : null,
                isset($error['spanId']) && is_string($error['spanId']) ? $error['spanId'] : null,
            );
        }

        $message = trim($text) === '' ? "Bancontact returned {$status} with no response body." : "Bancontact returned {$status}: {$text}";
        $challenge = $response->getHeaderLine('WWW-Authenticate');
        if ($challenge !== '') {
            $message .= " (WWW-Authenticate: {$challenge})";
        }
        throw new ApiException($status, '', $message);
    }
}

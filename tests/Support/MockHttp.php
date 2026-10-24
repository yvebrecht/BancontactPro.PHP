<?php

declare(strict_types=1);

namespace BancontactPro\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** A PSR-18 client with canned responses per "METHOD url" that records every request it receives. */
final class MockHttp implements ClientInterface
{
    /** @var array<string, array{int, string, array<string, string>}> */
    private array $routes = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param array<mixed>|string|null $body an array is sent as JSON
     * @param array<string, string> $headers
     */
    public function on(string $method, string $url, int $status = 200, array|string|null $body = null, array $headers = []): self
    {
        $this->routes[strtoupper($method) . ' ' . $url] = [
            $status,
            is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : (string) $body,
            $headers,
        ];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $route = $this->routes[$request->getMethod() . ' ' . (string) $request->getUri()] ?? null;
        if ($route === null) {
            throw new RuntimeException('No mock route for ' . $request->getMethod() . ' ' . $request->getUri());
        }

        return new Response($route[0], $route[2], $route[1]);
    }

    public function last(): RequestInterface
    {
        $last = end($this->requests);

        return $last !== false ? $last : throw new RuntimeException('No request was sent.');
    }

    public function count(string $method, string $url): int
    {
        return count(array_filter(
            $this->requests,
            static fn(RequestInterface $r): bool => $r->getMethod() === strtoupper($method) && (string) $r->getUri() === $url,
        ));
    }
}

<?php

declare(strict_types=1);

namespace BancontactPro\Http;

/** Adds the credentials of one API to an outgoing request. */
interface Auth
{
    /**
     * @param array<string, string> $headers
     * @param string $path the absolute path of the request URI, without the query string, as sent
     * @return array<string, string>
     */
    public function apply(array $headers, string $method, string $path, string $body): array;
}

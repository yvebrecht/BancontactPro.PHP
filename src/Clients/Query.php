<?php

declare(strict_types=1);

namespace BancontactPro\Clients;

/** Builds the query strings the three clients share. */
final class Query
{
    /** @param list<string> $params */
    public static function append(string $path, array $params): string
    {
        return $params === [] ? $path : $path . '?' . implode('&', $params);
    }

    /** @param list<string> $params */
    public static function paging(array &$params, ?int $page, ?int $size): void
    {
        if ($page !== null) {
            $params[] = 'page=' . $page;
        }
        if ($size !== null) {
            $params[] = 'size=' . $size;
        }
    }
}

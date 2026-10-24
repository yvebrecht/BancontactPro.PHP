<?php

declare(strict_types=1);

namespace BancontactPro\Json;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Wire-format helpers shared by the hydrator and the serializer.
 *
 * Property names are the spec's camelCase names; the only exception is the hypermedia property, which is literally
 * `_links` on the wire and `$links` in PHP.
 */
final class Wire
{
    public static function name(string $property): string
    {
        return $property === 'links' ? '_links' : $property;
    }

    /** Requests send `yyyy-MM-ddTHH:mm:ss.fffZ`, in UTC. */
    public static function formatDateTime(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Responses are parsed tolerantly: with or without microseconds, `Z` or `+00:00`; a missing zone means UTC. */
    public static function parseDateTime(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new InvalidArgumentException("'{$value}' is not a valid date-time.", 0, $e);
        }
    }
}

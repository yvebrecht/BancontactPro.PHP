<?php

declare(strict_types=1);

namespace BancontactPro\Json;

use BackedEnum;
use DateTimeInterface;
use ReflectionClass;

/**
 * Turns a request model into JSON-ready data. Unset (null) optional fields are omitted, never sent as null: the specs
 * define them as optional, not nullable, and an explicit null can be rejected.
 */
final class Serializer
{
    /** @return array<string, mixed> */
    public static function toArray(object $model): array
    {
        $result = [];
        foreach ((new ReflectionClass($model))->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }
            $value = $property->getValue($model);
            if ($value === null) {
                continue;
            }
            $result[Wire::name($property->getName())] = self::convert($value);
        }

        return $result;
    }

    private static function convert(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => Wire::formatDateTime($value),
            is_object($value) => self::toArray($value),
            is_array($value) => array_map(self::convert(...), $value),
            default => $value,
        };
    }
}

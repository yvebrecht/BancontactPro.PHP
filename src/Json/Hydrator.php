<?php

declare(strict_types=1);

namespace BancontactPro\Json;

use BackedEnum;
use DateTimeImmutable;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Builds response models from decoded JSON by reading the constructor signature. Unknown properties are ignored.
 *
 * A property typed `SomeEnum|string` receives the enum case, or the raw string when the server sends a value this
 * version does not know, so a new status never breaks parsing of an otherwise valid response.
 */
final class Hydrator
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public static function hydrate(string $class, mixed $data): object
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException("Expected a JSON object for {$class}.");
        }

        $reflection = new ReflectionClass($class);
        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $wire = Wire::name($parameter->getName());
            $present = array_key_exists($wire, $data) && ($data[$wire] !== null || !$parameter->isDefaultValueAvailable());
            if (!$present) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                throw new InvalidArgumentException("Missing required property '{$wire}' for " . $reflection->getShortName() . '.');
            }
            $arguments[$parameter->getName()] = self::convert($parameter->getType(), $data[$wire], $parameter, $wire);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private static function convert(?ReflectionType $type, mixed $value, ReflectionParameter $parameter, string $wire): mixed
    {
        if ($value === null) {
            if ($type === null || $type->allowsNull()) {
                return null;
            }
            throw new InvalidArgumentException("Property '{$wire}' must not be null.");
        }

        $names = self::typeNames($type);

        if (is_array($value) && in_array('array', $names, true)) {
            $listOf = $parameter->getAttributes(ListOf::class)[0] ?? null;
            if ($listOf === null) {
                return $value;
            }
            $elementType = $listOf->newInstance()->type;

            return array_map(static fn(mixed $item): mixed => self::convertNamed($elementType, $item, $wire), array_values($value));
        }

        // Prefer a class/enum type over the plain `string` fallback of a tolerant enum property.
        foreach ($names as $name) {
            if (class_exists($name) || enum_exists($name)) {
                return self::convertNamed($name, $value, $wire, in_array('string', $names, true));
            }
        }

        foreach ($names as $name) {
            $converted = self::scalar($name, $value);
            if ($converted !== null) {
                return $converted;
            }
        }

        throw new InvalidArgumentException("Property '{$wire}' has an unexpected value type.");
    }

    private static function convertNamed(string $name, mixed $value, string $wire, bool $tolerant = false): mixed
    {
        if (is_a($name, BackedEnum::class, true)) {
            $case = (is_string($value) || is_int($value)) ? $name::tryFrom($value) : null;
            if ($case !== null) {
                return $case;
            }
            if ($tolerant && is_string($value)) {
                return $value;
            }
            throw new InvalidArgumentException("Property '{$wire}' has the unknown value '" . (is_scalar($value) ? (string) $value : gettype($value)) . "'.");
        }
        if ($name === DateTimeImmutable::class) {
            if (!is_string($value)) {
                throw new InvalidArgumentException("Property '{$wire}' must be a date-time string.");
            }

            return Wire::parseDateTime($value);
        }
        if (class_exists($name)) {
            return self::hydrate($name, $value);
        }

        return $value;
    }

    private static function scalar(string $name, mixed $value): mixed
    {
        return match ($name) {
            'int' => is_int($value) ? $value : (is_float($value) && floor($value) === $value ? (int) $value : null),
            'float' => is_int($value) || is_float($value) ? (float) $value : null,
            'string' => is_string($value) ? $value : null,
            'bool' => is_bool($value) ? $value : null,
            default => null,
        };
    }

    /** @return list<string> */
    private static function typeNames(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName() === 'null' ? [] : [$type->getName()];
        }
        if ($type instanceof ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $inner) {
                if ($inner instanceof ReflectionNamedType && $inner->getName() !== 'null') {
                    $names[] = $inner->getName();
                }
            }

            return $names;
        }

        return [];
    }
}

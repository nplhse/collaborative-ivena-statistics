<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\DataTable;

use Symfony\Component\PropertyAccess\Exception\AccessException;
use Symfony\Component\PropertyAccess\Exception\UnexpectedTypeException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Uid\Uuid;

final class DataTableValueResolver
{
    private readonly PropertyAccessorInterface $accessor;

    public function __construct(?PropertyAccessorInterface $accessor = null)
    {
        $this->accessor = $accessor ?? PropertyAccess::createPropertyAccessor();
    }

    /**
     * @param object|array<string, mixed> $row
     */
    public function resolve(object|array $row, DataTableColumn $column): mixed
    {
        $value = null;
        $property = $column->property;
        if (null !== $property) {
            $value = $this->read($row, $property);
            if (null !== $value && '' !== $value) {
                return $value;
            }
        }

        if (null !== $column->fallbackProperty) {
            return $this->read($row, $column->fallbackProperty);
        }

        return $value;
    }

    /**
     * @param object|array<string, mixed> $row
     */
    public function read(object|array $row, string $property): mixed
    {
        try {
            return $this->accessor->getValue($row, $this->pathFor($row, $property));
        } catch (AccessException|UnexpectedTypeException|\InvalidArgumentException) {
            if (\is_array($row) && \array_key_exists($property, $row)) {
                return $row[$property];
            }

            return null;
        }
    }

    public function scalar(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }

    public function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    /**
     * @param object|array<string, mixed> $row
     */
    private function pathFor(object|array $row, string $property): string
    {
        if (!\is_array($row)) {
            return $property;
        }

        if (str_starts_with($property, '[')) {
            return $property;
        }

        $segments = explode('.', $property);
        $path = '';
        foreach ($segments as $segment) {
            $path .= '['.$segment.']';
        }

        return $path;
    }
}

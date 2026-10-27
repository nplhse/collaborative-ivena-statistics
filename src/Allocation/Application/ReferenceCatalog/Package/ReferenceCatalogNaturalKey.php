<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Application\ReferenceCatalog\ReferenceNameKey;
use App\Allocation\Domain\IndicationKey;

/**
 * Natural key of a catalog row, the same for a package entry and a database row (ADR 020).
 *
 * Package validation uses it for duplicates; the import uses it to match rows without an id match.
 */
final class ReferenceCatalogNaturalKey
{
    public static function of(CatalogEntry $entry): string
    {
        return match (true) {
            $entry instanceof DispatchAreaEntry => self::dispatchArea($entry->state, $entry->name),
            $entry instanceof NormalizedIndicationEntry => self::normalizedIndication($entry->code, $entry->name),
            $entry instanceof RawIndicationEntry => self::rawIndication($entry->code, $entry->name),
            default => self::name($entry->name),
        };
    }

    public static function name(string $name): string
    {
        return ReferenceNameKey::normalize($name);
    }

    public static function dispatchArea(string $stateId, string $name): string
    {
        return strtolower($stateId).'|'.self::name($name);
    }

    public static function normalizedIndication(string $code, string $name): string
    {
        return IndicationKey::normalizeCode($code).'|'.self::name($name);
    }

    public static function rawIndication(string $code, string $name): string
    {
        return IndicationKey::hashFrom($code, $name);
    }

    public static function label(ReferenceCatalogType $type): string
    {
        return match ($type) {
            ReferenceCatalogType::DispatchArea => 'state and name',
            ReferenceCatalogType::IndicationNormalized => 'code and name',
            ReferenceCatalogType::IndicationRaw => 'code and label',
            default => 'name',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\DataCatalog;

use Doctrine\DBAL\Types\Types;

enum DataCatalogType: string
{
    case INTEGER = 'integer';
    case DECIMAL = 'decimal';
    case DATETIME = 'datetime';
    case DATE = 'date';
    case TIME = 'time';
    case BOOLEAN = 'boolean';
    case CODE = 'code';
    case CODE_SET = 'code_set';
    case TEXT = 'text';
    case REFERENCE = 'reference';

    public static function fromDoctrine(string $type, bool $hasEnum): self
    {
        if ($hasEnum) {
            return self::CODE;
        }

        return match ($type) {
            Types::INTEGER, Types::SMALLINT, Types::BIGINT => self::INTEGER,
            Types::DECIMAL, Types::FLOAT, Types::SMALLFLOAT => self::DECIMAL,
            Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE, Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE => self::DATETIME,
            Types::DATE_MUTABLE, Types::DATE_IMMUTABLE => self::DATE,
            Types::TIME_MUTABLE, Types::TIME_IMMUTABLE => self::TIME,
            Types::BOOLEAN => self::BOOLEAN,
            default => self::TEXT,
        };
    }

    public function labelKey(): string
    {
        return 'data_catalog.type.'.$this->value;
    }

    /**
     * Date-times and times are stored as local wall-clock time of the application time zone, without an
     * offset (`timestamp without time zone`, `time`); the catalog says so next to the type (#1499).
     */
    public function isLocalTime(): bool
    {
        return self::DATETIME === $this || self::TIME === $this;
    }

    /**
     * Tabler icon shown next to the type label (#1499); the label stays, so the type never depends on the icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::INTEGER => 'tabler:123',
            self::DECIMAL => 'tabler:decimal',
            self::DATETIME => 'tabler:calendar-time',
            self::DATE => 'tabler:calendar',
            self::TIME => 'tabler:clock',
            self::BOOLEAN => 'tabler:toggle-left',
            self::CODE => 'tabler:list',
            self::CODE_SET => 'tabler:list-check',
            self::TEXT => 'tabler:abc',
            self::REFERENCE => 'tabler:link',
        };
    }
}

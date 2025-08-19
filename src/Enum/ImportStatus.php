<?php

declare(strict_types=1);

namespace App\Enum;

enum ImportStatus: string
{
    case PENDING = 'Pending';
    case RUNNING = 'Running';
    case COMPLETED = 'Completed';
    case FAILED = 'Failed';
    case CANCELLED = 'Cancelled';
    case PARTIAL = 'Partial';

    public function getType(): string
    {
        return match ($this) {
            self::PENDING => self::PENDING->value,
            self::RUNNING => self::RUNNING->value,
            self::COMPLETED => self::COMPLETED->value,
            self::FAILED => self::FAILED->value,
            self::CANCELLED => self::CANCELLED->value,
            self::PARTIAL => self::PARTIAL->value,
        };
    }

    /**
     * @return string[]
     */
    public static function getValues(): array
    {
        return [
            self::PENDING->value,
            self::RUNNING->value,
            self::COMPLETED->value,
            self::FAILED->value,
            self::CANCELLED->value,
            self::PARTIAL->value,
        ];
    }
}

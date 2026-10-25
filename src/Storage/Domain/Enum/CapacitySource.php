<?php

declare(strict_types=1);

namespace App\Storage\Domain\Enum;

/**
 * The reference usage is compared with (ADR 017, "Capacity reference"). Never a hosting quota.
 */
enum CapacitySource: string
{
    case Budget = 'budget';
    case Filesystem = 'filesystem';
    case None = 'none';

    public function labelKey(): string
    {
        return 'storage.capacity_source.'.$this->value;
    }
}

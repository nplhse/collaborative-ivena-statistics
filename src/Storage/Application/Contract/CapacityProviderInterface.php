<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Application\Capacity\CapacityReference;
use App\Storage\Application\Capacity\FilesystemSpace;
use App\Storage\Domain\Enum\CapacitySource;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One source of a capacity reference (ADR 017, "Capacity reference"). A hosting quota can be added later
 * as another implementation.
 */
#[AutoconfigureTag(self::TAG)]
interface CapacityProviderInterface
{
    public const string TAG = 'app.storage.capacity_provider';

    public function source(): CapacitySource;

    /**
     * @param int|null $measuredUsedBytes files + database, null when unknown
     */
    public function provide(?int $measuredUsedBytes, ?FilesystemSpace $filesystem): ?CapacityReference;
}

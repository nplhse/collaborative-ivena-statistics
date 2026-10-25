<?php

declare(strict_types=1);

namespace App\Tests\Support\Storage;

use App\Storage\Application\Contract\StorageCapacityAlertStoreInterface;
use App\Storage\Domain\Enum\CapacityLevel;

final class InMemoryStorageCapacityAlertStore implements StorageCapacityAlertStoreInterface
{
    /** @var list<array{CapacityLevel, string, string}> level, changed on, recorded at */
    public array $recorded = [];

    public function __construct(
        public CapacityLevel $level = CapacityLevel::Normal,
    ) {
    }

    #[\Override]
    public function lastNotifiedLevel(): CapacityLevel
    {
        return $this->level;
    }

    #[\Override]
    public function recordNotified(CapacityLevel $level, \DateTimeImmutable $changedOn, \DateTimeImmutable $now): void
    {
        $this->level = $level;
        $this->recorded[] = [$level, $changedOn->format('Y-m-d'), $now->format(\DATE_ATOM)];
    }
}

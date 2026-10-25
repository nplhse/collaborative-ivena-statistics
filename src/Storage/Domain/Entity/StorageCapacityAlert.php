<?php

declare(strict_types=1);

namespace App\Storage\Domain\Entity;

use App\Storage\Domain\Enum\CapacityLevel;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The capacity level administrators were last told about (ADR 017, "Notifications"). A single row; without it the
 * last notified level is normal. Deduplication keys expire and cannot express "same level as yesterday".
 */
#[ORM\Entity]
#[ORM\Table(name: 'storage_capacity_alert')]
class StorageCapacityAlert
{
    public const int SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(length: 16, enumType: CapacityLevel::class)]
    private CapacityLevel $lastNotifiedLevel;

    /** Day of the measurement that changed the level. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $changedOn;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(CapacityLevel $level, \DateTimeImmutable $changedOn, \DateTimeImmutable $updatedAt)
    {
        $this->lastNotifiedLevel = $this->known($level);
        $this->changedOn = $changedOn->setTime(0, 0);
        $this->updatedAt = $updatedAt;
    }

    public function record(CapacityLevel $level, \DateTimeImmutable $changedOn, \DateTimeImmutable $updatedAt): void
    {
        $this->lastNotifiedLevel = $this->known($level);
        $this->changedOn = $changedOn->setTime(0, 0);
        $this->updatedAt = $updatedAt;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getLastNotifiedLevel(): CapacityLevel
    {
        return $this->lastNotifiedLevel;
    }

    public function getChangedOn(): \DateTimeImmutable
    {
        return $this->changedOn;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function known(CapacityLevel $level): CapacityLevel
    {
        if (!$level->isKnown()) {
            throw new \InvalidArgumentException('An unknown capacity level is never notified.');
        }

        return $level;
    }
}

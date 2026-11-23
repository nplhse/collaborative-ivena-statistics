<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

/**
 * Marks the one-time takeover of the legacy district geography as verified. Later deployments skip the takeover, so
 * later maintenance of districts cannot fail a deployment (ADR 041).
 */
#[ORM\Entity]
class DistrictLegacyTakeover
{
    public const int SINGLE_ROW_ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::SINGLE_ROW_ID;

    #[ORM\Column]
    private \DateTimeImmutable $completedAt;

    public function __construct(
        #[ORM\Column(length: 64)]
        private string $transferFileHash,
    ) {
        $this->completedAt = Clock::get()->now();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getTransferFileHash(): string
    {
        return $this->transferFileHash;
    }
}

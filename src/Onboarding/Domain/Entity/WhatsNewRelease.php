<?php

declare(strict_types=1);

namespace App\Onboarding\Domain\Entity;

use App\Onboarding\Infrastructure\Repository\WhatsNewReleaseRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * When a release was installed on this installation (#1485). The migration shipped with a release inserts its row,
 * so only accounts created before that moment see the release's "What's new for you" card.
 */
#[ORM\Entity(repositoryClass: WhatsNewReleaseRepository::class)]
#[ORM\Table(name: 'whats_new_release')]
class WhatsNewRelease
{
    #[ORM\Id]
    #[ORM\Column(length: 20)]
    private string $releaseKey;

    #[ORM\Column]
    private \DateTimeImmutable $installedAt;

    public function __construct(string $releaseKey, \DateTimeImmutable $installedAt)
    {
        $this->releaseKey = $releaseKey;
        $this->installedAt = $installedAt;
    }

    public function getReleaseKey(): string
    {
        return $this->releaseKey;
    }

    public function getInstalledAt(): \DateTimeImmutable
    {
        return $this->installedAt;
    }

    public function setInstalledAt(\DateTimeImmutable $installedAt): void
    {
        $this->installedAt = $installedAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Domain\Traits;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

trait HasPublicId
{
    #[ORM\Column(type: 'uuid', unique: true, nullable: true)]
    private ?Uuid $publicId = null;

    /**
     * @psalm-suppress PossiblyUnusedMethod Doctrine lifecycle callback
     */
    #[ORM\PrePersist]
    public function ensurePublicId(): void
    {
        $this->publicId ??= Uuid::v4();
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getPublicId(): ?Uuid
    {
        return $this->publicId;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function setPublicId(Uuid $publicId): static
    {
        $this->publicId = $publicId;

        return $this;
    }

    public function getPublicIdString(): string
    {
        if (!$this->publicId instanceof Uuid) {
            throw new \LogicException(sprintf('%s is missing publicId.', static::class));
        }

        return $this->publicId->toRfc4122();
    }
}

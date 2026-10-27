<?php

declare(strict_types=1);

namespace App\Shared\Domain\Traits;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Public id that every stored row has. Reference catalog packages identify rows by it (ADR 020).
 *
 * The id is created on persist unless one was set before (for example when a catalog
 * package is imported). An entity that was never stored has none, so nothing links to it.
 */
trait HasRequiredPublicId
{
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $publicId = null;

    /**
     * @psalm-suppress PossiblyUnusedMethod Doctrine lifecycle callback
     */
    #[ORM\PrePersist]
    public function ensurePublicId(): void
    {
        $this->publicId ??= Uuid::v4();
    }

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

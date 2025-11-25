<?php

namespace App\Shared\Domain\Traits;

use App\User\Domain\Entity\User;

trait Blamable
{
    protected ?User $createdBy = null;

    protected ?User $updatedBy = null;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function setCreatedBy(User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function setUpdatedBy(?User $updatedBy): self
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }
}

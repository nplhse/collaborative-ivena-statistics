<?php

declare(strict_types=1);

namespace App\Content\Application\Contract;

/** Removes reactions whose target no longer exists (#467). */
interface ReactionCleanerInterface
{
    /**
     * @return array<string, int> deleted reactions by target type
     */
    public function removeOrphans(): array;
}

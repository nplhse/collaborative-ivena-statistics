<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

interface ProjectionEarliestDateProviderInterface
{
    public function getEarliestCreatedAt(): ?\DateTimeImmutable;
}

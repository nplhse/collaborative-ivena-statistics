<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

interface ClosureRebuildRequestInterface
{
    public function requestAnalysis(?int $hospitalId): void;

    public function requestVolume(?int $hospitalId): void;
}

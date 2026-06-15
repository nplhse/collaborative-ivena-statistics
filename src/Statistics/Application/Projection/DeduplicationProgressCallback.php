<?php

declare(strict_types=1);

namespace App\Statistics\Application\Projection;

interface DeduplicationProgressCallback
{
    public function onProgress(string $phase, int $current, int $max, string $message): void;
}

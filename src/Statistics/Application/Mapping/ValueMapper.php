<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

interface ValueMapper
{
    public function label(?int $value): string;
}

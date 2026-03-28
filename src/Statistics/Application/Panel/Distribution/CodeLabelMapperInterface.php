<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

interface CodeLabelMapperInterface
{
    /**
     * Human-readable label for a stored projection/analytics code (nullable when SQL returns NULL).
     */
    public function label(?int $code): string;
}

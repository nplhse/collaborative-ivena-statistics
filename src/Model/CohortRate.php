<?php

declare(strict_types=1);

namespace App\Model;

/** @psalm-suppress PossiblyUnusedProperty */
final class CohortRate
{
    public function __construct(
        public ?float $mean,
        public ?float $sd,
        public ?float $var,
    ) {
    }
}

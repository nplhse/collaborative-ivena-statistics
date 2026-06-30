<?php

declare(strict_types=1);

namespace App\Install\Application\Environment;

final readonly class EnvironmentCheckItem
{
    public function __construct(
        public string $variable,
        public EnvironmentCheckStatus $status,
        public string $message,
    ) {
    }
}

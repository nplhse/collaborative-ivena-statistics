<?php

declare(strict_types=1);

namespace App\Install\Application\Environment;

enum EnvironmentCheckProfile: string
{
    case Prod = 'prod';
    case Beta = 'beta';
    case Dev = 'dev';

    public function isStrict(): bool
    {
        return self::Dev !== $this;
    }

    public function requiresSentry(): bool
    {
        return self::Beta === $this;
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Application\PublicId;

final class PublicIdBackfillExitCode
{
    public const int SUCCESS = 0;

    public const int MORE_WORK = 1;

    public const int CRITICAL = 2;

    private function __construct()
    {
    }
}

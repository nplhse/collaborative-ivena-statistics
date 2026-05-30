<?php

declare(strict_types=1);

namespace App\Import\Application;

final class ImportDispatchExitCode
{
    public const int SUCCESS = 0;

    public const int FAILURE = 1;

    public const int CRITICAL = 2;

    private function __construct()
    {
    }
}

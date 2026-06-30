<?php

declare(strict_types=1);

namespace App\Install\Application\Environment;

enum EnvironmentCheckStatus: string
{
    case Ok = 'OK';
    case Warn = 'WARN';
    case Fail = 'FAIL';
}

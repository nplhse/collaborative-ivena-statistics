<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

/**
 * What started a generation run.
 */
enum GenerationRunTrigger: string
{
    case Console = 'console';
    case Wizard = 'wizard';
}

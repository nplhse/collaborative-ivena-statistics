<?php

declare(strict_types=1);

namespace App\Shared\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class BackfillAuditDerivedColumnsInput
{
    #[Option(description: 'Persist intent and outcome (default: preview only)', name: 'apply')]
    public bool $apply = false;
}

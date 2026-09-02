<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class BackfillHospitalParticipatingSinceInput
{
    #[Option(description: 'Preview reconstructed dates without writing (default)', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Persist reconstructed participatingSince values', name: 'apply')]
    public bool $apply = false;
}

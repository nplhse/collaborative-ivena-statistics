<?php

declare(strict_types=1);

namespace App\User\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class BackfillUserActivityInput
{
    #[Option(description: 'Preview reconstructed activity without writing (default)', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Persist reconstructed activity rows', name: 'apply')]
    public bool $apply = false;
}

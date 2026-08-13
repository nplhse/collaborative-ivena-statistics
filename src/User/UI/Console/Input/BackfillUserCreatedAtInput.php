<?php

declare(strict_types=1);

namespace App\User\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class BackfillUserCreatedAtInput
{
    #[Option(description: 'Preview reconstructed dates without writing (default)', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Persist earlier reconstructed createdAt values', name: 'apply')]
    public bool $apply = false;
}

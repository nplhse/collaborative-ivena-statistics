<?php

declare(strict_types=1);

namespace App\User\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class NormalizeUsernamesInput
{
    #[Option(description: 'Preview username changes without writing (default)', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Persist normalized usernames', name: 'apply')]
    public bool $apply = false;
}

<?php

declare(strict_types=1);

namespace App\Content\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class BackfillPageTranslationsInput
{
    #[Option(description: 'Only report what would be created without writing changes', name: 'dry-run')]
    public bool $dryRun = false;
}

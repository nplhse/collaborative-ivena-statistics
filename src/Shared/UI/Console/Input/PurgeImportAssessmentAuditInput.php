<?php

declare(strict_types=1);

namespace App\Shared\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class PurgeImportAssessmentAuditInput
{
    #[Option(description: 'Preview matching audit entries without deleting (default)', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Delete matching import-generated Assessment create audit entries', name: 'execute')]
    public bool $execute = false;
}

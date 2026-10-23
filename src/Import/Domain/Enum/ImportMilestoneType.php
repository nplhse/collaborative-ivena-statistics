<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

enum ImportMilestoneType: string
{
    /** The user's first import that finished as completed or partial. */
    case FirstImport = 'first_import';
}

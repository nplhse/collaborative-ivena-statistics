<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogViolation;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists reference catalog package problems with their path in the file.
 */
final class ReferenceCatalogViolationTable
{
    /**
     * @param list<ReferenceCatalogViolation> $violations
     */
    public static function render(SymfonyStyle $io, array $violations): void
    {
        $io->table(
            ['Path', 'Problem', 'Value'],
            array_map(
                static fn (ReferenceCatalogViolation $violation): array => ['' === $violation->path ? '(file)' : $violation->path, $violation->message, $violation->invalidValue ?? ''],
                $violations,
            ),
        );
    }
}

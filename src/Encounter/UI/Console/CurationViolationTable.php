<?php

declare(strict_types=1);

namespace App\Encounter\UI\Console;

use App\Encounter\Application\Curation\Package\CurationViolation;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists problems of a curation package with their path in the file.
 */
final class CurationViolationTable
{
    /**
     * @param list<CurationViolation> $violations
     */
    public static function render(SymfonyStyle $io, array $violations): void
    {
        $io->table(
            ['Path', 'Problem', 'Value'],
            array_map(
                static fn (CurationViolation $violation): array => ['' === $violation->path ? '(file)' : $violation->path, $violation->message, $violation->invalidValue ?? ''],
                $violations,
            ),
        );
    }
}

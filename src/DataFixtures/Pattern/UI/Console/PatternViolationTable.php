<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\UI\Console;

use App\DataFixtures\Pattern\Package\PatternPackageViolation;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists pattern package problems with the table and key they belong to.
 */
final class PatternViolationTable
{
    /**
     * @param list<PatternPackageViolation> $violations
     */
    public static function render(SymfonyStyle $io, array $violations): void
    {
        $io->table(
            ['Path', 'Problem', 'Value'],
            array_map(
                static fn (PatternPackageViolation $violation): array => ['' === $violation->path ? '(file)' : $violation->path, $violation->message, $violation->invalidValue ?? ''],
                $violations,
            ),
        );
    }
}

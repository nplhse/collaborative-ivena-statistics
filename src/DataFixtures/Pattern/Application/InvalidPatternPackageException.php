<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\DataFixtures\Pattern\Package\PatternPackageViolation;

/**
 * The selected pattern package cannot be used; nothing has been generated.
 */
final class InvalidPatternPackageException extends \RuntimeException
{
    /**
     * @param list<PatternPackageViolation> $violations
     */
    public function __construct(string $path, array $violations)
    {
        parent::__construct(sprintf(
            "%s is not a valid pattern package (%d problem(s); app:patterns:validate shows them as a table):\n- %s",
            $path,
            \count($violations),
            implode("\n- ", array_map(
                static fn (PatternPackageViolation $violation): string => ('' === $violation->path ? '(file)' : $violation->path).': '.$violation->message,
                $violations,
            )),
        ));
    }
}

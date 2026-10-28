<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\UI\Console;

use Symfony\Component\Filesystem\Path;

/**
 * Where a pattern package file lives: an absolute path, a path relative to the project, or a bare
 * file name in `var/patterns/` (ADR 022).
 */
final class PatternPackagePath
{
    public const string DIRECTORY = 'var/patterns';

    public static function resolve(string $file, string $projectDir): string
    {
        if (Path::isAbsolute($file)) {
            return $file;
        }
        if (!str_contains($file, '/')) {
            return Path::join($projectDir, self::DIRECTORY, $file);
        }

        return Path::join($projectDir, $file);
    }
}

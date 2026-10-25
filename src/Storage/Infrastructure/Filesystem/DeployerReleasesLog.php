<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Filesystem;

/**
 * Reads release dates from Deployer's .dep/releases_log (one JSON object per line).
 * Unreadable lines are skipped; the log only supplies dates, the directories stay the truth.
 */
final readonly class DeployerReleasesLog
{
    /**
     * @return array<array-key, \DateTimeImmutable> release name => creation time (numeric names become integer keys)
     */
    public function read(string $deployPath): array
    {
        $file = $deployPath.'/.dep/releases_log';
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $lines = @file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $dates = [];
        foreach ($lines as $line) {
            try {
                $entry = json_decode($line, true, 8, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!\is_array($entry) || !\is_scalar($entry['release_name'] ?? null) || !\is_string($entry['created_at'] ?? null)) {
                continue;
            }

            try {
                $dates[(string) $entry['release_name']] = new \DateTimeImmutable($entry['created_at']);
            } catch (\Exception) {
                continue;
            }
        }

        return $dates;
    }
}

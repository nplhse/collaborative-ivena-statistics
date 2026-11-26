<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * The result of verifying one backup (ADR 038, "Verify").
 *
 * Problems make the backup unusable for a restore; warnings are worth a look but do not affect it.
 */
final readonly class BackupVerification
{
    /**
     * @param list<string> $problems
     * @param list<string> $warnings
     */
    public function __construct(
        public BackupManifest $manifest,
        public array $problems,
        public array $warnings,
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->problems;
    }
}

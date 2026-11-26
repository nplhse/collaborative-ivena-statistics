<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

/**
 * Where export runs write their files and keep the archive for the download period (ADR 045).
 */
interface ExportStorageInterface
{
    /** An empty working directory for one attempt of a run. */
    public function workDirectory(string $key): string;

    /**
     * Packs the CSV files and the extra text files (name => content) into a `.tar.gz` archive, removes the working
     * directory and returns the archive's file name.
     *
     * @param list<ExportFile>      $files
     * @param array<string, string> $extras
     */
    public function pack(string $key, string $folder, array $files, array $extras): string;

    /** Absolute path of an archive, or null when it is gone. */
    public function path(string $archiveFile): ?string;

    public function discard(string $key): void;

    public function remove(string $archiveFile): void;

    /**
     * Removes archives that no run refers to and working directories left by an interrupted run, both older than
     * $before. Returns how many were removed.
     *
     * @param list<string> $archivesInUse
     */
    public function removeOrphans(array $archivesInUse, \DateTimeImmutable $before): int;
}

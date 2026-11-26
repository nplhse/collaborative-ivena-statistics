<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * The machine-readable description of one backup, stored as manifest.json (ADR 038, "Manifest").
 *
 * It holds counts and checksums only, never records or personal data, and no absolute paths.
 */
final readonly class BackupManifest
{
    /**
     * @param array<string, ArchivedFiles>                     $files    per file component
     * @param array<string, array{bytes: int, sha256: string}> $payloads per relative payload path, sorted by path
     */
    public function __construct(
        public string $id,
        public string $type,
        public \DateTimeImmutable $createdAt,
        public string $appVersion,
        public string $environment,
        public DatabaseDump $database,
        public array $files,
        public array $payloads,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $components = [
            BackupComponent::Database->value => ['payloads' => [BackupFormat::DATABASE_DUMP]],
        ];
        foreach ($this->files as $name => $archived) {
            $component = BackupComponent::from($name);
            $components[$name] = [
                'files' => $archived->files,
                'bytes' => $archived->bytes,
                'skipped_links' => $archived->skippedLinks,
                'source_missing' => $archived->sourceMissing,
                'payloads' => $archived->hasArchive()
                    ? [BackupFormat::archivePath($component), BackupFormat::checksumListPath($component)]
                    : [],
            ];
        }

        return [
            'format' => BackupFormat::FORMAT,
            'format_version' => BackupFormat::VERSION,
            'id' => $this->id,
            'type' => $this->type,
            'created_at' => $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'application' => [
                'version' => $this->appVersion,
                'environment' => $this->environment,
            ],
            'database' => [
                'server_version' => $this->database->serverVersion,
                'dump_tool_version' => $this->database->dumpToolVersion,
                'latest_migration' => $this->database->latestMigration(),
                'executed_migrations' => $this->database->executedMigrations,
                'table_rows' => $this->database->tableRows,
            ],
            'components' => $components,
            'payloads' => $this->payloads,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
    }
}

<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * Reads manifest.json of a backup back into a BackupManifest (ADR 038, "Verify").
 *
 * Every field the format defines is checked for presence and type, so later steps (verify, restore) can rely on
 * it. A manifest of a newer format version is refused: its meaning is unknown to this code.
 */
final class ManifestReader
{
    /**
     * @throws BackupFailed when the manifest is missing, unreadable or not of a known format
     */
    public static function read(string $backupDirectory): BackupManifest
    {
        $file = $backupDirectory.'/'.BackupFormat::MANIFEST;
        if (!is_file($file)) {
            throw new BackupFailed(sprintf('%s has no %s.', $backupDirectory, BackupFormat::MANIFEST), 'Only complete backups have a manifest; an unfinished or legacy backup cannot be verified with this command.');
        }

        $json = @file_get_contents($file);
        if (false === $json) {
            throw new BackupFailed(sprintf('%s cannot be read.', BackupFormat::MANIFEST), 'Check the file permissions.');
        }

        try {
            $data = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BackupFailed(sprintf('%s is not valid JSON.', BackupFormat::MANIFEST), 'The manifest is damaged; use another backup.', previous: $exception);
        }

        return self::fromArray(\is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws BackupFailed
     */
    public static function fromArray(array $data): BackupManifest
    {
        if (BackupFormat::FORMAT !== ($data['format'] ?? null)) {
            throw new BackupFailed('The manifest does not describe an IVENA statistics backup.');
        }

        $version = self::int($data, 'format_version');
        if ($version > BackupFormat::VERSION) {
            throw new BackupFailed(sprintf('The backup has format version %d; this application reads up to version %d.', $version, BackupFormat::VERSION), 'Update the application to a release that knows this format.');
        }
        if ($version < 1) {
            throw new BackupFailed(sprintf('The manifest has the invalid format version %d.', $version));
        }

        $createdAt = \DateTimeImmutable::createFromFormat('!'.\DATE_ATOM, self::string($data, 'created_at'));
        if (false === $createdAt) {
            throw new BackupFailed('The manifest field "created_at" is not a date.');
        }

        $application = self::array($data, 'application');
        $database = self::array($data, 'database');

        $tableRows = [];
        foreach (self::array($database, 'table_rows', 'database.') as $table => $rows) {
            if (!\is_string($table) || !\is_int($rows) || $rows < 0) {
                throw new BackupFailed('The manifest field "database.table_rows" is invalid.');
            }
            $tableRows[$table] = $rows;
        }

        $migrations = self::array($database, 'executed_migrations', 'database.');
        if (!array_is_list($migrations) || [] !== array_filter($migrations, static fn (mixed $migration): bool => !\is_string($migration))) {
            throw new BackupFailed('The manifest field "database.executed_migrations" is invalid.');
        }

        /** @var list<string> $migrations */
        $dump = new DatabaseDump(
            self::string($database, 'server_version', 'database.'),
            self::string($database, 'dump_tool_version', 'database.'),
            $migrations,
            $tableRows,
        );

        return new BackupManifest(
            self::string($data, 'id'),
            self::string($data, 'type'),
            $createdAt,
            self::string($application, 'version', 'application.'),
            self::string($application, 'environment', 'application.'),
            $dump,
            self::files(self::array($data, 'components')),
            self::payloads(self::array($data, 'payloads')),
        );
    }

    /**
     * @param array<mixed> $components
     *
     * @return array<string, ArchivedFiles>
     */
    private static function files(array $components): array
    {
        if (!\array_key_exists(BackupComponent::Database->value, $components)) {
            throw new BackupFailed('The manifest lists no database component.');
        }

        $files = [];
        foreach (BackupComponent::fileComponents() as $component) {
            $name = $component->value;
            $entry = self::array($components, $name, 'components.');
            $prefix = 'components.'.$name.'.';

            $files[$name] = new ArchivedFiles(
                self::int($entry, 'files', $prefix),
                self::int($entry, 'bytes', $prefix),
                self::int($entry, 'skipped_links', $prefix),
                self::bool($entry, 'source_missing', $prefix),
            );
        }

        return $files;
    }

    /**
     * @param array<mixed> $payloads
     *
     * @return array<string, array{bytes: int, sha256: string}>
     */
    private static function payloads(array $payloads): array
    {
        $result = [];
        foreach ($payloads as $path => $payload) {
            if (!\is_string($path) || !\is_array($payload) || !\is_int($payload['bytes'] ?? null) || !\is_string($payload['sha256'] ?? null)
                || 1 !== preg_match('/^[0-9a-f]{64}$/', $payload['sha256'])) {
                throw new BackupFailed('The manifest field "payloads" is invalid.');
            }
            $result[$path] = ['bytes' => $payload['bytes'], 'sha256' => $payload['sha256']];
        }

        if (!\array_key_exists(BackupFormat::DATABASE_DUMP, $result)) {
            throw new BackupFailed(sprintf('The manifest lists no %s.', BackupFormat::DATABASE_DUMP));
        }

        return $result;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function array(array $data, string $key, string $prefix = ''): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            throw self::invalid($prefix.$key);
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key, string $prefix = ''): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw self::invalid($prefix.$key);
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function int(array $data, string $key, string $prefix = ''): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value) || $value < 0) {
            throw self::invalid($prefix.$key);
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function bool(array $data, string $key, string $prefix = ''): bool
    {
        $value = $data[$key] ?? null;
        if (!\is_bool($value)) {
            throw self::invalid($prefix.$key);
        }

        return $value;
    }

    private static function invalid(string $field): BackupFailed
    {
        return new BackupFailed(sprintf('The manifest field "%s" is missing or invalid.', $field), 'The manifest is damaged or was edited; use another backup.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Backup;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\BackupFormat;
use App\Storage\Application\Backup\ManifestReader;
use App\Tests\Support\Storage\BackupDirectoryBuilder;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManifestReaderTest extends TestCase
{
    private StorageFixtureDirectory $fixture;

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testReadsBackWhatTheManifestWrote(): void
    {
        $directory = BackupDirectoryBuilder::write($this->fixture->path);

        $manifest = ManifestReader::read($directory);

        self::assertSame(BackupDirectoryBuilder::NAME, $manifest->id);
        self::assertSame('full', $manifest->type);
        self::assertSame('2026-11-20T10:30:05+00:00', $manifest->createdAt->format(\DATE_ATOM));
        self::assertSame('v0.3.0-beta', $manifest->appVersion);
        self::assertSame(['allocation' => 4, 'hospital' => 2], $manifest->database->tableRows);
        self::assertSame('DoctrineMigrations\Version20261119220659', $manifest->database->latestMigration());
        self::assertSame(2, $manifest->files['media']->files);
        self::assertSame(10, $manifest->files['media']->bytes);
        self::assertSame(0, $manifest->files['imports']->files);
        self::assertSame(['database.dump', 'files/media.sha256', 'files/media.tar.gz'], array_keys($manifest->payloads));
        self::assertSame(file_get_contents($directory.'/manifest.json'), $manifest->toJson());
    }

    public function testRefusesANewerFormatVersion(): void
    {
        $directory = BackupDirectoryBuilder::write($this->fixture->path);
        BackupDirectoryBuilder::editManifest($directory, static fn (array $data): array => ['format_version' => BackupFormat::VERSION + 1] + $data);

        $failure = $this->failure($directory);

        self::assertSame(sprintf('The backup has format version %d; this application reads up to version %d.', BackupFormat::VERSION + 1, BackupFormat::VERSION), $failure->getMessage());
        self::assertStringContainsString('Update the application', (string) $failure->hint);
    }

    public function testRefusesAnotherFormat(): void
    {
        $directory = BackupDirectoryBuilder::write($this->fixture->path);
        BackupDirectoryBuilder::editManifest($directory, static fn (array $data): array => ['format' => 'other'] + $data);

        self::assertSame('The manifest does not describe an IVENA statistics backup.', $this->failure($directory)->getMessage());
    }

    /**
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function brokenFields(): iterable
    {
        yield 'missing id' => [static function (array $data): array {
            unset($data['id']);

            return $data;
        }, 'The manifest field "id" is missing or invalid.'];
        yield 'negative rows' => [static function (array $data): array {
            $data['database'] = ['table_rows' => ['allocation' => -1]] + (array) $data['database'];

            return $data;
        }, 'The manifest field "database.table_rows" is invalid.'];
        yield 'component without bytes' => [static function (array $data): array {
            $data['components'] = ['media' => ['files' => 2]] + (array) $data['components'];

            return $data;
        }, 'The manifest field "components.media.bytes" is missing or invalid.'];
        yield 'checksum not hex' => [static function (array $data): array {
            $data['payloads'] = ['database.dump' => ['bytes' => 4, 'sha256' => 'abc']];

            return $data;
        }, 'The manifest field "payloads" is invalid.'];
        yield 'no dump payload' => [static function (array $data): array {
            $data['payloads'] = [];

            return $data;
        }, 'The manifest lists no database.dump.'];
        yield 'date' => [static fn (array $data): array => ['created_at' => 'yesterday'] + $data, 'The manifest field "created_at" is not a date.'];
        yield 'format version 0' => [static fn (array $data): array => ['format_version' => 0] + $data, 'The manifest has the invalid format version 0.'];
        yield 'migrations not a list' => [static function (array $data): array {
            $data['database'] = ['executed_migrations' => ['a' => 1]] + (array) $data['database'];

            return $data;
        }, 'The manifest field "database.executed_migrations" is invalid.'];
        yield 'no database component' => [static function (array $data): array {
            $components = (array) $data['components'];
            unset($components['database']);
            $data['components'] = $components;

            return $data;
        }, 'The manifest lists no database component.'];
        yield 'application version not a string' => [static fn (array $data): array => ['application' => ['version' => 3, 'environment' => 'prod']] + $data, 'The manifest field "application.version" is missing or invalid.'];
        yield 'source missing not a bool' => [static function (array $data): array {
            $data['components'] = ['geo' => ['files' => 0, 'bytes' => 0, 'skipped_links' => 0, 'source_missing' => 'no']] + (array) $data['components'];

            return $data;
        }, 'The manifest field "components.geo.source_missing" is missing or invalid.'];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $edit
     */
    #[DataProvider('brokenFields')]
    public function testNamesTheBrokenField(callable $edit, string $message): void
    {
        $directory = BackupDirectoryBuilder::write($this->fixture->path);
        BackupDirectoryBuilder::editManifest($directory, $edit);

        self::assertSame($message, $this->failure($directory)->getMessage());
    }

    public function testExplainsAMissingOrDamagedManifest(): void
    {
        $directory = $this->fixture->directory('empty');
        self::assertStringContainsString('has no manifest.json', $this->failure($directory)->getMessage());

        file_put_contents($directory.'/manifest.json', '{');
        self::assertSame('manifest.json is not valid JSON.', $this->failure($directory)->getMessage());
    }

    private function failure(string $directory): BackupFailed
    {
        try {
            ManifestReader::read($directory);
        } catch (BackupFailed $failure) {
            return $failure;
        }

        self::fail('The manifest should be refused.');
    }
}

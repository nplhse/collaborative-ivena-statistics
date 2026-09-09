<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Repair;

use App\Import\Application\Repair\ImportSourceFileGate;
use App\Import\Application\Repair\QuoteBrokenImportDiscoveryService;
use App\Import\Application\Service\ImportFileStorage;
use App\Import\Domain\Enum\ImportSourceFileStatus;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class QuoteBrokenImportDiscoveryServiceTest extends TestCase
{
    private string $projectDir;
    private Filesystem $filesystem;
    private ImportSourceFileGate $gate;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/import-discovery-'.bin2hex(random_bytes(4));
        $importsBaseDir = Path::join($this->projectDir, 'var', 'imports');
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($importsBaseDir, 0775);
        $this->gate = new ImportSourceFileGate(new ImportFileStorage(
            $this->projectDir,
            $importsBaseDir,
            $this->filesystem,
            new NullLogger(),
        ));
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->projectDir)) {
            $this->filesystem->remove($this->projectDir);
        }

        parent::tearDown();
    }

    public function testDiscoverWithoutSinceOmitsCreatedAtFilter(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => !str_contains($sql, ':since')),
                [],
                [],
            )
            ->willReturn([]);

        $result = new QuoteBrokenImportDiscoveryService($connection, $this->gate, new NullLogger())->discover(null, null);

        self::assertSame([], $result->ready);
        self::assertSame([], $result->skipped);
    }

    public function testDiscoverLimitsToOnlyImportId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, ':onlyImportId')),
                self::callback(static fn (array $params): bool => 42 === $params['onlyImportId']),
                self::arrayHasKey('onlyImportId'),
            )
            ->willReturn([]);

        new QuoteBrokenImportDiscoveryService($connection, $this->gate, new NullLogger())
            ->discover(new \DateTimeImmutable('2025-05-01'), 42);
    }

    public function testNonStringFilePathIsTreatedAsEmpty(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([[
            'import_id' => 9,
            'import_name' => null,
            'file_path' => 15,
            'hospital_name' => null,
            'reject_count' => 2,
        ]]);

        $result = new QuoteBrokenImportDiscoveryService($connection, $this->gate, new NullLogger())->discover();

        self::assertSame([], $result->ready);
        self::assertCount(1, $result->skipped);
        self::assertNull($result->skipped[0]->filePath);
        self::assertNull($result->skipped[0]->importName);
        self::assertNull($result->skipped[0]->hospitalName);
        self::assertSame(ImportSourceFileStatus::EmptyPath, $result->skipped[0]->sourceStatus);
    }
}

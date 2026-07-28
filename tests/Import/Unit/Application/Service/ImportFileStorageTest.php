<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\Exception\ImportFilePathOutsideBaseException;
use App\Import\Application\Service\ImportFileStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ImportFileStorageTest extends TestCase
{
    private string $projectDir;
    private string $importsBaseDir;
    private Filesystem $filesystem;
    /** @var MockObject&LoggerInterface */
    private MockObject $logger;
    private ImportFileStorage $storage;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/import-storage-'.bin2hex(random_bytes(4));
        $this->importsBaseDir = Path::join($this->projectDir, 'var', 'imports');
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->importsBaseDir, 0775);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->storage = new ImportFileStorage(
            $this->projectDir,
            $this->importsBaseDir,
            $this->filesystem,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->projectDir)) {
            $this->filesystem->remove($this->projectDir);
        }

        parent::tearDown();
    }

    public function testResolveJoinsRelativePathUnderImportsBase(): void
    {
        $base = realpath($this->importsBaseDir);
        self::assertNotFalse($base);

        self::assertSame(
            Path::canonicalize(Path::join($base, 'file.csv')),
            $this->storage->resolve('var/imports/file.csv'),
        );
    }

    public function testResolveAcceptsAbsolutePathInsideBase(): void
    {
        $abs = Path::join($this->importsBaseDir, 'absolute.csv');
        file_put_contents($abs, 'data');

        self::assertSame(realpath($abs), $this->storage->resolve($abs));
    }

    public function testResolveRejectsAbsolutePathOutsideBase(): void
    {
        $this->expectException(ImportFilePathOutsideBaseException::class);

        $this->storage->resolve('/etc/passwd');
    }

    public function testResolveRejectsRelativeEscapeViaDotDot(): void
    {
        $this->expectException(ImportFilePathOutsideBaseException::class);

        $this->storage->resolve('var/imports/../../.env');
    }

    public function testResolveRejectsEmptyPath(): void
    {
        $this->expectException(ImportFilePathOutsideBaseException::class);

        $this->storage->resolve('');
    }

    public function testResolveRejectsNullByte(): void
    {
        $this->expectException(ImportFilePathOutsideBaseException::class);

        $this->storage->resolve("var/imports/file.csv\0.txt");
    }

    public function testToRelativeReturnsForwardSlashes(): void
    {
        $abs = Path::join($this->projectDir, 'var', 'imports', 'file.csv');
        self::assertSame('var/imports/file.csv', $this->storage->toRelative($abs));
    }

    public function testDeleteRemovesExistingFileAndLogsSuccess(): void
    {
        $rel = 'var/imports/delete-me.csv';
        $abs = Path::join($this->projectDir, $rel);
        $this->filesystem->mkdir(\dirname($abs));
        file_put_contents($abs, 'data');
        $resolved = realpath($abs);
        self::assertNotFalse($resolved);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('import.source_file.deleted', $this->callback(static fn (array $context): bool => 42 === $context['import_id'] && $resolved === $context['path']));

        $this->storage->delete($rel, 'import.source_file.deleted', 42);

        self::assertFileDoesNotExist($abs);
    }

    public function testDeleteIgnoresMissingFile(): void
    {
        $this->logger->expects($this->never())->method('info');
        $this->logger->expects($this->never())->method('warning');

        $this->storage->delete('var/imports/missing.csv', 'import.source_file.deleted', 1);
    }

    public function testDeleteIgnoresEmptyPath(): void
    {
        $this->logger->expects($this->never())->method('info');
        $this->logger->expects($this->never())->method('warning');

        $this->storage->delete(null, 'import.source_file.deleted', 1);
        $this->storage->delete('', 'import.source_file.deleted', 1);
    }

    public function testDeleteIgnoresPathOutsideBase(): void
    {
        $outside = Path::join($this->projectDir, 'outside.csv');
        file_put_contents($outside, 'secret');

        $this->logger->expects($this->never())->method('info');
        $this->logger->expects($this->never())->method('warning');

        $this->storage->delete($outside, 'import.source_file.deleted', 1);

        self::assertFileExists($outside);
    }

    public function testDeleteLogsWarningWhenRemovalFails(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);
        $filesystem
            ->method('remove')
            ->willThrowException(new IOException('permission denied'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('warning')
            ->with('import.file.delete_failed', $this->callback(static fn (array $context): bool => 7 === $context['import_id']
                && 'import.reject_file.deleted' === $context['event']
                && 'permission denied' === $context['error']));

        $storage = new ImportFileStorage($this->projectDir, $this->importsBaseDir, $filesystem, $logger);
        $storage->delete('var/imports/locked.csv', 'import.reject_file.deleted', 7);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Import\Application\Service\FileUploader;
use App\Import\Application\Service\ImportFileStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FileUploaderTest extends TestCase
{
    private string $projectDir;
    private string $baseDir;
    private Filesystem $filesystem;
    /** @var MockObject&LoggerInterface */
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/proj_'.bin2hex(random_bytes(4));
        @mkdir($this->projectDir, 0775, true);

        $this->baseDir = Path::join($this->projectDir, 'var', 'imports');

        $this->filesystem = new Filesystem();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->projectDir);
        }

        parent::tearDown();
    }

    public function testUploadHappyPathCreatesYearMonthAndMovesFileAndLogsInfo(): void
    {
        // Arrange
        $tmp = tempnam(sys_get_temp_dir(), 'up_');
        file_put_contents($tmp, 'csv,content');

        $uploaded = new UploadedFile(
            $tmp,
            'data.csv',
            'text/csv',
            null,
            true
        );

        $this->logger->expects($this->once())->method('info');

        $uploader = new FileUploader(
            $this->baseDir,
            $this->createFileStorage(),
            $this->logger,
            $this->filesystem,
        );

        // Act
        $returnedRel = $uploader->upload($uploaded);

        // Assert
        self::assertIsString($returnedRel);

        $absTarget = Path::join($this->projectDir, $returnedRel);
        self::assertFileExists($absTarget);

        $parts = explode('/', str_replace('\\', '/', $returnedRel));
        self::assertCount(5, $parts);
        self::assertSame('var', $parts[0]);
        self::assertSame('imports', $parts[1]);
        self::assertMatchesRegularExpression('/^\d{4}$/', $parts[2]);
        self::assertMatchesRegularExpression('/^\d{2}$/', $parts[3]);
        self::assertStringEndsWith('.csv', $parts[4]);
    }

    public function testUploadRejectsUnsupportedFileWhenNoExtensionAvailable(): void
    {
        // Arrange
        $this->logger->expects($this->never())->method('info');

        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientMimeType')->willReturn('application/octet-stream');
        $file->method('getClientOriginalName')->willReturn('noext');
        $file->method('guessExtension')->willReturn(null);
        $file->method('getClientOriginalExtension')->willReturn('');
        $file->expects($this->never())->method('move');

        $uploader = new FileUploader(
            $this->baseDir,
            $this->createFileStorage(),
            $this->logger,
            $this->filesystem,
        );

        // Act + Assert
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('Unsupported import file type. Allowed extensions: csv, txt.');

        $uploader->upload($file);
    }

    public function testUploadRejectsXlsxExtension(): void
    {
        $this->logger->expects($this->never())->method('info');

        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientOriginalName')->willReturn('report.xlsx');
        $file->expects($this->never())->method('move');

        $uploader = new FileUploader(
            $this->baseDir,
            $this->createFileStorage(),
            $this->logger,
            $this->filesystem,
        );

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('Excel files (.xls, .xlsx) are not supported');

        $uploader->upload($file);
    }

    public function testUploadRejectsXlsExtension(): void
    {
        $this->logger->expects($this->never())->method('info');

        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientOriginalName')->willReturn('report.xls');
        $file->expects($this->never())->method('move');

        $uploader = new FileUploader(
            $this->baseDir,
            $this->createFileStorage(),
            $this->logger,
            $this->filesystem,
        );

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('Excel files (.xls, .xlsx) are not supported');

        $uploader->upload($file);
    }

    /**
     * @param list<string> $allowedSuffixes
     */
    #[DataProvider('mimeTypeExtensionProvider')]
    public function testUploadResolvesExtensionFromMimeType(string $mime, array $allowedSuffixes): void
    {
        $this->logger->expects($this->once())->method('info');

        $uploader = new FileUploader(
            $this->baseDir,
            $this->createFileStorage(),
            $this->logger,
            $this->filesystem,
        );

        $returnedRel = $uploader->upload($this->createMockUploadedFile(
            originalName: 'upload',
            mimeType: $mime,
            guessedExtension: null,
        ));

        self::assertTrue(
            array_any($allowedSuffixes, static fn (string $suffix): bool => str_ends_with($returnedRel, $suffix)),
            sprintf('Expected path ending with one of [%s], got %s', implode(', ', $allowedSuffixes), $returnedRel),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function mimeTypeExtensionProvider(): iterable
    {
        yield 'plain text mime' => ['text/plain', ['.csv']];
        yield 'csv mime' => ['text/csv', ['.csv']];
        yield 'windows csv mime' => ['application/vnd.ms-excel', ['.csv']];
    }

    public function testUploadUsesGuessedExtensionWhenMimeTypeIsUnknown(): void
    {
        $this->logger->expects($this->once())->method('info');

        $uploader = new FileUploader(
            $this->baseDir,
            $this->createFileStorage(),
            $this->logger,
            $this->filesystem,
        );

        $returnedRel = $uploader->upload($this->createMockUploadedFile(
            originalName: 'upload',
            mimeType: 'application/octet-stream',
            guessedExtension: 'txt',
        ));

        self::assertStringEndsWith('.txt', $returnedRel);
    }

    public function testUploadIgnoresDisallowedOriginalExtensionAndUsesMimeType(): void
    {
        $this->logger->expects($this->once())->method('info');

        $uploader = new FileUploader(
            $this->baseDir,
            $this->createFileStorage(),
            $this->logger,
            $this->filesystem,
        );

        $returnedRel = $uploader->upload($this->createMockUploadedFile(
            originalName: 'payload.php',
            mimeType: 'text/csv',
            guessedExtension: null,
        ));

        self::assertStringEndsWith('.csv', $returnedRel);
    }

    public function testUploadRejectsWhenGuessedExtensionIsExcel(): void
    {
        $this->logger->expects($this->never())->method('info');

        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getClientMimeType')->willReturn('application/octet-stream');
        $file->method('getClientOriginalName')->willReturn('upload');
        $file->method('guessExtension')->willReturn('xlsx');
        $file->expects($this->never())->method('move');

        $uploader = new FileUploader(
            $this->baseDir,
            $this->createFileStorage(),
            $this->logger,
            $this->filesystem,
        );

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('Excel files (.xls, .xlsx) are not supported');

        $uploader->upload($file);
    }

    public function testUploadLogsErrorAndRethrowsOnMoveFailure(): void
    {
        // Arrange
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getSize')->willReturn(123);
        $file->method('getClientMimeType')->willReturn('text/csv');
        $file->method('getClientOriginalName')->willReturn('bad.csv');
        $file->method('guessExtension')->willReturn('csv');

        $file->expects($this->once())
            ->method('move')
            ->willThrowException(new FileException('boom'));

        $this->logger
            ->expects($this->once())
            ->method('error');

        $uploader = new FileUploader(
            $this->baseDir,
            $this->createFileStorage(),
            $this->logger,
            $this->filesystem,
        );

        // Act + Assert
        $this->expectException(FileException::class);
        $uploader->upload($file);
    }

    private function createFileStorage(): ImportFileStorage
    {
        return new ImportFileStorage($this->projectDir, $this->filesystem, $this->logger);
    }

    /**
     * @return MockObject&UploadedFile
     */
    private function createMockUploadedFile(
        string $originalName,
        string $mimeType,
        ?string $guessedExtension,
    ): MockObject {
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getSize')->willReturn(12);
        $file->method('getClientMimeType')->willReturn($mimeType);
        $file->method('getClientOriginalName')->willReturn($originalName);
        $file->method('guessExtension')->willReturn($guessedExtension);
        $file->method('getClientOriginalExtension')->willReturn(pathinfo($originalName, PATHINFO_EXTENSION));
        $file->expects($this->once())
            ->method('move')
            ->willReturnCallback(function (string $dir, ?string $name = null): \Symfony\Component\HttpFoundation\File\File {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $path = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
                @touch($path);

                return new \Symfony\Component\HttpFoundation\File\File($path);
            });

        return $file;
    }
}

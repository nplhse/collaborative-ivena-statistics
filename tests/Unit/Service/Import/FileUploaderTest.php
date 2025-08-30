<?php

namespace App\Tests\Unit\Service\Import;

use App\Service\Import\FileUploader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FileUploaderTest extends TestCase
{
    private string $projectDir;
    private string $baseDir;
    private Filesystem $filesystem;
    /** @var \PHPUnit\Framework\MockObject\MockObject&LoggerInterface */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        // eigenes "Projektverzeichnis" im /tmp
        $this->projectDir = sys_get_temp_dir().'/proj_'.bin2hex(random_bytes(4));
        @mkdir($this->projectDir, 0775, true);

        // Basis-Upload-Verzeichnis relativ zum Projekt
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

        $expectedExt = $uploaded->guessExtension() ?: $uploaded->getClientOriginalExtension() ?: 'bin';

        $this->logger->expects($this->once())->method('info');

        // ACHTUNG: Reihenfolge ggf. an deinen Konstruktor anpassen
        $uploader = new FileUploader(
            $this->projectDir,
            $this->baseDir,
            $this->filesystem,
            $this->logger
        );

        // Act
        $returnedRel = $uploader->upload($uploaded); // jetzt RELATIVER Pfad

        // Assert (relativer Pfad + Datei existiert am absoluten Ort)
        self::assertIsString($returnedRel);
        // sollte mit "var/imports/" beginnen
        self::assertStringStartsWith('var/imports/', str_replace('\\', '/', $returnedRel));

        $absTarget = Path::join($this->projectDir, $returnedRel);
        self::assertFileExists($absTarget);

        // Struktur: var/imports/YYYY/MM/filename.ext
        $parts = explode('/', str_replace('\\', '/', $returnedRel));
        // ['var','imports','YYYY','MM','filename.ext']
        self::assertCount(5, $parts);
        self::assertSame('var', $parts[0]);
        self::assertSame('imports', $parts[1]);
        self::assertMatchesRegularExpression('/^\d{4}$/', $parts[2]);
        self::assertMatchesRegularExpression('/^\d{2}$/', $parts[3]);
        self::assertStringEndsWith('.'.$expectedExt, $parts[4]);
    }

    public function testUploadUsesBinFallbackWhenNoExtensionAvailable(): void
    {
        // Arrange
        $this->logger->expects($this->once())->method('info');

        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getSize')->willReturn(7);
        $file->method('getClientMimeType')->willReturn('application/octet-stream');
        $file->method('getClientOriginalName')->willReturn('noext');
        $file->method('guessExtension')->willReturn(null);
        $file->method('getClientOriginalExtension')->willReturn('');

        $file->expects($this->once())
            ->method('move')
            ->willReturnCallback(function (string $dir, ?string $name = null): File {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $path = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
                @touch($path);

                return new File($path);
            });

        $uploader = new FileUploader(
            $this->projectDir,
            $this->baseDir,
            $this->filesystem,
            $this->logger
        );

        // Act
        $returnedRel = $uploader->upload($file);

        // Assert: relative Rückgabe + .bin-Endung + Existenz
        self::assertIsString($returnedRel);
        self::assertStringEndsWith('.bin', $returnedRel);

        $absTarget = Path::join($this->projectDir, $returnedRel);
        self::assertFileExists($absTarget);
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
            $this->projectDir,
            $this->baseDir,
            $this->filesystem,
            $this->logger
        );

        // Act + Assert
        $this->expectException(FileException::class);
        $uploader->upload($file);
    }
}

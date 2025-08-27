<?php

namespace App\Tests\Unit\Service\Import;

use App\Service\Import\FileUploader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FileUploaderTest extends TestCase
{
    private string $baseDir;
    /** @var \PHPUnit\Framework\MockObject\MockObject&LoggerInterface */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir().'/uploads_'.bin2hex(random_bytes(4));
        @mkdir($this->baseDir, 0775, true);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->baseDir);
        }
        parent::tearDown();
    }

    public function testUploadHappyPathCreatesYearMonthAndMovesFileAndLogsInfo(): void
    {
        // Arrange
        $tmp = tempnam(sys_get_temp_dir(), 'up_');
        file_put_contents($tmp, 'csv,content');

        $file = new UploadedFile(
            $tmp,
            'data.csv',
            'text/csv',
            null,
            true
        );

        // Erwartete Extension VOR dem Upload bestimmen
        $expectedExt = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';

        $this->logger->expects($this->once())->method('info');
        $uploader = new FileUploader($this->baseDir, $this->logger);

        // Act
        $targetAbs = $uploader->upload($file);

        // Assert
        self::assertFileExists($targetAbs);
        $rel = str_replace(rtrim($this->baseDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, '', $targetAbs);
        $parts = explode(DIRECTORY_SEPARATOR, $rel);
        self::assertCount(3, $parts);
        self::assertMatchesRegularExpression('/^\d{4}$/', $parts[0]);
        self::assertMatchesRegularExpression('/^\d{2}$/', $parts[1]);
        self::assertStringEndsWith('.'.$expectedExt, $parts[2]);
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

        $uploader = new FileUploader($this->baseDir, $this->logger);

        // Act
        $targetAbs = $uploader->upload($file);

        // Assert
        self::assertFileExists($targetAbs);
        self::assertStringEndsWith('.bin', $targetAbs);
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

        $uploader = new FileUploader($this->baseDir, $this->logger);

        // Act + Assert
        $this->expectException(FileException::class);
        $uploader->upload($file);
    }
}

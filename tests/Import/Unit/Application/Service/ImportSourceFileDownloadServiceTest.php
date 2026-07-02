<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\Exception\ImportSourceFileNotFoundException;
use App\Import\Application\Service\ImportFileStorage;
use App\Import\Application\Service\ImportSourceFileDownloadService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ImportSourceFileDownloadServiceTest extends TestCase
{
    private string $projectDir;

    private ImportSourceFileDownloadService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/import-download-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->projectDir);
        $this->service = new ImportSourceFileDownloadService(new ImportFileStorage($this->projectDir, new Filesystem(), new \Psr\Log\NullLogger()));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir);
    }

    public function testCreateDownloadResponseUsesImportNameAndExtension(): void
    {
        $relativePath = 'var/imports/test.csv';
        $absolutePath = Path::join($this->projectDir, $relativePath);
        new Filesystem()->dumpFile($absolutePath, "a;b;c\n");

        $import = $this->createImport($relativePath, 'My Import File', 'csv', 'text/csv');

        $response = $this->service->createDownloadResponse($import);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('My Import File.csv', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('text/csv', $response->headers->get('Content-Type'));
        self::assertSame($absolutePath, $response->getFile()->getPathname());
    }

    public function testCreateDownloadResponseThrowsWhenFileMissingOnDisk(): void
    {
        $import = $this->createImport('var/imports/missing.csv', 'Missing', 'csv', 'text/csv');

        $this->expectException(ImportSourceFileNotFoundException::class);

        $this->service->createDownloadResponse($import);
    }

    public function testCreateDownloadResponseThrowsWhenStoredPathEmpty(): void
    {
        $import = $this->createImport('', 'Empty', 'csv', 'text/csv');

        $this->expectException(ImportSourceFileNotFoundException::class);

        $this->service->createDownloadResponse($import);
    }

    public function testCreateDownloadResponseFallsBackToStoredBasenameWhenImportNameEmpty(): void
    {
        $relativePath = 'var/imports/fallback-name.csv';
        $absolutePath = Path::join($this->projectDir, $relativePath);
        new Filesystem()->dumpFile($absolutePath, "a\n");

        $import = $this->createImport($relativePath, '', 'csv', 'text/csv');

        $response = $this->service->createDownloadResponse($import);

        self::assertStringContainsString('fallback-name.csv', (string) $response->headers->get('Content-Disposition'));
    }

    private function createImport(string $filePath, string $name, string $extension, string $mimeType): Import
    {
        $import = new Import()
            ->setName($name)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($filePath)
            ->setFileExtension($extension)
            ->setFileMimeType($mimeType)
            ->setFileSize(10)
            ->setFileChecksum('checksum')
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(1);

        $idProperty = new \ReflectionProperty(Import::class, 'id');
        $idProperty->setValue($import, 42);

        return $import;
    }
}

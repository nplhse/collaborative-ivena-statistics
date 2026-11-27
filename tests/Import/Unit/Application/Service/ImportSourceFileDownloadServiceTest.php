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
        $importsBaseDir = Path::join($this->projectDir, 'var', 'imports');
        new Filesystem()->mkdir($importsBaseDir);
        $this->service = new ImportSourceFileDownloadService(new ImportFileStorage(
            $this->projectDir,
            $importsBaseDir,
            new Filesystem(),
            new \Psr\Log\NullLogger(),
        ));
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
        self::assertSame(realpath($absolutePath), $response->getFile()->getPathname());
    }

    /** #1591: synthetic imports stored the extension as ".csv"; the name must not end in "..csv". */
    public function testCreateDownloadResponseIgnoresALeadingDotOfTheStoredExtension(): void
    {
        $relativePath = 'var/imports/synthetic.csv';
        new Filesystem()->dumpFile(Path::join($this->projectDir, $relativePath), "a;b;c\n");

        $import = $this->createImport($relativePath, 'IVENA allocations 2026-10', '.csv', 'text/csv');

        $response = $this->service->createDownloadResponse($import);

        self::assertStringContainsString('IVENA allocations 2026-10.csv', (string) $response->headers->get('Content-Disposition'));
        self::assertStringNotContainsString('..csv', (string) $response->headers->get('Content-Disposition'));
    }

    public function testCreateDownloadResponseThrowsWhenPathOutsideImportsBase(): void
    {
        $import = $this->createImport('/etc/passwd', 'Escape', 'csv', 'text/csv');

        $this->expectException(ImportSourceFileNotFoundException::class);

        $this->service->createDownloadResponse($import);
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

    public function testCreateDownloadResponseSendsUnknownTypesAsBinaryWithoutSniffing(): void
    {
        $relativePath = 'var/imports/page.csv';
        new Filesystem()->dumpFile(Path::join($this->projectDir, $relativePath), "a;b\n");

        $response = $this->service->createDownloadResponse($this->createImport($relativePath, 'Upload', 'csv', 'text/html'));

        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}

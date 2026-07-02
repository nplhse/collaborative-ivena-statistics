<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service;

use App\Import\Application\Service\ImportUploadGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImportUploadGuardTest extends TestCase
{
    private ImportUploadGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new ImportUploadGuard();
    }

    #[DataProvider('rejectedFileProvider')]
    public function testResolveRejectionMessageKeyForDisallowedFiles(string $originalName, ?string $expectedKey): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'import_guard_');
        file_put_contents($tmp, 'dummy');

        $file = new UploadedFile(
            $tmp,
            $originalName,
            'application/octet-stream',
            null,
            true,
        );

        self::assertSame($expectedKey, $this->guard->resolveRejectionMessageKey($file));
    }

    public function testAcceptsPlainCsvFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'import_guard_');
        file_put_contents($tmp, "a;b\n1;2");

        $file = new UploadedFile(
            $tmp,
            'allocations.csv',
            'text/plain',
            null,
            true,
        );

        self::assertNull($this->guard->resolveRejectionMessageKey($file));
    }

    public function testRejectsNonTextContentForCsvExtension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'import_guard_');
        file_put_contents($tmp, base64_decode('UEsDBBQAAAAIAAAAIQAAAAAAABAAAAAAdGVzdC1kYXRh'));

        $file = new UploadedFile(
            $tmp,
            'report.csv',
            'application/octet-stream',
            null,
            true,
        );

        self::assertSame('validation.import.file_mime_types', $this->guard->resolveRejectionMessageKey($file));
    }

    public function testRejectsUnsupportedMimeForTxtExtension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'import_guard_');
        file_put_contents($tmp, '%PDF-1.4');

        $file = new UploadedFile(
            $tmp,
            'notes.txt',
            'application/pdf',
            null,
            true,
        );

        self::assertSame('validation.import.file_mime_types', $this->guard->resolveRejectionMessageKey($file));
    }

    public function testReturnsNullForInvalidUpload(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'import_guard_');
        file_put_contents($tmp, 'broken');

        $file = new UploadedFile(
            $tmp,
            'broken.csv',
            'text/csv',
            \UPLOAD_ERR_CANT_WRITE,
            true,
        );

        self::assertNull($this->guard->resolveRejectionMessageKey($file));
    }

    public function testRejectsSpreadsheetMimeForCsvExtension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'import_guard_');
        file_put_contents($tmp, 'spreadsheet-content');

        $file = new class($tmp, 'report.csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true) extends UploadedFile {
            public function getMimeType(): string
            {
                return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }
        };

        self::assertSame('validation.import.excel_rejected', $this->guard->resolveRejectionMessageKey($file));
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string}>
     */
    public static function rejectedFileProvider(): iterable
    {
        yield 'xlsx extension' => ['report.xlsx', 'validation.import.excel_rejected'];
        yield 'xls extension' => ['report.xls', 'validation.import.excel_rejected'];
        yield 'unsupported extension' => ['report.pdf', 'validation.import.file_extensions'];
    }
}

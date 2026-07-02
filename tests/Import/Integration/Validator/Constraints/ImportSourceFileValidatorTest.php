<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Validator\Constraints;

use App\Import\Application\Service\ImportUploadGuard;
use App\Import\Domain\Validation\Constraints\ImportSourceFile;
use App\Import\Domain\Validation\Constraints\ImportSourceFileValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<ImportSourceFileValidator>
 */
final class ImportSourceFileValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ImportSourceFileValidator
    {
        return new ImportSourceFileValidator(new ImportUploadGuard());
    }

    #[DataProvider('rejectedFileProvider')]
    public function testRejectsDisallowedImportFiles(string $originalName, string $expectedMessage): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'import_val_');
        file_put_contents($tmp, 'dummy');

        $file = new UploadedFile(
            $tmp,
            $originalName,
            'application/octet-stream',
            null,
            true,
        );

        $constraint = new ImportSourceFile();

        $this->validator->validate($file, $constraint);

        $this
            ->buildViolation($expectedMessage)
            ->assertRaised();
    }

    public function testAcceptsPlainCsvFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'import_val_');
        file_put_contents($tmp, "a;b\n1;2");

        $file = new UploadedFile(
            $tmp,
            'allocations.csv',
            'text/plain',
            null,
            true,
        );

        $this->validator->validate($file, new ImportSourceFile());

        $this->assertNoViolation();
    }

    public function testSkipsNullValue(): void
    {
        $this->validator->validate(null, new ImportSourceFile());

        $this->assertNoViolation();
    }

    public function testThrowsForInvalidConstraintType(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate(null, new NotBlank());
    }

    public function testThrowsForNonUploadedFileValue(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate('not-a-file', new ImportSourceFile());
    }

    public function testRejectsInvalidMimeWithDedicatedMessage(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'import_val_');
        file_put_contents($tmp, '%PDF-1.4');

        $file = new UploadedFile(
            $tmp,
            'notes.txt',
            'application/pdf',
            null,
            true,
        );

        $this->validator->validate($file, new ImportSourceFile());

        $this
            ->buildViolation('validation.import.file_mime_types')
            ->assertRaised();
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function rejectedFileProvider(): iterable
    {
        yield 'xlsx extension' => ['report.xlsx', 'validation.import.excel_rejected'];
        yield 'xls extension' => ['report.xls', 'validation.import.excel_rejected'];
        yield 'unsupported extension' => ['report.pdf', 'validation.import.file_extensions'];
    }
}

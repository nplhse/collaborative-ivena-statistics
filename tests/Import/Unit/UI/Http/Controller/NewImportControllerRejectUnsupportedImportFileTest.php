<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\UI\Http\Controller;

use App\Import\Application\Service\ImportUploadGuard;
use App\Import\UI\Http\Controller\NewImportController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Translation\IdentityTranslator;

final class NewImportControllerRejectUnsupportedImportFileTest extends TestCase
{
    public function testAddsFieldErrorForRejectedUploadWhenFieldHasNoErrors(): void
    {
        $file = $this->createUploadedFile('report.xlsx');
        $fileField = $this->createMock(FormInterface::class);
        $fileField->method('getData')->willReturn($file);
        $fileField->method('getErrors')->willReturn(new FormErrorIterator($fileField, []));
        $fileField->expects(self::once())->method('addError')->with(self::callback(
            static fn (FormError $error): bool => 'validation.import.excel_rejected' === (string) $error->getMessage(),
        ));

        $form = $this->createMock(FormInterface::class);
        $form->method('has')->with('file')->willReturn(true);
        $form->method('get')->with('file')->willReturn($fileField);

        $this->invokeRejectUnsupportedImportFile($form);
    }

    public function testSkipsAddingDuplicateFieldError(): void
    {
        $file = $this->createUploadedFile('report.xlsx');
        $fileField = $this->createMock(FormInterface::class);
        $fileField->method('getData')->willReturn($file);
        $fileField->method('getErrors')->willReturn(new FormErrorIterator($fileField, [new FormError('existing')]));
        $fileField->expects(self::never())->method('addError');

        $form = $this->createMock(FormInterface::class);
        $form->method('has')->with('file')->willReturn(true);
        $form->method('get')->with('file')->willReturn($fileField);

        $this->invokeRejectUnsupportedImportFile($form);
    }

    public function testReturnsEarlyWhenFormHasNoFileField(): void
    {
        $form = $this->createMock(FormInterface::class);
        $form->method('has')->with('file')->willReturn(false);
        $form->expects(self::never())->method('get');

        $this->invokeRejectUnsupportedImportFile($form);
    }

    public function testReturnsEarlyWhenFileFieldIsEmpty(): void
    {
        $fileField = $this->createMock(FormInterface::class);
        $fileField->method('getData')->willReturn(null);
        $fileField->expects(self::never())->method('addError');

        $form = $this->createMock(FormInterface::class);
        $form->method('has')->with('file')->willReturn(true);
        $form->method('get')->with('file')->willReturn($fileField);

        $this->invokeRejectUnsupportedImportFile($form);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function invokeRejectUnsupportedImportFile(FormInterface $form): void
    {
        $reflection = new \ReflectionClass(NewImportController::class);
        /** @var NewImportController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        $guardProperty = $reflection->getProperty('importUploadGuard');
        $guardProperty->setValue($controller, new ImportUploadGuard());

        $translatorProperty = $reflection->getProperty('translator');
        $translatorProperty->setValue($controller, new IdentityTranslator());

        $method = $reflection->getMethod('rejectUnsupportedImportFile');
        $method->invoke($controller, $form);
    }

    private function createUploadedFile(string $originalName): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'import_controller_');
        file_put_contents($tmp, 'dummy');

        return new UploadedFile(
            $tmp,
            $originalName,
            'application/octet-stream',
            null,
            true,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application;

use App\Import\Application\ImportAllowedFileTypes;
use PHPUnit\Framework\TestCase;

final class ImportAllowedFileTypesTest extends TestCase
{
    public function testDefinesSupportedImportFormats(): void
    {
        self::assertContains('csv', ImportAllowedFileTypes::EXTENSIONS);
        self::assertContains('txt', ImportAllowedFileTypes::EXTENSIONS);
        self::assertContains('xls', ImportAllowedFileTypes::REJECTED_EXTENSIONS);
        self::assertContains('xlsx', ImportAllowedFileTypes::REJECTED_EXTENSIONS);
        self::assertArrayHasKey('csv', ImportAllowedFileTypes::EXTENSION_MIME_MAP);
        self::assertArrayHasKey('txt', ImportAllowedFileTypes::EXTENSION_MIME_MAP);
        self::assertContains('text/csv', ImportAllowedFileTypes::MIME_TYPES);
    }

    public function testCannotBeInstantiated(): void
    {
        $reflection = new \ReflectionClass(ImportAllowedFileTypes::class);

        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        $constructor->invoke($reflection->newInstanceWithoutConstructor());
    }
}

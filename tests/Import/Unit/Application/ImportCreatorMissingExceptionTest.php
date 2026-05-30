<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application;

use App\Import\Application\Exception\ImportCreatorMissingException;
use PHPUnit\Framework\TestCase;

final class ImportCreatorMissingExceptionTest extends TestCase
{
    public function testExceptionMessageContainsImportId(): void
    {
        $exception = new ImportCreatorMissingException(42);

        self::assertStringContainsString('Import #42', $exception->getMessage());
        self::assertStringContainsString('createdBy', $exception->getMessage());
    }
}

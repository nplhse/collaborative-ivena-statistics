<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Exception;

use App\Import\Application\Exception\ImportTypeMismatchException;
use App\Import\Domain\Enum\ImportType;
use PHPUnit\Framework\TestCase;

final class ImportTypeMismatchExceptionTest extends TestCase
{
    public function testMissingTypeNamesNoRequeueCommand(): void
    {
        $exception = new ImportTypeMismatchException(15, ImportType::CLOSURE, null);

        self::assertSame(
            'Import #15 is a missing import and cannot be requeued as Closure.',
            $exception->getMessage(),
        );
    }
}

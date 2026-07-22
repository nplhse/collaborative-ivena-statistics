<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Exception;

use App\Import\Application\Exception\ImportException;
use PHPUnit\Framework\TestCase;

final class ImportExceptionTest extends TestCase
{
    public function testSummarizeWithMessageOnly(): void
    {
        $exception = new ImportException('Something went wrong.');

        self::assertSame('Something went wrong.', $exception->summarize());
    }

    public function testSummarizeWithCodeStrFieldAndValue(): void
    {
        $exception = new ImportException(
            'Invalid value.',
            field: 'age',
            value: '-1',
            codeStr: 'INVALID_VALUE',
        );

        self::assertSame(
            'INVALID_VALUE | Invalid value. | field=age | value="-1"',
            $exception->summarize(),
        );
    }

    public function testContextFiltersEmptyAndNullValues(): void
    {
        $exception = new ImportException(
            'Invalid value.',
            field: null,
            value: '',
            codeStr: null,
        );

        self::assertSame([
            'exception' => ImportException::class,
            'message' => 'Invalid value.',
        ], $exception->context());
    }

    public function testContextIncludesAllProvidedValues(): void
    {
        $exception = new ImportException(
            'Invalid value.',
            field: 'age',
            value: '-1',
            codeStr: 'INVALID_VALUE',
        );

        self::assertSame([
            'error_code' => 'INVALID_VALUE',
            'field' => 'age',
            'value' => '-1',
            'exception' => ImportException::class,
            'message' => 'Invalid value.',
        ], $exception->context());
    }
}

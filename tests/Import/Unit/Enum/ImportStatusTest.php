<?php

namespace App\Tests\Import\Unit\Enum;

use App\Import\Domain\Enum\ImportStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportStatusTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testGetTypeReturnsValue(ImportStatus $case, string $value): void
    {
        self::assertSame($value, $case->getType());
        self::assertSame($value, $case->value);
    }

    public function testGetValuesReturnsAllValuesInOrder(): void
    {
        $expected = ['Pending', 'Running', 'Completed', 'Failed', 'Cancelled', 'Partial'];
        self::assertSame($expected, ImportStatus::getValues());
    }

    public function testFromAndTryFrom(): void
    {
        self::assertSame(ImportStatus::PENDING, ImportStatus::from('Pending'));
        self::assertSame(ImportStatus::COMPLETED, ImportStatus::tryFrom('Completed'));
        self::assertNull(ImportStatus::tryFrom('Unknown'));
    }

    /**
     * @return array<string, array{case: ImportStatus, value: string}>
     */
    public static function caseProvider(): array
    {
        return [
            'PENDING' => ['case' => ImportStatus::PENDING, 'value' => 'Pending'],
            'RUNNING' => ['case' => ImportStatus::RUNNING, 'value' => 'Running'],
            'COMPLETED' => ['case' => ImportStatus::COMPLETED, 'value' => 'Completed'],
            'FAILED' => ['case' => ImportStatus::FAILED, 'value' => 'Failed'],
            'CANCELLED' => ['case' => ImportStatus::CANCELLED, 'value' => 'Cancelled'],
            'PARTIAL' => ['case' => ImportStatus::PARTIAL, 'value' => 'Partial'],
        ];
    }
}

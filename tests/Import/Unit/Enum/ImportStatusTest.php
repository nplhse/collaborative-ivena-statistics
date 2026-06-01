<?php

declare(strict_types=1);

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

    #[DataProvider('isFinalProvider')]
    public function testIsFinal(ImportStatus $case, bool $expected): void
    {
        self::assertSame($expected, $case->isFinal());
    }

    /**
     * @return array<string, array{case: ImportStatus, expected: bool}>
     */
    public static function isFinalProvider(): array
    {
        return [
            'PENDING' => ['case' => ImportStatus::PENDING, 'expected' => false],
            'RUNNING' => ['case' => ImportStatus::RUNNING, 'expected' => false],
            'COMPLETED' => ['case' => ImportStatus::COMPLETED, 'expected' => true],
            'FAILED' => ['case' => ImportStatus::FAILED, 'expected' => true],
            'CANCELLED' => ['case' => ImportStatus::CANCELLED, 'expected' => true],
            'PARTIAL' => ['case' => ImportStatus::PARTIAL, 'expected' => true],
        ];
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

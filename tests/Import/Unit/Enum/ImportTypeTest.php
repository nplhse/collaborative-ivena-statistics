<?php

namespace App\Tests\Import\Unit\Enum;

use App\Import\Domain\Enum\ImportType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportTypeTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testGetTypeReturnsValue(ImportType $case, string $value): void
    {
        self::assertSame($value, $case->getType());
        self::assertSame($value, $case->value);
    }

    public function testGetValuesReturnsAllValuesInOrder(): void
    {
        $expected = ['Allocation'];
        self::assertSame($expected, ImportType::getValues());
    }

    public function testFromAndTryFrom(): void
    {
        self::assertSame(ImportType::ALLOCATION, ImportType::from('Allocation'));
        self::assertSame(ImportType::ALLOCATION, ImportType::tryFrom('Allocation'));
        self::assertNull(ImportType::tryFrom('Unknown'));
    }

    /**
     * @return array<string, array{case: ImportType, value: string}>
     */
    public static function caseProvider(): array
    {
        return [
            'ALLOCATION' => ['case' => ImportType::ALLOCATION, 'value' => 'Allocation'],
        ];
    }
}

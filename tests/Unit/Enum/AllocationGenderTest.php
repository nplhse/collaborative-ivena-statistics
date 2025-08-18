<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\AllocationGender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationGenderTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testGetTypeReturnsValue(AllocationGender $case, string $value): void
    {
        self::assertSame($value, $case->getType());
        self::assertSame($value, $case->value);
    }

    public function testGetValuesReturnsAllValuesInOrder(): void
    {
        $expected = ['M', 'F', 'X'];
        self::assertSame($expected, AllocationGender::getValues());
    }

    public function testFromAndTryFrom(): void
    {
        self::assertSame(AllocationGender::MALE, AllocationGender::from('M'));
        self::assertSame(AllocationGender::FEMALE, AllocationGender::tryFrom('F'));
        self::assertNull(AllocationGender::tryFrom('Unknown'));
    }

    /**
     * @return array<string, array{case: AllocationGender, value: string}>
     */
    public static function caseProvider(): array
    {
        return [
            'MALE' => ['case' => AllocationGender::MALE, 'value' => 'M'],
            'FEMALE' => ['case' => AllocationGender::FEMALE, 'value' => 'F'],
            'OTHER' => ['case' => AllocationGender::OTHER, 'value' => 'X'],
        ];
    }
}

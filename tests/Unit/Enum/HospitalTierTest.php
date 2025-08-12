<?php

declare(strict_types=1);

namespace App\Test\Unit\Enum;

use App\Enum\HospitalTier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HospitalTierTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testGetTypeReturnsValue(HospitalTier $case, string $value): void
    {
        self::assertSame($value, $case->getType());
        self::assertSame($value, $case->value);
    }

    public function testGetValuesReturnsAllValuesInOrder(): void
    {
        $expected = ['Basic', 'Extended', 'Full'];
        self::assertSame($expected, HospitalTier::getValues());
    }

    public function testFromAndTryFrom(): void
    {
        self::assertSame(HospitalTier::BASIC, HospitalTier::from('Basic'));
        self::assertSame(HospitalTier::EXTENDED, HospitalTier::tryFrom('Extended'));
        self::assertNull(HospitalTier::tryFrom('Unknown'));
    }

    /**
     * @return array<string, array{case: HospitalTier, value: string}>
     */
    public static function caseProvider(): array
    {
        return [
            'BASIC' => ['case' => HospitalTier::BASIC, 'value' => 'Basic'],
            'EXTENDED' => ['case' => HospitalTier::EXTENDED, 'value' => 'Extended'],
            'FULL' => ['case' => HospitalTier::FULL, 'value' => 'Full'],
        ];
    }
}

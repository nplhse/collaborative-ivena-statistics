<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Enum;

use App\Allocation\Domain\Enum\HospitalLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HospitalLocationTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testGetTypeReturnsValue(HospitalLocation $case, string $value): void
    {
        self::assertSame($value, $case->getType());
        self::assertSame($value, $case->value);
    }

    public function testGetValuesReturnsAllValuesInOrder(): void
    {
        $expected = ['Urban', 'Mixed', 'Rural'];
        self::assertSame($expected, HospitalLocation::getValues());
    }

    public function testFromAndTryFrom(): void
    {
        self::assertSame(HospitalLocation::URBAN, HospitalLocation::from('Urban'));
        self::assertSame(HospitalLocation::MIXED, HospitalLocation::tryFrom('Mixed'));
        self::assertNull(HospitalLocation::tryFrom('Unknown'));
    }

    /**
     * @return array<string, array{case: HospitalLocation, value: string}>
     */
    public static function caseProvider(): array
    {
        return [
            'URBAN' => ['case' => HospitalLocation::URBAN, 'value' => 'Urban'],
            'MIXED' => ['case' => HospitalLocation::MIXED, 'value' => 'Mixed'],
            'RURAL' => ['case' => HospitalLocation::RURAL, 'value' => 'Rural'],
        ];
    }
}

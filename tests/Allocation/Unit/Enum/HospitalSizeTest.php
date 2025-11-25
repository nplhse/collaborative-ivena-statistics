<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Enum;

use App\Allocation\Domain\Enum\HospitalSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HospitalSizeTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testGetTypeReturnsValue(HospitalSize $case, string $value): void
    {
        self::assertSame($value, $case->getType());
        self::assertSame($value, $case->value);
    }

    public function testGetValuesReturnsAllValuesInOrder(): void
    {
        $expected = ['Small', 'Medium', 'Large'];
        self::assertSame($expected, HospitalSize::getValues());
    }

    public function testFromAndTryFrom(): void
    {
        self::assertSame(HospitalSize::SMALL, HospitalSize::from('Small'));
        self::assertSame(HospitalSize::MEDIUM, HospitalSize::tryFrom('Medium'));
        self::assertNull(HospitalSize::tryFrom('Unknown'));
    }

    /**
     * @return array<string, array{case: HospitalSize, value: string}>
     */
    public static function caseProvider(): array
    {
        return [
            'SMALL' => ['case' => HospitalSize::SMALL, 'value' => 'Small'],
            'MEDIUM' => ['case' => HospitalSize::MEDIUM, 'value' => 'Medium'],
            'LARGE' => ['case' => HospitalSize::LARGE, 'value' => 'Large'],
        ];
    }
}

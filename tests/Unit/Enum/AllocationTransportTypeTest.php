<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\AllocationTransportType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationTransportTypeTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testGetTypeReturnsValue(AllocationTransportType $case, string $value): void
    {
        self::assertSame($value, $case->getType());
        self::assertSame($value, $case->value);
    }

    public function testGetValuesReturnsAllValuesInOrder(): void
    {
        $expected = ['G', 'A'];
        self::assertSame($expected, AllocationTransportType::getValues());
    }

    public function testFromAndTryFrom(): void
    {
        self::assertSame(AllocationTransportType::GROUND, AllocationTransportType::from('G'));
        self::assertSame(AllocationTransportType::AIR, AllocationTransportType::tryFrom('A'));
        self::assertNull(AllocationTransportType::tryFrom('Unknown'));
    }

    /**
     * @return array<string, array{case: AllocationTransportType, value: string}>
     */
    public static function caseProvider(): array
    {
        return [
            'GROUND' => ['case' => AllocationTransportType::GROUND, 'value' => 'G'],
            'AIR' => ['case' => AllocationTransportType::AIR, 'value' => 'A'],
        ];
    }
}

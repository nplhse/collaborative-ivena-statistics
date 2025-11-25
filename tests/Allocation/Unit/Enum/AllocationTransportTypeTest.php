<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Enum;

use App\Allocation\Domain\Enum\AllocationTransportType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationTransportTypeTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testGetTypeReturnsValue(AllocationTransportType $case, string $value, string $label): void
    {
        self::assertSame($value, $case->getType());
        self::assertSame($value, $case->value);
        self::assertSame($label, $case->label());
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
        self::assertNull(AllocationTransportType::tryFrom('g'));
    }

    public function testLabelsAreUniqueAndWellFormed(): void
    {
        $labels = array_map(static fn (AllocationTransportType $c) => $c->label(), AllocationTransportType::cases());
        self::assertSame($labels, array_values(array_unique($labels)));
        foreach ($labels as $label) {
            self::assertMatchesRegularExpression('/^label\.transportType\.(ground|air)$/', $label);
        }
    }

    /**
     * @return array<string, array{case: AllocationTransportType, value: string}>
     */
    public static function caseProvider(): array
    {
        return [
            'GROUND' => ['case' => AllocationTransportType::GROUND, 'value' => 'G', 'label' => 'label.transportType.ground'],
            'AIR' => ['case' => AllocationTransportType::AIR, 'value' => 'A', 'label' => 'label.transportType.air'],
        ];
    }
}

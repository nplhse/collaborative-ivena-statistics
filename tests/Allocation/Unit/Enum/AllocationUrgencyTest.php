<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Enum;

use App\Allocation\Domain\Enum\AllocationUrgency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationUrgencyTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testGetTypeReturnsValue(AllocationUrgency $case, int $value, string $label): void
    {
        self::assertSame($value, $case->getType());
        self::assertSame($value, $case->value);
        self::assertSame($label, $case->label());
    }

    public function testGetValuesReturnsAllValuesInOrder(): void
    {
        self::assertSame([1, 2, 3], AllocationUrgency::getValues());
    }

    public function testFromAndTryFrom(): void
    {
        self::assertSame(AllocationUrgency::EMERGENCY, AllocationUrgency::from(1));
        self::assertSame(AllocationUrgency::INPATIENT, AllocationUrgency::from(2));
        self::assertSame(AllocationUrgency::OUTPATIENT, AllocationUrgency::from(3));

        self::assertNull(AllocationUrgency::tryFrom(0));
        self::assertNull(AllocationUrgency::tryFrom(4));
    }

    public function testLabelsAreUniqueAndWellFormed(): void
    {
        $labels = array_map(static fn (AllocationUrgency $case) => $case->label(), AllocationUrgency::cases());

        self::assertSame($labels, array_values(array_unique($labels)));

        foreach ($labels as $label) {
            self::assertMatchesRegularExpression('/^label\.urgency\.(emergency|inpatient|outpatient)$/', $label);
        }
    }

    /**
     * @return array<string, array{case: AllocationUrgency, value: int, label: string}>
     */
    public static function caseProvider(): array
    {
        return [
            'Emergency care' => ['case' => AllocationUrgency::EMERGENCY, 'value' => 1, 'label' => 'label.urgency.emergency'],
            'Inpatient care' => ['case' => AllocationUrgency::INPATIENT, 'value' => 2, 'label' => 'label.urgency.inpatient'],
            'Outpatient care' => ['case' => AllocationUrgency::OUTPATIENT, 'value' => 3, 'label' => 'label.urgency.outpatient'],
        ];
    }
}

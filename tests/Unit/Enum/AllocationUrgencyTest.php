<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\AllocationUrgency;
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
        $expected = [1, 2, 3];
        self::assertSame($expected, AllocationUrgency::getValues());
    }

    public function testFromAndTryFrom(): void
    {
        self::assertSame(AllocationUrgency::IMMEDIATE, AllocationUrgency::from(1));
        self::assertSame(AllocationUrgency::URGENT, AllocationUrgency::from(2));
        self::assertSame(AllocationUrgency::DELAYED, AllocationUrgency::from(3));

        self::assertNull(AllocationUrgency::tryFrom(0));
        self::assertNull(AllocationUrgency::tryFrom(4));
    }

    public function testLabelsAreUniqueAndWellFormed(): void
    {
        $labels = array_map(static fn (AllocationUrgency $c) => $c->label(), AllocationUrgency::cases());
        self::assertSame($labels, array_values(array_unique($labels)));
        foreach ($labels as $label) {
            self::assertMatchesRegularExpression('/^label\.urgency\.(immediate|urgent|delayed)$/', $label);
        }
    }

    /**
     * @return array<string, array{case: AllocationUrgency, value: int, label: string}>
     */
    public static function caseProvider(): array
    {
        return [
            'Immediate' => ['case' => AllocationUrgency::IMMEDIATE, 'value' => 1, 'label' => 'label.urgency.immediate'],
            'Urgent' => ['case' => AllocationUrgency::URGENT, 'value' => 2, 'label' => 'label.urgency.urgent'],
            'Delayed' => ['case' => AllocationUrgency::DELAYED, 'value' => 3, 'label' => 'label.urgency.delayed'],
        ];
    }
}

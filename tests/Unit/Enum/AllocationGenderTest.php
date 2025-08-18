<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\AllocationGender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationGenderTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testGetTypeReturnsValue(AllocationGender $case, string $value, string $label): void
    {
        self::assertSame($value, $case->getType());
        self::assertSame($value, $case->value);
        self::assertSame($label, $case->label());
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
        self::assertNull(AllocationGender::tryFrom('m'));
    }

    public function testLabelsAreUniqueAndWellFormed(): void
    {
        $labels = array_map(static fn (AllocationGender $c) => $c->label(), AllocationGender::cases());
        self::assertSame($labels, array_values(array_unique($labels)));
        foreach ($labels as $label) {
            self::assertMatchesRegularExpression('/^label\.gender\.(male|female|other)$/', $label);
        }
    }

    /**
     * @return array<string, array{case: AllocationGender, value: string, label: string}>
     */
    public static function caseProvider(): array
    {
        return [
            'MALE' => ['case' => AllocationGender::MALE, 'value' => 'M', 'label' => 'label.gender.male'],
            'FEMALE' => ['case' => AllocationGender::FEMALE, 'value' => 'F', 'label' => 'label.gender.female'],
            'OTHER' => ['case' => AllocationGender::OTHER, 'value' => 'X', 'label' => 'label.gender.other'],
        ];
    }
}

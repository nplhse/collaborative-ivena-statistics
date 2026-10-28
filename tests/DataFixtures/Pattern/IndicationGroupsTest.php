<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern;

use App\Allocation\Infrastructure\Faker\Provider\MedicalSpecialities;
use App\DataFixtures\Pattern\Application\IndicationGroups;
use PHPUnit\Framework\TestCase;

final class IndicationGroupsTest extends TestCase
{
    public function testEveryGroupUsesCatalogDepartmentsAndSharesThatSumToOne(): void
    {
        foreach (IndicationGroups::all() as $prefix => $group) {
            foreach (array_keys($group['departments']) as $department) {
                self::assertContains($department, MedicalSpecialities::unitNames(), sprintf('%s: %s', $prefix, $department));
            }
            foreach (['departments', 'urgency', 'ages'] as $key) {
                self::assertEqualsWithDelta(1.0, array_sum($group[$key]), 0.001, sprintf('%s %s', $prefix, $key));
            }
            foreach (array_keys($group['ages']) as $range) {
                self::assertMatchesRegularExpression('/^\d{1,2}-\d{2}$/', $range, (string) $prefix);
            }
            self::assertGreaterThanOrEqual(0.0, $group['female']);
            self::assertLessThanOrEqual(1.0, $group['female']);
        }
    }

    public function testTheLongestPrefixWins(): void
    {
        self::assertSame(['Kardiologie' => 1.0], IndicationGroups::forCode('332')['departments'], 'STEMI');
        self::assertArrayHasKey('Schlaganfallmedizin', IndicationGroups::forCode('421')['departments'], 'stroke');
        self::assertArrayHasKey('Allgemeine Psychiatrie', IndicationGroups::forCode('432')['departments'], 'psychiatry');
        self::assertSame(['0-17' => 1.0], IndicationGroups::forCode('511')['ages'], 'paediatrics');
        self::assertSame(1.0, IndicationGroups::forCode('551')['female'], 'gynaecology');
        self::assertSame(0.90, IndicationGroups::forCode('273')['ages']['65-99'], 'hip fracture');
        self::assertSame(IndicationGroups::forCode('3'), IndicationGroups::forCode('711'), 'codes without a group behave like internal medicine');
        self::assertSame(IndicationGroups::forCode('3'), IndicationGroups::forCode(''), 'no indication');
    }
}

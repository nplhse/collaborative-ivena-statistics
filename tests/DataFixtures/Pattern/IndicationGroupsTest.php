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
            if (($group['flags']['is_pregnant'] ?? 0.0) >= 1.0) {
                foreach (array_keys($group['ages']) as $range) {
                    [$min, $max] = array_map(intval(...), explode('-', $range));
                    self::assertTrue($min >= 15 && $max <= 49, sprintf('%s: pregnant patients are 15 to 49, not %s', $prefix, $range));
                }
                self::assertSame(1.0, $group['female'], (string) $prefix);
            }
        }
    }

    public function testTheLongestPrefixWins(): void
    {
        self::assertSame(['Kardiologie' => 1.0], IndicationGroups::forCode('332')['departments'], 'STEMI');
        self::assertArrayHasKey('Schlaganfallmedizin', IndicationGroups::forCode('421')['departments'], 'stroke');
        self::assertArrayHasKey('Allgemeine Psychiatrie', IndicationGroups::forCode('432')['departments'], 'psychiatry');
        self::assertSame(['0-17' => 1.0], IndicationGroups::forCode('511')['ages'], 'paediatrics');
        self::assertSame(1.0, IndicationGroups::forCode('551')['female'], 'gynaecology');
        self::assertArrayHasKey('Gynäkologie', IndicationGroups::forCode('551')['departments'], 'gynaecology, not obstetrics');
        foreach (['521', '526', '536', '546', '549'] as $code) {
            self::assertArrayHasKey('Geburtshilfe', IndicationGroups::forCode($code)['departments'], 'obstetrics: '.$code);
            self::assertSame(1.0, IndicationGroups::forCode($code)['flags']['is_pregnant'], 'obstetrics: '.$code);
        }
        self::assertSame(0.90, IndicationGroups::forCode('530')['urgency']['1'], 'acute fetal danger');
        self::assertSame(IndicationGroups::forCode('530'), IndicationGroups::forCode('540'));
        self::assertSame(0.90, IndicationGroups::forCode('273')['ages']['65-99'], 'hip fracture');
        self::assertSame(IndicationGroups::forCode('3'), IndicationGroups::forCode('711'), 'codes without a group behave like internal medicine');
        self::assertSame(IndicationGroups::forCode('3'), IndicationGroups::forCode(''), 'no indication');
    }
}

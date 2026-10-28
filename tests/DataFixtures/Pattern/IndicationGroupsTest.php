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
        self::assertSame(['1-17' => 1.0], IndicationGroups::forCode('511')['ages'], 'paediatrics');
        self::assertSame(1.0, IndicationGroups::forCode('551')['female'], 'gynaecology');
        self::assertArrayHasKey('Gynäkologie', IndicationGroups::forCode('551')['departments'], 'gynaecology, not obstetrics');
        foreach (['521', '526', '536', '546', '549'] as $code) {
            self::assertArrayHasKey('Geburtshilfe', IndicationGroups::forCode($code)['departments'], 'obstetrics: '.$code);
            self::assertSame(1.0, IndicationGroups::forCode($code)['flags']['is_pregnant'], 'obstetrics: '.$code);
        }
        self::assertSame(0.90, IndicationGroups::forCode('530')['urgency']['1'], 'acute fetal danger');
        self::assertSame(IndicationGroups::forCode('530'), IndicationGroups::forCode('540'));
        self::assertSame(['40-64' => 0.10, '65-79' => 0.30, '80-89' => 0.40, '90-99' => 0.20], IndicationGroups::forCode('273')['ages'], 'hip fracture');
        self::assertSame(IndicationGroups::forCode('25'), IndicationGroups::forCode('254'), 'back pain');
        self::assertSame(IndicationGroups::forCode('211'), IndicationGroups::forCode('212'), 'polytrauma with and without head injury');
        self::assertSame(0.80, IndicationGroups::forCode('211')['urgency']['1'], 'polytrauma');
        self::assertSame(0.05, IndicationGroups::forCode('214')['urgency']['1'], 'other multiple injuries are no polytrauma');
        self::assertSame(IndicationGroups::forCode('214'), IndicationGroups::forCode('219'), 'superficial injury');
        self::assertArrayHasKey('Wirbelsäulenchirurgie', IndicationGroups::forCode('252')['departments'], 'spine');
        self::assertNotSame(IndicationGroups::forCode('22'), IndicationGroups::forCode('223'), 'face injury has a group of its own');
        self::assertSame(IndicationGroups::forCode('3'), IndicationGroups::forCode('711'), 'codes without a group behave like internal medicine');
        self::assertSame(IndicationGroups::forCode('3'), IndicationGroups::forCode(''), 'no indication');
    }

    /**
     * Urgency 2 is the common case; time-critical groups are mostly urgency 1 (#909).
     */
    public function testUrgencyTwoIsTheCommonCase(): void
    {
        foreach (['322', '311', '353', '341', '361'] as $code) {
            $urgency = IndicationGroups::forCode($code)['urgency'];
            self::assertGreaterThanOrEqual(0.85, $urgency['2'], 'internal medicine: '.$code);
            self::assertLessThanOrEqual(0.10, $urgency['3'] ?? 0.0, 'internal medicine, few urgency 3: '.$code);
        }
        self::assertSame(['1' => 0.90, '2' => 0.10], IndicationGroups::forCode('421')['urgency'], 'stroke');
        self::assertSame(['1' => 0.90, '2' => 0.10], IndicationGroups::forCode('332')['urgency'], 'STEMI');
        self::assertSame(['1' => 1.0], IndicationGroups::forCode('132')['urgency'], 'CPR');
    }

    /**
     * Old patients in three bands, so the 90s do not get as many cases as the 70s; no age 0 (#909).
     */
    public function testAgeBands(): void
    {
        foreach (IndicationGroups::all() as $prefix => $group) {
            self::assertArrayNotHasKey('65-99', $group['ages'], (string) $prefix);
            foreach (array_keys($group['ages']) as $range) {
                self::assertStringStartsNotWith('0-', $range, sprintf('%s: children are 1 to 17', $prefix));
            }
            if (isset($group['ages']['90-99'])) {
                self::assertLessThan($group['ages']['80-89'], $group['ages']['90-99'], sprintf('%s: fewer patients over 90 than in their 80s', $prefix));
            }
        }
        self::assertSame(['1-17' => 0.05, '18-39' => 0.10, '40-64' => 0.25, '65-79' => 0.25, '80-89' => 0.25, '90-99' => 0.10], IndicationGroups::forCode('322')['ages'], 'internal medicine has a few children');
    }
}

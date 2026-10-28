<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Structure;

use App\DataFixtures\Generator\Application\HospitalProfile;
use App\DataFixtures\Pattern\Application\IndicationGroups;
use App\DataFixtures\Pattern\Application\IndicationGroupTable;
use App\DataFixtures\Pattern\Dto\DistributionPattern;
use App\DataFixtures\Structure\Dto\HospitalResources;
use App\DataFixtures\Structure\Dto\MixTilt;
use App\DataFixtures\Structure\Dto\VolumeModel;
use PHPUnit\Framework\TestCase;

final class VolumeAndTiltTest extends TestCase
{
    public function testTheBedFactorFollowsTheBedsOfTheSize(): void
    {
        $model = new VolumeModel(['Medium' => 450], bedElasticity: 0.8, hospitalSpread: 0.2);

        self::assertSame(1.0, $model->bedFactor('Medium', 450));
        self::assertEqualsWithDelta(1.7411, $model->bedFactor('Medium', 900), 0.0001, '2 ^ 0.8');
        self::assertSame(1.0, $model->bedFactor('Medium', null), 'without beds the preset rate stays');
        self::assertSame(1.0, $model->bedFactor('Large', 900), 'without reference beds for the size');
        self::assertSame(2.0, new VolumeModel(['Medium' => 450], 1.0, 0.0)->bedFactor('Medium', 900), 'elasticity 1: proportional');
    }

    /**
     * Category 2 (injuries) doubled: of 211 and 311 with equal weight, 2/3 of the draws are injuries.
     */
    public function testTheTiltWeightsCategoriesAgesAndUrgency(): void
    {
        $pattern = new DistributionPattern([])->withTilt(new MixTilt(categories: [2 => 2.0], oldAge: 0.5, urgent: 2.0)->toArray());

        self::assertSame(['211' => 1.0, '311' => 0.5], $pattern->tiltIndications(['211' => 0.5, '311' => 0.5]));
        self::assertSame([1 => 0.4, 2 => 0.8], $pattern->tiltUrgency([1 => 0.2, 2 => 0.8]));
        self::assertSame(['18-39' => 0.15, '40-64' => 0.30, '65-99' => 0.275], $pattern->tiltAges(['18-39' => 0.15, '40-64' => 0.30, '65-99' => 0.55]));
    }

    /**
     * A pattern package brings its own indication groups (#883); the hospital profile must keep them.
     */
    public function testTheProfileKeepsTheIndicationGroupsOfAPackage(): void
    {
        $groups = new IndicationGroupTable(['3' => IndicationGroups::forCode('3')], '3');
        $pattern = new DistributionPattern([], $groups);

        $applied = new HospitalProfile('urban-basic', new HospitalResources(), 0.02, ['Geburtshilfe'], blockedIndications: ['332'], tilt: new MixTilt([2 => 2.0]))->applyTo($pattern);

        self::assertSame($groups, $applied->indicationGroups());
        self::assertSame($groups, $pattern->withTilt(new MixTilt()->toArray())->indicationGroups());
        self::assertSame($groups, $pattern->withoutIndications(['332'])->indicationGroups());
    }

    public function testWithoutTiltNothingChanges(): void
    {
        $pattern = new DistributionPattern([]);

        self::assertSame(['211' => 0.5, '311' => 0.5], $pattern->tiltIndications(['211' => 0.5, '311' => 0.5]));
        self::assertSame([1 => 0.2, 2 => 0.8], $pattern->tiltUrgency([1 => 0.2, 2 => 0.8]));
        self::assertSame(['65-99' => 0.55], $pattern->tiltAges(['65-99' => 0.55]));
        self::assertSame(['211' => 0.5], new DistributionPattern([])->withTilt(new MixTilt()->toArray())->tiltIndications(['211' => 0.5]));
    }
}

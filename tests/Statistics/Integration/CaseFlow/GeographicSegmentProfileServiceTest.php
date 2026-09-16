<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\CaseFlow;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowCriteria;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMapFeature;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegment;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentCatalogFactory;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentProfileDimension;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentProfileService;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class GeographicSegmentProfileServiceTest extends KernelTestCase
{
    use Factories;

    public function testBuildsEntireAreaUrgencyAndHidesMedianForTravelBands(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'geo-prof-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'GeoProfState']);
        $home = DispatchAreaFactory::createOne(['name' => 'GeoProfHome', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'GeoProfHospital',
            'state' => $state,
            'dispatchArea' => $home,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'GeoProfSpec']);
        DepartmentFactory::createOne(['name' => 'GeoProfDept']);
        AssignmentFactory::createOne(['name' => 'GeoProfAssign']);
        IndicationRawFactory::createOne(['name' => 'GeoProfRaw', 'code' => 912_711]);

        $import = ImportFactory::createOne(['name' => 'GeoProfImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createMany(8, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $home,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 40,
            'requiresResus' => true,
            'isVentilated' => false,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 09:15:00'),
        ]);
        AllocationFactory::createMany(4, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $home,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'age' => 12,
            'requiresResus' => false,
            'isVentilated' => true,
            'createdAt' => new \DateTimeImmutable('2026-04-02 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-02 09:15:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $criteria = new CaseFlowCriteria(
            new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::All),
            new StatisticsScopeCriteria([$hospital->getId()]),
            new StatisticsPeriodBounds(null),
            CaseFlowMode::HospitalOrigin,
        );
        $catalog = new GeographicSegmentCatalogFactory()->fromMapFeatures(
            [new CaseFlowMapFeature($home->getId(), 'GeoProfHome', 'geoprofhome', 12, 100.0, false)],
            null,
            true,
        );
        $service = self::getContainer()->get(GeographicSegmentProfileService::class);

        $entireArea = $service->build($criteria, $catalog, null, GeographicSegmentProfileDimension::Overview);
        self::assertTrue($entireArea->hasSelection);
        self::assertFalse($entireArea->suppressed);
        self::assertTrue($entireArea->translateLabel);
        self::assertSame(GeographicSegmentProfileService::ENTIRE_AREA_LABEL, $entireArea->label);
        self::assertSame(12, $entireArea->segmentCases);
        self::assertNull($entireArea->sharePercent);
        self::assertTrue($entireArea->showMedianTransport);
        self::assertNotNull($entireArea->medianTransportMinutes);
        self::assertCount(2, $entireArea->groups);
        self::assertSame('stats.case_flow.segment.group.urgency', $entireArea->groups[0]->titleTranslationKey);
        self::assertSame('label.urgency.emergency', $entireArea->groups[0]->rows[0]->labelTranslationKey);
        self::assertSame(8, $entireArea->groups[0]->rows[0]->count);
        self::assertSame(66.7, $entireArea->groups[0]->rows[0]->segmentPercent);
        self::assertNull($entireArea->groups[0]->rows[0]->referencePercent);
        self::assertNull($entireArea->groups[0]->rows[0]->deltaPp);
        self::assertSame('bg-red', $entireArea->groups[0]->rows[0]->barClass);
        self::assertSame('stats.case_flow.segment.group.gender', $entireArea->groups[1]->titleTranslationKey);
        self::assertSame(8, $entireArea->groups[1]->rows[0]->count);
        self::assertFalse($entireArea->showsReferenceComparison());

        $origin = $service->build(
            $criteria,
            $catalog,
            GeographicSegment::originArea($home->getId()),
            GeographicSegmentProfileDimension::Overview,
        );
        self::assertFalse($origin->translateLabel);
        self::assertSame('GeoProfHome', $origin->label);
        self::assertSame(100.0, $origin->sharePercent);
        self::assertCount(2, $origin->groups);
        self::assertSame(8, $origin->groups[1]->rows[0]->count);
        self::assertSame(66.7, $origin->groups[1]->rows[0]->segmentPercent);
        self::assertSame(66.7, $origin->groups[1]->rows[0]->referencePercent);
        self::assertSame(0.0, $origin->groups[1]->rows[0]->deltaPp);
        self::assertTrue($origin->showsReferenceComparison());

        $originAge = $service->build(
            $criteria,
            $catalog,
            GeographicSegment::originArea($home->getId()),
            GeographicSegmentProfileDimension::Age,
        );
        self::assertCount(1, $originAge->groups);
        self::assertNotSame([], $originAge->groups[0]->rows);

        $missingOrigin = $service->build(
            $criteria,
            $catalog,
            GeographicSegment::originArea(9_999_999),
            GeographicSegmentProfileDimension::Overview,
        );
        self::assertFalse($missingOrigin->translateLabel);
        self::assertNull($missingOrigin->label);
        self::assertTrue($missingOrigin->suppressed);

        $travel = $service->build(
            $criteria,
            $catalog,
            GeographicSegment::travelTimeBand('10_20'),
            GeographicSegmentProfileDimension::Resources,
        );
        self::assertFalse($travel->showMedianTransport);
        self::assertNull($travel->medianTransportMinutes);
        self::assertSame('statistics.distribution.transport_time_bucket.10_20', $travel->label);
        self::assertTrue($travel->translateLabel);
        self::assertCount(1, $travel->groups);
        self::assertCount(2, $travel->groups[0]->rows);
        self::assertSame(8, $travel->groups[0]->rows[0]->count);
        self::assertSame('statistics.distribution.dim.requires_resus', $travel->groups[0]->rows[0]->labelTranslationKey);
        self::assertSame(66.7, $travel->groups[0]->rows[0]->segmentPercent);
        self::assertSame(66.7, $travel->groups[0]->rows[0]->referencePercent);
        self::assertSame(0.0, $travel->groups[0]->rows[0]->deltaPp);
        self::assertTrue($travel->showsReferenceComparison());

        $features = $service->build(
            $criteria,
            $catalog,
            GeographicSegment::travelTimeBand('10_20'),
            GeographicSegmentProfileDimension::Features,
        );
        self::assertCount(1, $features->groups);
        self::assertCount(7, $features->groups[0]->rows);
        self::assertSame('statistics.distribution.dim.is_with_physician', $features->groups[0]->rows[0]->labelTranslationKey);
        self::assertSame('stats.analysis.feature.is_pregnant', $features->groups[0]->rows[4]->labelTranslationKey);
        self::assertSame('stats.analysis.feature.is_work_accident', $features->groups[0]->rows[5]->labelTranslationKey);
        self::assertSame('field.infection', $features->groups[0]->rows[6]->labelTranslationKey);
        self::assertSame(4, $features->groups[0]->rows[2]->count);
        self::assertSame('statistics.distribution.dim.is_ventilated', $features->groups[0]->rows[2]->labelTranslationKey);
    }

    public function testTravelBandUrgencyIsComparedAgainstHospitalPopulation(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'geo-prof-cmp-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'GeoProfCmpState']);
        $home = DispatchAreaFactory::createOne(['name' => 'GeoProfCmpHome', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'GeoProfCmpHospital',
            'state' => $state,
            'dispatchArea' => $home,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'GeoProfCmpSpec']);
        DepartmentFactory::createOne(['name' => 'GeoProfCmpDept']);
        AssignmentFactory::createOne(['name' => 'GeoProfCmpAssign']);
        IndicationRawFactory::createOne(['name' => 'GeoProfCmpRaw', 'code' => 912_712]);

        $import = ImportFactory::createOne(['name' => 'GeoProfCmpImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createMany(10, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $home,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 40,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 09:15:00'),
        ]);
        AllocationFactory::createMany(10, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $home,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'age' => 12,
            'createdAt' => new \DateTimeImmutable('2026-04-02 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-02 09:25:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $criteria = new CaseFlowCriteria(
            new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::All),
            new StatisticsScopeCriteria([$hospital->getId()]),
            new StatisticsPeriodBounds(null),
            CaseFlowMode::HospitalOrigin,
        );
        $catalog = new GeographicSegmentCatalogFactory()->fromMapFeatures(
            [new CaseFlowMapFeature($home->getId(), 'GeoProfCmpHome', 'geoprofcmphome', 20, 100.0, false)],
            null,
            true,
        );
        $profile = self::getContainer()->get(GeographicSegmentProfileService::class)->build(
            $criteria,
            $catalog,
            GeographicSegment::travelTimeBand('10_20'),
            GeographicSegmentProfileDimension::Overview,
        );

        self::assertSame(10, $profile->segmentCases);
        self::assertSame(20, $profile->populationCases);
        self::assertTrue($profile->showsReferenceComparison());
        self::assertCount(2, $profile->groups);
        $rows = $profile->groups[0]->rows;
        self::assertSame('label.urgency.emergency', $rows[0]->labelTranslationKey);
        self::assertSame(10, $rows[0]->count);
        self::assertSame(100.0, $rows[0]->segmentPercent);
        self::assertSame(50.0, $rows[0]->referencePercent);
        self::assertSame(50.0, $rows[0]->deltaPp);
        self::assertSame('label.urgency.inpatient', $rows[1]->labelTranslationKey);
        self::assertSame(0, $rows[1]->count);
        self::assertSame(0.0, $rows[1]->segmentPercent);
        self::assertSame(50.0, $rows[1]->referencePercent);
        self::assertSame(-50.0, $rows[1]->deltaPp);
        self::assertSame('label.urgency.outpatient', $rows[2]->labelTranslationKey);
        self::assertSame(0, $rows[2]->count);
        self::assertSame(0.0, $rows[2]->segmentPercent);
        self::assertSame(0.0, $rows[2]->referencePercent);
        self::assertSame(0.0, $rows[2]->deltaPp);
    }
}

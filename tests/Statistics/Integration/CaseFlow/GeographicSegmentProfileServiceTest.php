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

        $entireArea = $service->build($criteria, $catalog, null, GeographicSegmentProfileDimension::Urgency);
        self::assertTrue($entireArea->hasSelection);
        self::assertFalse($entireArea->suppressed);
        self::assertTrue($entireArea->translateLabel);
        self::assertSame(GeographicSegmentProfileService::ENTIRE_AREA_LABEL, $entireArea->label);
        self::assertSame(12, $entireArea->segmentCases);
        self::assertNull($entireArea->sharePercent);
        self::assertTrue($entireArea->showMedianTransport);
        self::assertNotNull($entireArea->medianTransportMinutes);
        self::assertCount(1, $entireArea->groups);
        self::assertSame('label.urgency.emergency', $entireArea->groups[0]->rows[0]->labelTranslationKey);
        self::assertSame(8, $entireArea->groups[0]->rows[0]->count);
        self::assertSame('bg-red', $entireArea->groups[0]->rows[0]->barClass);

        $origin = $service->build(
            $criteria,
            $catalog,
            GeographicSegment::originArea($home->getId()),
            GeographicSegmentProfileDimension::Demographics,
        );
        self::assertFalse($origin->translateLabel);
        self::assertSame('GeoProfHome', $origin->label);
        self::assertSame(100.0, $origin->sharePercent);
        self::assertCount(2, $origin->groups);
        self::assertSame(8, $origin->groups[0]->rows[0]->count);
        self::assertNotSame([], $origin->groups[1]->rows);

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
        self::assertSame(8, $travel->groups[0]->rows[0]->count);
        self::assertSame('statistics.distribution.dim.requires_resus', $travel->groups[0]->rows[0]->labelTranslationKey);
    }
}

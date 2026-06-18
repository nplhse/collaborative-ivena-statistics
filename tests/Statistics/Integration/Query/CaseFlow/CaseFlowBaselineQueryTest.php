<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\CaseFlow;

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
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowBaselineQuery;
use App\Tests\Statistics\Support\PreciseTransportTimeScenarios;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CaseFlowBaselineQueryTest extends KernelTestCase
{
    use Factories;

    public function testPublicBaselineTransportMetricsUsePreciseTimestampMinutes(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'case-flow-baseline-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CaseFlowBaselineState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CaseFlowBaselineDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CaseFlowBaselineHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CaseFlowBaselineSpec']);
        DepartmentFactory::createOne(['name' => 'CaseFlowBaselineDept']);
        AssignmentFactory::createOne(['name' => 'CaseFlowBaselineAssign']);
        IndicationRawFactory::createOne(['name' => 'CaseFlowBaselineRaw', 'code' => 912_503]);

        $import = ImportFactory::createOne(['name' => 'CaseFlowBaselineImport', 'hospital' => $hospital, 'createdBy' => $user]);

        foreach (PreciseTransportTimeScenarios::allocations() as $times) {
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'createdAt' => $times['createdAt'],
                'arrivalAt' => $times['arrivalAt'],
            ]);
        }

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $baseline = self::getContainer()->get(CaseFlowBaselineQuery::class)->fetchPublicBaseline(null, null);

        self::assertEqualsWithDelta(
            PreciseTransportTimeScenarios::PRECISE_MEDIAN_MINUTES,
            $baseline['medianTransport'],
            0.001,
        );
        self::assertEqualsWithDelta(
            PreciseTransportTimeScenarios::PRECISE_MEAN_MINUTES,
            $baseline['meanTransport'],
            0.001,
        );
        self::assertNotEquals(
            PreciseTransportTimeScenarios::ROUNDED_MINUTES_MEDIAN,
            $baseline['medianTransport'],
        );
    }
}

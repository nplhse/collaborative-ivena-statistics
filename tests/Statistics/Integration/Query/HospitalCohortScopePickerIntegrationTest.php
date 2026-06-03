<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\Tests\Support\MaterializedView\RefreshesStatisticsMaterializedViewsTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class HospitalCohortScopePickerIntegrationTest extends KernelTestCase
{
    use Factories;
    use RefreshesStatisticsMaterializedViewsTrait;
    use ResetDatabase;

    public function testEligibleCohortScopeChoicesShowTranslatedLabels(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'cohort-picker-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CohortPickerState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CohortPickerDispatch', 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'CohortPickerHospitalA',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'CohortPickerHospitalB',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CohortPickerSpec']);
        DepartmentFactory::createOne(['name' => 'CohortPickerDept']);
        AssignmentFactory::createOne(['name' => 'CohortPickerAssign']);
        OccasionFactory::createOne(['name' => 'CohortPickerOcc']);
        SecondaryTransportFactory::createOne(['name' => 'CohortPickerSec']);
        InfectionFactory::createOne(['name' => 'CohortPickerInf']);
        IndicationRawFactory::createOne(['name' => 'CohortPickerRaw', 'code' => 912_348]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'CohortPickerNorm']);

        $importA = ImportFactory::createOne(['name' => 'CohortPickerImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'CohortPickerImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        $allocationDefaults = [
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'age' => 40,
            'requiresResus' => false,
            'requiresCathlab' => false,
            'isCPR' => false,
            'isVentilated' => false,
            'isWorkAccident' => false,
            'isWithPhysician' => false,
            'occasion' => null,
            'infection' => null,
            'indicationNormalized' => $indicationNormalized,
            'createdAt' => new \DateTimeImmutable('2025-04-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-04-01 08:30:00'),
        ];

        AllocationFactory::createOne(array_merge($allocationDefaults, ['import' => $importA, 'hospital' => $hospitalA]));
        AllocationFactory::createOne(array_merge($allocationDefaults, ['import' => $importB, 'hospital' => $hospitalB]));

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());

        $this->refreshStatisticsMaterializedViews();

        $expectedLabel = self::getContainer()->get(HospitalCohortLabelResolver::class)->label(
            HospitalCohortKey::tryFrom('urban_basic'),
        );

        $pageModel = self::getContainer()->get(StatisticsPageViewModelFactory::class)->create(
            new Request(query: ['scope' => 'public', 'period' => 'all']),
            'app_stats_dashboard',
            null,
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::All,
            ),
        );

        $urbanBasicChoice = null;
        foreach ($pageModel->cohortScopeChoices as $choice) {
            if ('urban_basic' === $choice['key']) {
                $urbanBasicChoice = $choice;
                break;
            }
        }

        self::assertNotNull($urbanBasicChoice, 'Expected urban_basic cohort in scope choices.');
        self::assertSame($expectedLabel, $urbanBasicChoice['label']);
        self::assertStringNotContainsString('stats.filter.cohort.', $urbanBasicChoice['label']);
        self::assertStringNotContainsString('urban_basic', $urbanBasicChoice['label']);
    }
}

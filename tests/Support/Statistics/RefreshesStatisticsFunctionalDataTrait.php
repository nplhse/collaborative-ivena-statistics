<?php

declare(strict_types=1);

namespace App\Tests\Support\Statistics;

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
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Infrastructure\MaterializedView\MaterializedViewRefresher;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait RefreshesStatisticsFunctionalDataTrait
{
    /**
     * @param list<int> $importIds
     */
    protected function rebuildProjectionForImports(array $importIds): void
    {
        $container = static::getContainer();
        $container->get(Connection::class)->executeStatement('TRUNCATE TABLE allocation_stats_projection');
        $rebuilder = $container->get(AllocationStatsProjectionRebuildInterface::class);

        foreach ($importIds as $importId) {
            $rebuilder->rebuildForImport($importId);
        }
    }

    protected function refreshOverviewMaterializedViews(): void
    {
        static::getContainer()->get(MaterializedViewRefresher::class)->refresh(
            [StatisticsMaterializedViewGroups::OVERVIEW],
            concurrently: false,
        );
    }

    protected function seedEligibleUrbanBasicCohort(KernelBrowser $client): void
    {
        $user = UserFactory::createOne(['username' => 'dashboard-cohort-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'Dashboard Cohort State']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Dashboard Cohort Dispatch']);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'Dashboard Cohort Hospital A',
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'Dashboard Cohort Hospital B',
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::URBAN,
        ]);
        SpecialityFactory::createOne(['name' => 'Dashboard Cohort Spec']);
        DepartmentFactory::createOne(['name' => 'Dashboard Cohort Dept']);
        AssignmentFactory::createOne(['name' => 'Dashboard Cohort Assign']);
        OccasionFactory::createOne(['name' => 'Dashboard Cohort Occ']);
        InfectionFactory::createOne(['name' => 'Dashboard Cohort Inf']);
        $raw = IndicationRawFactory::createOne(['name' => 'Dashboard Cohort Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Dashboard Cohort Norm']);
        $importA = ImportFactory::createOne(['name' => 'Dashboard Cohort Import A', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'Dashboard Cohort Import B', 'hospital' => $hospitalB, 'createdBy' => $user]);

        foreach ([[$importA, $hospitalA], [$importB, $hospitalB]] as [$import, $hospital]) {
            AllocationFactory::createOne([
                'createdAt' => new \DateTimeImmutable('today'),
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'indicationRaw' => $raw,
                'indicationNormalized' => $normalized,
            ]);
        }

        $this->rebuildProjectionForImports([(int) $importA->getId(), (int) $importB->getId()]);
        $this->refreshOverviewMaterializedViews();
    }
}

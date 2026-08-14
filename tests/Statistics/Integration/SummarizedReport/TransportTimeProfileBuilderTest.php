<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\SummarizedReport;

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
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileView;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\TransportTimeProfileBuilder;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\TransportTimeProfileReportType;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;

final class TransportTimeProfileBuilderTest extends DatabaseKernelTestCase
{
    public function testEmptySliceProducesEmptyView(): void
    {
        $view = $this->builder()->build($this->publicJuneContext(), 'en');

        self::assertFalse($view->hasData);
        self::assertSame(0, $view->allocationCount);
        self::assertSame([], $view->matrixSections);
        self::assertSame([], $view->rankedSections);
        self::assertSame([], $view->chartSpecs);
        self::assertSame([], $view->insights);
        self::assertStringContainsString('transport-time-bucket-distribution', $view->explorerTransportTimeUrl);
    }

    public function testBuildPartitionsCompositionAndRankedSections(): void
    {
        $this->seedJuneAllocations();

        $type = self::getContainer()->get(TransportTimeProfileReportType::class);
        $result = $type->build($this->publicJuneContext(), 'en');
        $view = $result->viewModel;

        self::assertSame('transport_time_profile', $type->key());
        self::assertSame('stats.reports.types.transport_time_profile.label', $type->labelTranslationKey());
        self::assertTrue($type->supports($this->publicJuneContext()->filter));
        self::assertSame('@Statistics/reports/types/transport_time_profile.html.twig', $result->template);
        self::assertInstanceOf(TransportTimeProfileView::class, $view);
        self::assertTrue($view->hasData);
        self::assertGreaterThan(0, $view->knownTransportCount);
        self::assertSame(1, $view->unknownTransportCount);
        self::assertNotEmpty($view->chartSpecs['cases']['counts']);
        self::assertContains('volume', array_map(static fn (\App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileMatrixSection $section): string => $section->key, $view->matrixSections));
        self::assertContains('resources', array_map(static fn (\App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileMatrixSection $section): string => $section->key, $view->matrixSections));
        self::assertSame(['departments', 'specialities', 'indications'], array_map(
            static fn (\App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileMatrixSection $section): string => $section->key,
            $view->rankedSections,
        ));
        self::assertStringContainsString('transport-time-bucket-distribution', $view->explorerTransportTimeUrl);
        self::assertStringContainsString('year=2025', $view->explorerTransportTimeUrl);
        self::assertStringContainsString('month=6', $view->dashboardUrl);
    }

    private function builder(): TransportTimeProfileBuilder
    {
        return self::getContainer()->get(TransportTimeProfileBuilder::class);
    }

    private function publicJuneContext(): StatisticsContext
    {
        return new StatisticsContext(
            null,
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::Month,
                2025,
                6,
            ),
        );
    }

    private function seedJuneAllocations(): void
    {
        $user = UserFactory::createOne(['username' => 'ttp-builder-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'TtpBuilderState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'TtpBuilderDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'TtpBuilderHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $department = DepartmentFactory::createOne(['name' => 'TtpBuilderDept']);
        SpecialityFactory::createOne(['name' => 'TtpBuilderSpec']);
        AssignmentFactory::createOne(['name' => 'TtpBuilderAssign']);
        IndicationRawFactory::createOne(['name' => 'TtpBuilderRaw', 'code' => 912_360]);
        IndicationNormalizedFactory::createOne(['name' => 'TtpBuilderNorm']);
        $import = ImportFactory::createOne(['name' => 'TtpBuilderImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'transportType' => AllocationTransportType::GROUND,
            'department' => $department,
            'createdAt' => new \DateTimeImmutable('2025-06-15 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 10:05:00'),
        ]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::AIR,
            'department' => $department,
            'createdAt' => new \DateTimeImmutable('2025-06-15 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 12:10:00'),
        ]);
        $unknown = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'createdAt' => new \DateTimeImmutable('2025-06-15 13:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 13:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)
            ->rebuildForImport($import->getId());
        self::getContainer()->get(Connection::class)->update(
            'allocation_stats_projection',
            ['transport_time_minutes' => -1],
            ['id' => $unknown->getId()],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Support;

use App\Statistics\AnalysisExplorer\Application\AllocationsAnalysisRunner;
use App\Statistics\AnalysisExplorer\Application\AllocationsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\Application\AnalysisAxisUpgradeMapper;
use App\Statistics\AnalysisExplorer\Application\AnalysisDimensionLabelResolver;
use App\Statistics\AnalysisExplorer\Application\AnalysisTotalsCalculator;
use App\Statistics\AnalysisExplorer\Application\DataSourceCapabilitiesRegistry;
use App\Statistics\AnalysisExplorer\Application\ExplorerAllocationAnalysisExecutor;
use App\Statistics\AnalysisExplorer\Application\ExplorerAllocationQueryMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerAllocationResultMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerChartPresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerHospitalQueryMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricCapabilityPolicy;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricCatalog;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricKeyMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricProfileRegistry;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricSummabilityPolicy;
use App\Statistics\AnalysisExplorer\Application\ExplorerQueryMapperRegistry;
use App\Statistics\AnalysisExplorer\Application\ExplorerResultsTablePresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerTablePercentHelper;
use App\Statistics\AnalysisExplorer\Application\HospitalsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\Application\HospitalsDistributionResultMapper;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Infrastructure\Query\AllocationsCountQuery;
use App\Statistics\AnalysisExplorer\Infrastructure\Query\AllocationsDistributionQuery;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\GenericAnalysis\Application\ChartPrimaryBucketLimiter;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisDimensionPolicy;
use App\Statistics\GenericAnalysis\Application\HospitalPopulationModifier;
use App\Statistics\GenericAnalysis\Application\MetricCompatibilityChecker;
use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Application\RelativeDistributionCalculator;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisQuery;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisSqlBuilder;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationDistributionSqlBuilder;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use App\Statistics\HospitalPopulation\Application\DescriptiveStatisticsCalculator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @mixin TestCase
 */
trait AnalysisExplorerTestSupport
{
    protected function createAllocationsCapabilitiesProvider(): AllocationsCapabilitiesProvider
    {
        return new AllocationsCapabilitiesProvider(
            $this->createDimensionPolicy(),
            $this->createExplorerMetricCatalog(),
            $this->createExplorerMetricCapabilityPolicy(),
        );
    }

    protected function createHospitalsCapabilitiesProvider(): HospitalsCapabilitiesProvider
    {
        return new HospitalsCapabilitiesProvider(
            $this->createExplorerMetricCatalog(),
            $this->createExplorerMetricCapabilityPolicy(),
        );
    }

    protected function createDataSourceCapabilitiesRegistry(): DataSourceCapabilitiesRegistry
    {
        return new DataSourceCapabilitiesRegistry([
            $this->createAllocationsCapabilitiesProvider(),
            $this->createHospitalsCapabilitiesProvider(),
        ]);
    }

    protected function createDimensionPolicy(): GenericAnalysisDimensionPolicy
    {
        return new GenericAnalysisDimensionPolicy(
            $this->createAdminHospitalAccess(),
            new DimensionRegistry(),
        );
    }

    protected function createAdminHospitalAccess(): HospitalAccessInterface&MockObject
    {
        $hospitalAccess = $this->createMock(HospitalAccessInterface::class);
        $hospitalAccess->method('isAdminHospitalScopeUser')->willReturn(true);

        return $hospitalAccess;
    }

    protected function createSecurityWithoutUser(): Security&MockObject
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        return $security;
    }

    protected function createMetricRegistry(): MetricRegistry
    {
        return new MetricRegistry();
    }

    protected function createExplorerMetricKeyMapper(): ExplorerMetricKeyMapper
    {
        return new ExplorerMetricKeyMapper();
    }

    protected function createMetricCompatibilityChecker(): MetricCompatibilityChecker
    {
        return new MetricCompatibilityChecker(
            $this->createMetricRegistry(),
            new DimensionRegistry(),
        );
    }

    protected function createExplorerMetricCatalog(): ExplorerMetricCatalog
    {
        return new ExplorerMetricCatalog($this->createMetricRegistry());
    }

    protected function createExplorerAllocationQueryMapper(): ExplorerAllocationQueryMapper
    {
        return new ExplorerAllocationQueryMapper($this->createExplorerMetricKeyMapper());
    }

    protected function createExplorerMetricProfileRegistry(): ExplorerMetricProfileRegistry
    {
        return new ExplorerMetricProfileRegistry();
    }

    protected function createExplorerMetricCapabilityPolicy(): ExplorerMetricCapabilityPolicy
    {
        return new ExplorerMetricCapabilityPolicy(
            $this->createExplorerMetricCatalog(),
            $this->createExplorerQueryMapperRegistry(),
            $this->createMetricCompatibilityChecker(),
            $this->createExplorerMetricProfileRegistry(),
        );
    }

    protected function createExplorerHospitalQueryMapper(): ExplorerHospitalQueryMapper
    {
        return new ExplorerHospitalQueryMapper(
            $this->createExplorerMetricKeyMapper(),
            new HospitalPopulationModifier(),
        );
    }

    protected function createExplorerQueryMapperRegistry(): ExplorerQueryMapperRegistry
    {
        return new ExplorerQueryMapperRegistry([
            $this->createExplorerAllocationQueryMapper(),
            $this->createExplorerHospitalQueryMapper(),
        ]);
    }

    protected function createExplorerChartPresenter(): ExplorerChartPresenter
    {
        $metricRegistry = $this->createMetricRegistry();
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'stats.analysis_explorer.dimension.month' => 'Month',
                'stats.analysis_explorer.dimension.gender' => 'Gender',
                'stats.generic_analysis.chart.remainder_bucket' => 'Other',
                default => $id,
            },
        );

        return new ExplorerChartPresenter(
            $this->createExplorerMetricKeyMapper(),
            $metricRegistry,
            new ChartPrimaryBucketLimiter($translator),
            $translator,
            $this->createExplorerMetricProfileRegistry(),
        );
    }

    protected function createAnalysisTotalsCalculator(): AnalysisTotalsCalculator
    {
        return new AnalysisTotalsCalculator($this->createExplorerMetricSummabilityPolicy());
    }

    protected function createExplorerMetricSummabilityPolicy(): ExplorerMetricSummabilityPolicy
    {
        return new ExplorerMetricSummabilityPolicy();
    }

    /**
     * @return array{0: AnalysisAxisRef, 1: ?AnalysisAxisRef}
     */
    protected function axesFromLegacy(AnalysisDimensionKey $dimension, AnalysisDimensionGrain $grain): array
    {
        return new AnalysisAxisUpgradeMapper()->fromLegacyDimension($dimension, $grain);
    }

    protected function createAllocationsAnalysisRunner(
        Connection $connection,
        TranslatorInterface $translator,
    ): AllocationsAnalysisRunner {
        return new AllocationsAnalysisRunner(
            $this->createAllocationsCountQuery($connection, $translator),
            $this->createAllocationsCapabilitiesProvider(),
            $this->createAnalysisTotalsCalculator(),
        );
    }

    protected function createExplorerResultsTablePresenter(TranslatorInterface $translator): ExplorerResultsTablePresenter
    {
        $metricRegistry = $this->createMetricRegistry();
        $metricValueFormatter = new MetricValueFormatter($metricRegistry);

        return new ExplorerResultsTablePresenter(
            $translator,
            $this->createExplorerMetricKeyMapper(),
            $metricRegistry,
            $metricValueFormatter,
            new ExplorerTablePercentHelper($metricRegistry, $metricValueFormatter),
            $this->createExplorerMetricSummabilityPolicy(),
            $this->createExplorerMetricProfileRegistry(),
        );
    }

    protected function createAllocationsCountQuery(
        Connection $connection,
        TranslatorInterface $translator,
    ): AllocationsCountQuery {
        $dimensionRegistry = new DimensionRegistry();
        $metricRegistry = $this->createMetricRegistry();
        $entityLabelResolver = $this->createMock(GenericAnalysisEntityLabelResolverInterface::class);
        $entityLabelResolver->method('supports')->willReturn(false);

        $labelResolver = new AnalysisDimensionLabelResolver(
            $translator,
            $entityLabelResolver,
            new HospitalCohortLabelResolver($translator),
        );

        $profileRegistry = $this->createExplorerMetricProfileRegistry();
        $distributionResultMapper = new HospitalsDistributionResultMapper(
            $dimensionRegistry,
            $labelResolver,
            new DescriptiveStatisticsCalculator(),
            $profileRegistry,
        );

        $executor = new ExplorerAllocationAnalysisExecutor(
            $this->createExplorerQueryMapperRegistry(),
            new GenericAllocationAnalysisQuery(
                $connection,
                new GenericAllocationAnalysisSqlBuilder(
                    $dimensionRegistry,
                    $metricRegistry,
                    new GenericAnalysisScopeSqlFilter(),
                ),
                $metricRegistry,
            ),
            $this->createMetricCompatibilityChecker(),
            new RelativeDistributionCalculator(),
            new ExplorerAllocationResultMapper(
                $dimensionRegistry,
                $labelResolver,
                $this->createExplorerMetricKeyMapper(),
            ),
            new AllocationsDistributionQuery(
                $connection,
                $this->createExplorerQueryMapperRegistry(),
                new GenericAllocationDistributionSqlBuilder(
                    $dimensionRegistry,
                    new GenericAnalysisScopeSqlFilter(),
                ),
                $distributionResultMapper,
                $profileRegistry,
            ),
        );

        return new AllocationsCountQuery($executor);
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>, 2: ?string, 3: ?string, 4: string}> $messages
     */
    protected function stubExplorerTranslator(array $messages = []): TranslatorInterface
    {
        $defaults = [
            ['stats.analysis_explorer.table.footer_total', [], null, null, 'Total'],
            ['stats.analysis_explorer.table.footer_average', [], null, null, 'Ø'],
            ['stats.analysis_explorer.table.footer_minimum', [], null, null, 'Min.'],
            ['stats.analysis_explorer.table.footer_maximum', [], null, null, 'Max.'],
        ];

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap(array_merge($defaults, $messages));

        return $translator;
    }
}

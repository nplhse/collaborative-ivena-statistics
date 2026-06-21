<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Support;

use App\Statistics\AnalysisExplorer\Application\AllocationsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\Application\AnalysisDimensionLabelResolver;
use App\Statistics\AnalysisExplorer\Application\ExplorerAllocationQueryMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerAllocationResultMapper;
use App\Statistics\AnalysisExplorer\Infrastructure\Query\AllocationsCountQuery;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisDimensionPolicy;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisQuery;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisSqlBuilder;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
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
        return new AllocationsCapabilitiesProvider($this->createDimensionPolicy());
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

    protected function createAllocationsCountQuery(
        Connection $connection,
        TranslatorInterface $translator,
    ): AllocationsCountQuery {
        $dimensionRegistry = new DimensionRegistry();
        $metricRegistry = new MetricRegistry();
        $entityLabelResolver = $this->createMock(GenericAnalysisEntityLabelResolverInterface::class);
        $entityLabelResolver->method('supports')->willReturn(false);

        $labelResolver = new AnalysisDimensionLabelResolver(
            $translator,
            $entityLabelResolver,
            new HospitalCohortLabelResolver($translator),
        );

        return new AllocationsCountQuery(
            new ExplorerAllocationQueryMapper(),
            new GenericAllocationAnalysisQuery(
                $connection,
                new GenericAllocationAnalysisSqlBuilder(
                    $dimensionRegistry,
                    $metricRegistry,
                    new GenericAnalysisScopeSqlFilter(),
                ),
                $metricRegistry,
            ),
            new ExplorerAllocationResultMapper($dimensionRegistry, $labelResolver),
        );
    }
}

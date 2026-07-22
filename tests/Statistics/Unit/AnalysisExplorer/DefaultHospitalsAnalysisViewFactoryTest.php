<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultHospitalsAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerTitleFactory;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DefaultHospitalsAnalysisViewFactoryTest extends TestCase
{
    public function testCreateDefaultReturnsParticipatingCohortByHospitalCount(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Hospitals by master cohort');

        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        $factory = new DefaultHospitalsAnalysisViewFactory(new ExplorerTitleFactory($translator));
        $config = $factory->createDefault($filter);

        self::assertSame(AnalysisDataSourceKey::Hospitals, $config->dataSourceKey);
        self::assertSame(AnalysisMetricKey::HospitalCount, $config->visualMetricKey);
        self::assertSame([AnalysisMetricKey::HospitalCount], $config->metricKeys);
        self::assertSame(AnalysisDimensionKey::HospitalMasterCohort, $config->rowAxis->dimensionKey);
        self::assertNull($config->columnAxis);
        self::assertSame(ExplorerHospitalPopulationMode::Participating, $config->hospitalPopulationMode);
        self::assertSame(ChartPresentationType::Bar, $config->presentation->chartType);
        self::assertSame($filter, $config->statisticsFilter);
    }
}

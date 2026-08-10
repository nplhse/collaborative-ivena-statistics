<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Allocation\Application\Contracts\DispatchAreaLookupInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisSummaryLabelResolver;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsHospitalScopeLabelResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerAnalysisSummaryLabelResolverTest extends TestCase
{
    public function testPublicScopeUsesNarrativeAllHospitalsLabel(): void
    {
        $resolver = $this->resolver([
            'stats.analysis_explorer.summary.scope.all_hospitals' => 'all hospitals',
            'stats.filter.period.all' => 'Last 12 months',
        ]);

        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        self::assertSame('all hospitals', $resolver->scopeLabel($filter, null));
        self::assertSame('Last 12 months', $resolver->periodLabel($filter));
    }

    public function testYearPeriodUsesReferenceYear(): void
    {
        $resolver = $this->resolver([]);

        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::Year,
            referenceYear: 2025,
        );

        self::assertSame('2025', $resolver->periodLabel($filter));
    }

    /**
     * @param array<string, string> $map
     */
    private function resolver(array $map): ExplorerAnalysisSummaryLabelResolver
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []) use ($map): string {
                $template = $map[$id] ?? $id;

                return strtr($template, array_combine(
                    array_map(static fn (string|int $key): string => '{'.$key.'}', array_keys($parameters)),
                    array_map(static fn (mixed $value): string => (string) $value, array_values($parameters)),
                ));
            },
        );

        return new ExplorerAnalysisSummaryLabelResolver(
            $translator,
            new StatisticsHospitalScopeLabelResolver(
                $this->createStub(HospitalAccessInterface::class),
                $translator,
            ),
            new HospitalCohortLabelResolver($translator),
            $this->createStub(HospitalAccessInterface::class),
            $this->createStub(HospitalLookupInterface::class),
            $this->createStub(StateLookupInterface::class),
            $this->createStub(DispatchAreaLookupInterface::class),
        );
    }
}

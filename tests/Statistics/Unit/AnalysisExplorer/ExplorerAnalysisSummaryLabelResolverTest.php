<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Allocation\Application\Contracts\DispatchAreaLookupInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisSummaryLabelResolver;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsHospitalScopeLabelResolver;
use App\User\Domain\Entity\User;
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

    public function testMyHospitalsFallsBackToAllHospitalsWhenUserHasNone(): void
    {
        $user = $this->createStub(User::class);
        $hospitalAccess = $this->createStub(HospitalAccessInterface::class);
        $hospitalAccess->method('countAccessibleHospitals')->willReturn(0);

        $resolver = $this->resolver(
            ['stats.analysis_explorer.summary.scope.all_hospitals' => 'all hospitals'],
            hospitalAccess: $hospitalAccess,
        );

        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::MyHospitals,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        self::assertSame('all hospitals', $resolver->scopeLabel($filter, $user));
    }

    public function testMyHospitalsUsesGroupLabelWhenUserHasHospitals(): void
    {
        $user = $this->createStub(User::class);
        $hospitalAccess = $this->createStub(HospitalAccessInterface::class);
        $hospitalAccess->method('countAccessibleHospitals')->willReturn(2);

        $resolver = $this->resolver(
            ['stats.filter.scope.my_hospitals' => 'My hospitals'],
            hospitalAccess: $hospitalAccess,
        );

        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::MyHospitals,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        self::assertSame('My hospitals', $resolver->scopeLabel($filter, $user));
    }

    public function testHospitalScopeUsesLookupNameAndFallback(): void
    {
        $hospital = $this->createStub(Hospital::class);
        $hospital->method('getName')->willReturn('Klinikum A');
        $hospitalLookup = $this->createStub(HospitalLookupInterface::class);
        $hospitalLookup->method('findById')->willReturnMap([
            [17, $hospital],
            [99, null],
        ]);

        $resolver = $this->resolver(
            ['stats.filter.hospital.choose' => 'Choose hospital'],
            hospitalLookup: $hospitalLookup,
        );

        self::assertSame(
            'Klinikum A',
            $resolver->scopeLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::Hospital,
                hospitalId: 17,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ), null),
        );
        self::assertSame(
            'Choose hospital',
            $resolver->scopeLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::Hospital,
                hospitalId: 99,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ), null),
        );
        self::assertSame(
            'Choose hospital',
            $resolver->scopeLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::Hospital,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ), null),
        );
    }

    public function testHospitalCohortScopeUsesResolverAndFallback(): void
    {
        $resolver = $this->resolver([
            'stats.filter.cohort.label' => '{location} · {tier}',
            'stats.filter.cohort.location.Urban' => 'Urban',
            'stats.filter.cohort.tier.Basic' => 'Basic',
            'stats.filter.scope.hospital_cohort' => 'Hospital cohort',
        ]);

        self::assertSame(
            'Urban · Basic',
            $resolver->scopeLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::HospitalCohort,
                hospitalId: null,
                cohortType: new HospitalCohortKey(HospitalLocation::URBAN, HospitalTier::BASIC),
                period: StatisticsFilterPeriod::All,
            ), null),
        );
        self::assertSame(
            'Hospital cohort',
            $resolver->scopeLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::HospitalCohort,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ), null),
        );
    }

    public function testStateAndDispatchAreaScopesUseLookupNamesAndFallbacks(): void
    {
        $state = $this->createStub(State::class);
        $state->method('getName')->willReturn('Hessen');
        $stateLookup = $this->createStub(StateLookupInterface::class);
        $stateLookup->method('findById')->willReturnMap([
            [3, $state],
            [9, null],
        ]);

        $dispatch = $this->createStub(DispatchArea::class);
        $dispatch->method('getName')->willReturn('Nordhessen');
        $dispatchLookup = $this->createStub(DispatchAreaLookupInterface::class);
        $dispatchLookup->method('findById')->willReturnMap([
            [5, $dispatch],
            [8, null],
        ]);

        $resolver = $this->resolver(
            [
                'stats.filter.scope.state' => 'State',
                'stats.filter.scope.dispatch_area' => 'Dispatch area',
            ],
            stateLookup: $stateLookup,
            dispatchAreaLookup: $dispatchLookup,
        );

        self::assertSame(
            'Hessen',
            $resolver->scopeLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::State,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
                stateId: 3,
            ), null),
        );
        self::assertSame(
            'State',
            $resolver->scopeLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::State,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
                stateId: 9,
            ), null),
        );
        self::assertSame(
            'Nordhessen',
            $resolver->scopeLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::DispatchArea,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
                dispatchAreaId: 5,
            ), null),
        );
        self::assertSame(
            'Dispatch area',
            $resolver->scopeLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::DispatchArea,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
                dispatchAreaId: null,
            ), null),
        );
    }

    public function testPeriodLabelsCoverAllModes(): void
    {
        $resolver = $this->resolver([
            'stats.filter.period.all' => 'Last 12 months',
            'stats.filter.period.all_time' => 'All time',
            'stats.dashboard.heading.quarter' => 'Q{quarter} {year}',
        ]);

        self::assertSame(
            'Last 12 months',
            $resolver->periodLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            )),
        );
        self::assertSame(
            'All time',
            $resolver->periodLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::AllTime,
            )),
        );
        self::assertSame(
            '2025',
            $resolver->periodLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::Year,
                referenceYear: 2025,
            )),
        );
        self::assertSame(
            'Q2 2024',
            $resolver->periodLabel(new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::Quarter,
                referenceYear: 2024,
                referenceQuarter: 2,
            )),
        );

        $monthLabel = $resolver->periodLabel(new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::Month,
            referenceYear: 2025,
            referenceMonth: 3,
        ));
        self::assertNotSame('', $monthLabel);
        self::assertStringContainsString('2025', $monthLabel);
    }

    public function testYearPeriodWithoutReferenceUsesCurrentYear(): void
    {
        $resolver = $this->resolver([]);
        $label = $resolver->periodLabel(new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::Year,
        ));

        self::assertSame(new \DateTimeImmutable()->format('Y'), $label);
    }

    /**
     * @param array<string, string> $map
     */
    private function resolver(
        array $map,
        ?HospitalAccessInterface $hospitalAccess = null,
        ?HospitalLookupInterface $hospitalLookup = null,
        ?StateLookupInterface $stateLookup = null,
        ?DispatchAreaLookupInterface $dispatchAreaLookup = null,
    ): ExplorerAnalysisSummaryLabelResolver {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null) use ($map): string {
                $template = $map[$id] ?? $id;

                if ([] === $parameters) {
                    return $template;
                }

                return strtr($template, array_combine(
                    array_map(static fn (string|int $key): string => '{'.$key.'}', array_keys($parameters)),
                    array_map(static fn (mixed $value): string => (string) $value, array_values($parameters)),
                ));
            },
        );

        $access = $hospitalAccess ?? $this->createStub(HospitalAccessInterface::class);

        return new ExplorerAnalysisSummaryLabelResolver(
            $translator,
            new StatisticsHospitalScopeLabelResolver($access, $translator),
            new HospitalCohortLabelResolver($translator),
            $access,
            $hospitalLookup ?? $this->createStub(HospitalLookupInterface::class),
            $stateLookup ?? $this->createStub(StateLookupInterface::class),
            $dispatchAreaLookup ?? $this->createStub(DispatchAreaLookupInterface::class),
        );
    }
}

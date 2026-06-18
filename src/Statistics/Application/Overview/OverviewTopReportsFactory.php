<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\Overview\Dto\OverviewTopReportCard;
use App\Statistics\Application\Overview\Dto\OverviewTopTableRow;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\Statistics\Infrastructure\Query\Overview\OverviewTopEntitiesBatchQuery;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class OverviewTopReportsFactory
{
    private const int TOP_LIMIT = 5;

    public function __construct(
        private OverviewTopEntitiesBatchQuery $batchQuery,
        private StatisticsScopeResolver $scopeResolver,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    /**
     * @return list<OverviewTopReportCard>
     */
    public function build(Request $request, StatisticsContext $context, int $scopedTotal): array
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $hospitalIds = $this->scopeResolver->resolveCriteria($context)->hospitalIds;
        $batch = ($this->batchQuery)(
            OverviewQueryCriteria::fromPeriodBounds($bounds, $hospitalIds),
            self::TOP_LIMIT,
        );

        $cards = [];

        foreach ($this->sections() as $section) {
            $rows = [];
            $rank = 1;

            foreach ($batch[$section['batchKey']] as $row) {
                $count = $row['count'];
                $share = $scopedTotal > 0 ? round(100 * $count / $scopedTotal, 1) : 0.0;
                $rows[] = new OverviewTopTableRow(
                    $rank,
                    $row['label'],
                    $count,
                    sprintf('%.1f%%', $share),
                );
                ++$rank;
            }

            $cards[] = new OverviewTopReportCard(
                $section['titleTranslationKey'],
                $section['labelColumnTranslationKey'],
                $this->navigationUrlBuilder->build(
                    $request,
                    'app_stats_reports',
                    ['report' => $section['reportKey']],
                ),
                $section['testId'],
                $rows,
            );
        }

        return $cards;
    }

    /**
     * @return list<array{
     *     reportKey: string,
     *     batchKey: string,
     *     titleTranslationKey: string,
     *     labelColumnTranslationKey: string,
     *     testId: string
     * }>
     */
    private function sections(): array
    {
        return [
            [
                'reportKey' => 'top_specialities',
                'batchKey' => OverviewTopEntitiesBatchQuery::DIMENSION_SPECIALITY,
                'titleTranslationKey' => 'stats.reports.top_specialities.label',
                'labelColumnTranslationKey' => 'stats.reports.table.speciality',
                'testId' => 'stats-overview-top-specialities',
            ],
            [
                'reportKey' => 'top_departments',
                'batchKey' => OverviewTopEntitiesBatchQuery::DIMENSION_DEPARTMENT,
                'titleTranslationKey' => 'stats.reports.top_departments.label',
                'labelColumnTranslationKey' => 'stats.reports.table.department',
                'testId' => 'stats-overview-top-departments',
            ],
            [
                'reportKey' => 'top_assignments',
                'batchKey' => OverviewTopEntitiesBatchQuery::DIMENSION_ASSIGNMENT,
                'titleTranslationKey' => 'stats.reports.top_assignments.label',
                'labelColumnTranslationKey' => 'stats.reports.table.assignment',
                'testId' => 'stats-overview-top-assignments',
            ],
            [
                'reportKey' => 'top_occasions',
                'batchKey' => OverviewTopEntitiesBatchQuery::DIMENSION_OCCASION,
                'titleTranslationKey' => 'stats.reports.top_occasions.label',
                'labelColumnTranslationKey' => 'stats.reports.table.occasion',
                'testId' => 'stats-overview-top-occasions',
            ],
            [
                'reportKey' => 'top_infections',
                'batchKey' => OverviewTopEntitiesBatchQuery::DIMENSION_INFECTION,
                'titleTranslationKey' => 'stats.reports.top_infections.label',
                'labelColumnTranslationKey' => 'stats.reports.table.infection',
                'testId' => 'stats-overview-top-infections',
            ],
            [
                'reportKey' => 'top_secondary_diagnoses',
                'batchKey' => OverviewTopEntitiesBatchQuery::DIMENSION_SECONDARY_DIAGNOSIS,
                'titleTranslationKey' => 'stats.reports.top_secondary_diagnoses.label',
                'labelColumnTranslationKey' => 'stats.reports.table.secondary_diagnosis',
                'testId' => 'stats-overview-top-secondary-indications',
            ],
        ];
    }
}

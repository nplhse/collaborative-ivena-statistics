<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;

final class AnalysisExplorerShellEmptyReasonTest extends AnalysisExplorerShellTestCase
{
    public function testMissingSourceDataAndFiltersChooseDistinctEmptyReasons(): void
    {
        $testComponent = $this->createShellComponent();
        $render = $testComponent->render();

        self::assertStringContainsString('No allocations have been imported yet', $render->crawler()->html());
        self::assertGreaterThan(0, $render->crawler()->filter('a[href="/"][target="_top"]')->count());
        self::assertCount(0, $render->crawler()->filter('a[href="/import/new"]'));

        $stillEmpty = $testComponent->call('clearAnalysisFilters')->render();
        self::assertStringContainsString('No allocations have been imported yet', $stillEmpty->crawler()->html());

        $testComponent->call('openEdit');
        $formName = $this->formName($testComponent->render());
        $filtered = $testComponent
            ->submitForm($this->formPayload($formName, [
                'filterUrgency' => '1',
            ]))
            ->call('applyEdit')
            ->render();

        self::assertNotSame([], $testComponent->component()->appliedConfigState['query']['filters'] ?? []);
        self::assertStringContainsString('No results for the current filters', $filtered->crawler()->html());
        self::assertStringContainsString('Reset filters', $filtered->crawler()->filter('.empty-action')->text());

        $cleared = $testComponent->call('clearAnalysisFilters')->render();

        self::assertSame([], $testComponent->component()->appliedConfigState['query']['filters']);
        self::assertStringContainsString('No allocations have been imported yet', $cleared->crawler()->html());
    }

    public function testEmptyPeriodWithImportedDataIsNotAMissingSource(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement(<<<'SQL'
            INSERT INTO allocation_stats_projection (
                id, import_id, hospital_id, state_id, dispatch_area_id,
                speciality_id, department_id, assignment_id,
                created_at, arrival_at,
                created_year, created_quarter, created_month, created_week, created_day, created_weekday, created_hour,
                day_time_bucket_code, shift_bucket_code, transport_time_minutes, urgency_code
            ) VALUES (
                1, 1, 7, 1, 3,
                1, 1, 1,
                '2024-03-01 08:00:00', '2024-03-01 08:30:00',
                2024, 1, 3, 9, 1, 5, 8,
                1, 1, 30, 1
            )
            SQL);

        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        self::assertInstanceOf(ExplorerConfigMapper::class, $mapper);
        self::assertInstanceOf(DefaultAnalysisViewFactory::class, $viewFactory);

        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::Month,
            referenceYear: 1999,
            referenceMonth: 1,
        );
        $user = UserFactory::createOne(['username' => 'explorer-empty-period-'.bin2hex(random_bytes(4))]);
        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($viewFactory->createDefault($filter)),
            'locale' => 'en',
            'libraryUrl' => '/statistics/analysis/library',
        ])->actingAs($user);

        $render = $testComponent->render();

        self::assertStringContainsString('No data available for the selected scope and period.', $render->crawler()->html());
        self::assertStringContainsString('Change period or scope', $render->crawler()->filter('.empty-action')->text());
        self::assertCount(0, $render->crawler()->filter('a[href="/import/new"]'));
    }
}

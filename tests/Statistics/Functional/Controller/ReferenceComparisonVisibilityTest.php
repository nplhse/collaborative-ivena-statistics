<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Statistics\AnalysisExplorer\Application\Comparison\ReferenceComparisonDecision;
use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewLoader;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Saved and public views and the CSV export render the comparison that is effective for the current viewer (#1346).
 * An authorised viewer sees the reference with its name; an unauthorised viewer gets neither the reference id nor
 * its name in the page state or the download. The tests check the relevant state fields and CSV columns, not the whole
 * body, because numeric hospital ids also occur as ordinary counts.
 */
#[ResetDatabase]
final class ReferenceComparisonVisibilityTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    private int $reference;

    private User $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $hospitals = [];
        foreach (['Reference Clinic', 'Clinic B', 'Clinic C', 'Clinic D', 'Clinic E'] as $name) {
            $hospitals[] = (int) HospitalFactory::createOne(['name' => $name])->getId();
        }
        $this->reference = $hospitals[0];
        $this->owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $this->seedProjection($hospitals);
    }

    public function testAnAuthorisedViewerSeesTheComparisonInThePublicView(): void
    {
        $viewer = $this->participantWithBenchmarking();
        $result = $this->loader()->load((string) $this->savePublicView()->getId(), $this->filter(), $viewer, null, true);

        self::assertSame([$this->reference], $result->state['query']['referenceHospitals']);
        self::assertContains(AnalysisMetricKey::PercentOfSeries->value, $result->state['query']['metrics']);
        self::assertSame([], $result->warnings);
    }

    public function testAnUnauthorisedViewerGetsTheViewWithoutTheReference(): void
    {
        $view = $this->savePublicView();
        $viewer = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        $result = $this->loader()->load((string) $view->getId(), $this->filter(), $viewer, null, true);

        self::assertSame([], $result->state['query']['referenceHospitals']);
        self::assertNotContains(AnalysisMetricKey::PercentOfSeries->value, $result->state['query']['metrics']);
        self::assertSame([ReferenceComparisonDecision::NOTICE_KEY], $result->warnings);

        $stored = self::getContainer()->get(SavedExplorerViewRepository::class)->find($view->getId());
        self::assertSame([$this->reference], $stored?->getConfigJson()['query']['referenceHospitals'] ?? null, 'The stored view keeps its comparison.');
    }

    public function testThePageStateOfAnUnauthorisedViewerHoldsNoReference(): void
    {
        $view = $this->savePublicView();
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));

        $crawler = $this->client->request(Request::METHOD_GET, '/statistics/analysis/explorer/'.$view->getId());

        self::assertResponseIsSuccessful();
        $exportState = json_decode((string) $crawler->filter('input[name="appliedConfigState"]')->attr('value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], $exportState['query']['referenceHospitals']);
        self::assertNotContains(AnalysisMetricKey::PercentOfSeries->value, $exportState['query']['metrics']);
        $table = $crawler->filter('[data-testid="stats-analysis-explorer-table"]');
        self::assertCount(1, $table, 'The view without the comparison still shows its results.');
        $rows = $crawler->filter('[data-testid="stats-analysis-explorer-table-body"] tr')->each(static fn ($row): string => (string) preg_replace('/\s+/', ' ', trim($row->text(''))));
        self::assertSame(['Indication Allocations', '101 5', '102 5', 'Total 10'], $rows, 'Every case counts once, without group rows.');
        self::assertStringNotContainsString('Reference Clinic', $table->text(''));
        self::assertStringNotContainsString('Other hospitals in scope', $table->text(''));
        self::assertSelectorTextContains('[data-testid="stats-analysis-explorer-config-warning"]', 'The comparison with reference hospitals is not available in this view.');
    }

    public function testTheCsvOfAnAuthorisedViewerUsesTheVisibleGroupLabels(): void
    {
        $csv = $this->exportCsv($this->participantWithBenchmarking());

        self::assertSame(['Indication', 'Group', 'Allocations', 'Share within group'], $csv[0]);
        $groups = array_values(array_unique(array_map(static fn (array $row): string => $row[1], \array_slice($csv, 1, -1))));
        sort($groups);
        self::assertSame(['Other hospitals in scope', 'Reference Clinic'], $groups);
    }

    public function testTheCsvOfAnUnauthorisedViewerHasNoGroupColumn(): void
    {
        $csv = $this->exportCsv(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));

        self::assertNotContains('Group', $csv[0]);
        self::assertNotContains('Share within group', $csv[0]);
        foreach ($csv as $row) {
            self::assertNotContains('Reference Clinic', $row);
        }
    }

    public function testAnAnonymousViewerCannotOpenAPublicViewOfAUser(): void
    {
        $result = $this->loader()->load((string) $this->savePublicView()->getId(), $this->filter(), null);

        self::assertTrue($result->notFound);
        self::assertSame([], $result->state['query']['referenceHospitals']);
    }

    public function testAnAnonymousViewerGetsASystemViewWithoutTheReference(): void
    {
        // System views are the only saved views anonymous viewers can open.
        $view = new SavedExplorerView(
            slug: 'reference-comparison-test',
            title: 'Reference comparison',
            category: 'System',
            configJson: $this->comparisonState(),
            isSystem: true,
        );
        self::getContainer()->get(SavedExplorerViewRepository::class)->save($view);

        $result = $this->loader()->load((string) $view->getId(), $this->filter(), null);

        self::assertSame([], $result->state['query']['referenceHospitals']);
        self::assertNotContains(AnalysisMetricKey::PercentOfSeries->value, $result->state['query']['metrics']);
        self::assertSame([ReferenceComparisonDecision::NOTICE_KEY], $result->warnings);
    }

    public function testASavedV4ViewWithAColumnAxisKeepsAxisAndResultsAfterLoadingAndSavingAgain(): void
    {
        $view = new SavedExplorerView(
            slug: null,
            title: 'Indication by urgency',
            category: 'My views',
            configJson: [
                'schemaVersion' => 4,
                'dataSource' => 'allocations',
                'query' => [
                    'scope' => ['group' => 'public', 'detail' => null],
                    'period' => ['type' => 'all', 'year' => null, 'quarter' => null, 'month' => null],
                    'hospitalPopulation' => 'participating',
                    'metrics' => ['allocation_count'],
                    'visualMetric' => 'allocation_count',
                    'rows' => ['dimension' => 'indication', 'grain' => 'total'],
                    'columns' => ['dimension' => 'urgency', 'grain' => 'total'],
                    'filters' => [],
                ],
                'presentation' => ['chartType' => 'grouped_bar', 'tableLayout' => 'matrix', 'chartRowLimit' => 'all'],
                'title' => 'Indication by urgency',
            ],
            isSystem: false,
        );
        $view->setCreatedBy($this->owner);
        self::getContainer()->get(SavedExplorerViewRepository::class)->save($view);

        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $loaded = $this->loader()->load((string) $view->getId(), $this->filter(), $this->owner);
        self::assertSame([], $loaded->warnings);
        self::assertSame(5, $loaded->state['schemaVersion']);
        self::assertSame(['dimension' => 'urgency', 'grain' => 'total'], $loaded->state['query']['columns']);
        self::assertSame([], $loaded->state['query']['referenceHospitals']);

        $config = $mapper->viewConfigFromState($loaded->state, $this->owner);
        $resaved = $mapper->toStateArray($config);
        self::assertSame($loaded->state['query']['columns'], $resaved['query']['columns']);
        self::assertSame('matrix', $resaved['presentation']['tableLayout']);

        $before = $this->counts($config);
        self::assertSame(['101|1' => 5, '102|1' => 5], $before, 'Five hospitals with one urgent case per indication.');
        self::assertSame($before, $this->counts($mapper->viewConfigFromState($resaved, $this->owner)));
    }

    /**
     * @return array<string, int> count by "bucket|series"
     */
    private function counts(\App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig $config): array
    {
        $execution = self::getContainer()->get(\App\Statistics\AnalysisExplorer\Application\AnalysisExecution::class)->execute($config, $this->owner);
        self::assertNull($execution->emptyReason);

        $counts = [];
        foreach ($execution->result->rows as $row) {
            $counts[$row->bucket.'|'.$row->seriesKey] = (int) $row->valueFor(AnalysisMetricKey::AllocationCount);
        }
        ksort($counts);

        return $counts;
    }

    private function participantWithBenchmarking(): User
    {
        $viewer = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => HospitalFactory::find($this->reference),
            'user' => $viewer,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View, HospitalPermission::Statistics, HospitalPermission::Benchmarking]),
        ]);

        return $viewer;
    }

    /**
     * @return array<string, mixed>
     */
    private function comparisonState(): array
    {
        $config = self::getContainer()->get(DefaultAnalysisViewFactory::class)->createDefault($this->filter())
            ->withAxes(AnalysisAxisRef::breakdown(AnalysisDimensionKey::Indication), null)
            ->withReferenceHospitalIds([$this->reference])
            ->withMetrics([AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfSeries], AnalysisMetricKey::AllocationCount);

        return self::getContainer()->get(ExplorerConfigMapper::class)->toStateArray($config);
    }

    private function savePublicView(): SavedExplorerView
    {
        $view = new SavedExplorerView(
            slug: null,
            title: 'Reference comparison',
            category: 'My views',
            configJson: $this->comparisonState(),
            isSystem: false,
        );
        $view->setCreatedBy($this->owner);
        $view->setVisibility(AnalysisViewVisibility::Public);
        self::getContainer()->get(SavedExplorerViewRepository::class)->save($view);

        return $view;
    }

    /**
     * @return list<list<string>>
     */
    private function exportCsv(User $viewer): array
    {
        $this->client->loginUser($viewer);
        $this->client->request(Request::METHOD_GET, '/statistics/analysis/explorer?scope=public&period=all');
        self::assertResponseIsSuccessful();

        $requestStack = $this->client->getContainer()->get('request_stack');
        $requestStack->push($this->client->getRequest());
        try {
            $token = (string) $this->client->getContainer()->get('security.csrf.token_manager')->getToken('explorer_export_csv')->getValue();
        } finally {
            $requestStack->pop();
        }

        $this->client->request(Request::METHOD_POST, '/statistics/analysis/explorer/export/table.csv', [
            '_token' => $token,
            'appliedConfigState' => json_encode($this->comparisonState(), \JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getInternalResponse()->getContent();
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;

        return array_map(
            static fn (string $line): array => array_map(strval(...), str_getcsv($line, ',', '"', '\\')),
            preg_split('/\R/', trim($content)) ?: [],
        );
    }

    private function loader(): SavedExplorerViewLoader
    {
        return self::getContainer()->get(SavedExplorerViewLoader::class);
    }

    private function filter(): StatisticsFilter
    {
        return new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::AllTime,
        );
    }

    /**
     * Two cases per hospital: indications 101 and 102 (5 hospitals, one reference leaves 4 others).
     *
     * @param list<int> $hospitals
     */
    private function seedProjection(array $hospitals): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $id = 0;
        foreach ($hospitals as $hospitalId) {
            foreach ([101, 102] as $indication) {
                $connection->insert('allocation_stats_projection', [
                    'id' => ++$id, 'import_id' => 1, 'hospital_id' => $hospitalId, 'state_id' => 1, 'dispatch_area_id' => 1,
                    'speciality_id' => 1, 'department_id' => 1, 'assignment_id' => 1, 'indication_normalized_id' => $indication,
                    'created_at' => '2024-03-10 08:00:00', 'arrival_at' => '2024-03-10 08:20:00',
                    'created_year' => 2024, 'created_quarter' => 1, 'created_month' => 3, 'created_week' => 10, 'created_day' => 10,
                    'created_weekday' => 7, 'created_hour' => 8, 'day_time_bucket_code' => 1, 'shift_bucket_code' => 1,
                    'transport_time_minutes' => 20, 'urgency_code' => 1,
                ]);
            }
        }
    }
}

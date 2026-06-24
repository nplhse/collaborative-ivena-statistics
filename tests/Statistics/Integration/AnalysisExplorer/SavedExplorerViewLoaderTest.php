<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewLoader;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class SavedExplorerViewLoaderTest extends KernelTestCase
{
    use SeedsExplorerSystemViewsTrait;

    private SavedExplorerViewLoader $loader;

    private SavedExplorerViewRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->loader = $container->get(SavedExplorerViewLoader::class);
        $this->repository = $container->get(SavedExplorerViewRepository::class);
        $this->seedExplorerSystemViews();
    }

    public function testLoadBySlugReturnsSavedConfig(): void
    {
        $filter = $this->publicFilter();
        $result = $this->loader->load('gender-over-time', $filter, null);

        self::assertFalse($result->notFound);
        self::assertFalse($result->usedFallback);
        self::assertSame('time', $result->state['query']['rows']['dimension'] ?? null);
        self::assertSame('month', $result->state['query']['rows']['grain'] ?? null);
        self::assertSame('gender', $result->state['query']['columns']['dimension'] ?? null);
        self::assertSame('grouped_bar', $result->state['presentation']['chartType'] ?? null);
    }

    public function testLoadByNumericId(): void
    {
        $view = $this->repository->findBySlug('allocations-by-year');
        self::assertInstanceOf(SavedExplorerView::class, $view);

        $result = $this->loader->load((string) $view->getId(), $this->publicFilter(), null);

        self::assertFalse($result->notFound);
        self::assertSame('year', $result->state['query']['rows']['grain'] ?? null);
        self::assertSame('line', $result->state['presentation']['chartType'] ?? null);
    }

    public function testHospitalSystemViewLoadsWithoutExplicitDataSource(): void
    {
        $result = $this->loader->load('hospitals-by-tier', $this->publicFilter(), null);

        self::assertFalse($result->notFound);
        self::assertFalse($result->usedFallback);
        self::assertSame('hospitals', $result->state['dataSource'] ?? null);
        self::assertSame('hospital_tier', $result->state['query']['rows']['dimension'] ?? null);
    }

    public function testExplicitDataSourceMismatchFallsBack(): void
    {
        $result = $this->loader->load(
            'hospitals-by-tier',
            $this->publicFilter(),
            null,
            AnalysisDataSourceKey::Allocations,
        );

        self::assertTrue($result->usedFallback);
        self::assertSame(['stats.analysis_explorer.saved_view.data_source_mismatch'], $result->warnings);
        self::assertSame('allocations', $result->state['dataSource'] ?? null);
    }

    public function testUnknownViewIsNotFound(): void
    {
        $result = $this->loader->load('missing-view', $this->publicFilter(), null);

        self::assertTrue($result->notFound);
    }

    public function testInvalidConfigUsesFallbackWithWarning(): void
    {
        $view = $this->repository->findBySlug('urgency-distribution');
        self::assertInstanceOf(SavedExplorerView::class, $view);

        $view->update(
            title: $view->getTitle(),
            category: $view->getCategory(),
            configJson: ['broken' => true],
            description: $view->getDescription(),
        );
        $this->repository->save($view);

        $result = $this->loader->load('urgency-distribution', $this->publicFilter(), null);

        self::assertTrue($result->usedFallback);
        self::assertSame(['stats.analysis_explorer.saved_view.invalid_config'], $result->warnings);
        self::assertSame('time', $result->state['query']['rows']['dimension'] ?? null);
    }

    public function testFilterOverlayReplacesScopeInState(): void
    {
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::Year,
            referenceYear: 2024,
        );

        $result = $this->loader->load('allocations-over-time', $filter, null);

        self::assertSame('year', $result->state['query']['period']['type'] ?? null);
        self::assertSame(2024, $result->state['query']['period']['year'] ?? null);
    }

    public function testForeignUserViewIsNotFoundForOtherUsers(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $other = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        $view = new SavedExplorerView(
            slug: null,
            title: 'Private view',
            category: 'My views',
            configJson: $this->repository->findBySlug('allocations-over-time')?->getConfigJson() ?? [],
            isSystem: false,
        );
        $view->setCreatedBy($owner);
        $this->repository->save($view);
        self::assertNotNull($view->getId());

        $result = $this->loader->load((string) $view->getId(), $this->publicFilter(), $other);

        self::assertTrue($result->notFound);
    }

    public function testLegacyV2ConfigWithSingularMetricLoadsAndUpgradesState(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $view = new SavedExplorerView(
            slug: 'legacy-v2-singular-metric',
            title: 'Legacy v2 view',
            category: 'Test',
            configJson: [
                'schemaVersion' => 2,
                'dataSource' => 'allocations',
                'query' => [
                    'scope' => ['group' => 'public', 'detail' => null],
                    'period' => ['type' => 'all', 'year' => null, 'quarter' => null, 'month' => null],
                    'metric' => 'allocation_count',
                    'dimension' => 'time',
                    'grain' => 'month',
                ],
                'presentation' => ['mode' => 'chart', 'chartType' => 'bar'],
                'title' => 'Allocations over time',
            ],
            isSystem: false,
        );
        $view->setCreatedBy($owner);
        $this->repository->save($view);

        $result = $this->loader->load('legacy-v2-singular-metric', $this->publicFilter(), $owner);

        self::assertFalse($result->notFound);
        self::assertFalse($result->usedFallback);
        self::assertSame(4, $result->state['schemaVersion'] ?? null);
        self::assertSame(['allocation_count'], $result->state['query']['metrics'] ?? null);
        self::assertSame('allocation_count', $result->state['query']['visualMetric'] ?? null);
        self::assertSame('time', $result->state['query']['rows']['dimension'] ?? null);
        self::assertSame('month', $result->state['query']['rows']['grain'] ?? null);
        self::assertSame([], $result->state['query']['filters'] ?? null);
    }

    public function testLegacyViewWithoutFiltersLoadsWithEmptyFilters(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $view = new SavedExplorerView(
            slug: 'legacy-without-filters',
            title: 'Legacy without filters',
            category: 'Test',
            configJson: [
                'schemaVersion' => 3,
                'dataSource' => 'allocations',
                'query' => [
                    'scope' => ['group' => 'public', 'detail' => null],
                    'period' => ['type' => 'all', 'year' => null, 'quarter' => null, 'month' => null],
                    'metrics' => ['allocation_count'],
                    'visualMetric' => 'allocation_count',
                    'rows' => ['dimension' => 'time', 'grain' => 'month'],
                ],
                'presentation' => ['mode' => 'chart', 'chartType' => 'bar'],
                'title' => 'Allocations over time',
            ],
            isSystem: false,
        );
        $view->setCreatedBy($owner);
        $this->repository->save($view);

        $result = $this->loader->load('legacy-without-filters', $this->publicFilter(), $owner);

        self::assertFalse($result->notFound);
        self::assertSame([], $result->state['query']['filters'] ?? []);
    }

    private function publicFilter(): StatisticsFilter
    {
        return new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
    }
}

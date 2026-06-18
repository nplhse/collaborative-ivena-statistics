<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\AnalysisViewResolver;
use App\Statistics\GenericAnalysis\Application\SavedAnalysisViewService;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewConfig;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisViewException;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AnalysisViewResolverTest extends KernelTestCase
{
    use Factories;

    private AnalysisViewResolver $resolver;

    private SavedAnalysisViewService $savedViewService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->resolver = $container->get(AnalysisViewResolver::class);
        $this->savedViewService = $container->get(SavedAnalysisViewService::class);
    }

    public function testResolveSystemViewReturnsContextForKnownView(): void
    {
        $request = Request::create('/statistics/analytics/view/allocations_by_month', Request::METHOD_GET, [
            'scope' => 'public',
            'period' => 'all',
        ]);

        $resolved = $this->resolver->resolveSystemView(
            'allocations_by_month',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            null,
        );

        self::assertFalse($resolved->isSaved);
        self::assertSame('allocations_by_month', $resolved->sourceKey);
        self::assertSame('allocations_by_month', $resolved->view->key);
        self::assertSame('month', $resolved->config->primaryDimensionKey);
    }

    public function testResolveSavedViewReturnsSavedContext(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $saved = $this->savedViewService->create(
            owner: $user,
            title: 'My urgency view',
            config: new AnalysisViewConfig(
                primaryDimensionKey: 'urgency',
                metricKeys: ['count'],
                visualMetricKey: 'count',
            ),
            sourceSystemViewKey: 'urgency_distribution_with_share',
        );
        $savedId = (int) $saved->getId();

        $request = Request::create('/statistics/analytics/saved/'.$savedId, Request::METHOD_GET, [
            'scope' => 'public',
            'period' => 'all',
        ]);

        $resolved = $this->resolver->resolveSavedView(
            $savedId,
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            $user,
        );

        self::assertTrue($resolved->isSaved);
        self::assertSame((string) $savedId, $resolved->sourceKey);
        self::assertSame('saved_'.$savedId, $resolved->view->key);
        self::assertSame('My urgency view', $resolved->view->title);
        self::assertSame('urgency', $resolved->config->primaryDimensionKey);
    }

    public function testResolveSavedViewThrowsForUnknownId(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $request = Request::create('/statistics/analytics/saved/99999', Request::METHOD_GET);

        $this->expectException(UnknownAnalysisViewException::class);

        $this->resolver->resolveSavedView(
            99999,
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            $user,
        );
    }

    private function publicFilter(): StatisticsFilter
    {
        return new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::All,
        );
    }
}

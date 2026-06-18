<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\DTO\ResolvedGenericAnalysisConfig;
use App\Statistics\GenericAnalysis\Application\SavedAnalysisViewService;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewConfig;
use App\Statistics\Infrastructure\Repository\SavedAnalysisViewRepository;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class SavedAnalysisViewServiceTest extends KernelTestCase
{
    use Factories;

    private SavedAnalysisViewService $service;

    private SavedAnalysisViewRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->service = $container->get(SavedAnalysisViewService::class);
        $this->repository = $container->get(SavedAnalysisViewRepository::class);
    }

    public function testCreatePersistsSavedView(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $config = new AnalysisViewConfig(
            primaryDimensionKey: 'month',
            metricKeys: ['count'],
            visualMetricKey: 'count',
        );

        $saved = $this->service->create(
            owner: $user,
            title: 'Saved allocations',
            config: $config,
            description: 'Monthly view',
            sourceSystemViewKey: 'allocations_by_month',
        );

        self::assertNotNull($saved->getId());
        self::assertSame('Saved allocations', $saved->getTitle());
        self::assertSame('month', $saved->getConfig()->primaryDimensionKey);
        self::assertSame('allocations_by_month', $saved->getSourceSystemViewKey());
    }

    public function testUpdateChangesTitleAndConfig(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $saved = $this->service->create(
            owner: $user,
            title: 'Original title',
            config: new AnalysisViewConfig(primaryDimensionKey: 'month'),
        );

        $updatedConfig = new AnalysisViewConfig(
            primaryDimensionKey: 'urgency',
            metricKeys: ['count', 'percent_of_total'],
        );
        $this->service->update($saved, 'Updated title', 'Updated description', $updatedConfig);

        $reloaded = $this->repository->findForOwner((int) $saved->getId(), $user);
        self::assertNotNull($reloaded);
        self::assertSame('Updated title', $reloaded->getTitle());
        self::assertSame('Updated description', $reloaded->getDescription());
        self::assertSame('urgency', $reloaded->getConfig()->primaryDimensionKey);
    }

    public function testDeleteRemovesSavedView(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $saved = $this->service->create(
            owner: $user,
            title: 'Delete me',
            config: new AnalysisViewConfig(primaryDimensionKey: 'month'),
        );
        $id = (int) $saved->getId();

        $this->service->delete($saved);

        self::assertNull($this->repository->findForOwner($id, $user));
    }

    public function testConfigFromResolvedMapsQueryFields(): void
    {
        $config = SavedAnalysisViewService::configFromResolved(
            new ResolvedGenericAnalysisConfig(
                query: new AnalysisQuery(
                    primaryDimensionKey: 'hospital',
                    scopeCriteria: StatisticsScopeCriteria::public(),
                    periodBounds: new StatisticsPeriodBounds(null),
                    seriesDimensionKey: 'month',
                    metricKeys: ['count'],
                    visualMetricKey: 'count',
                ),
                displayTitle: 'Hospitals by month',
                isCustom: false,
                routePresetKey: 'allocations_by_hospital',
                referencePresetKey: 'allocations_by_hospital',
                primaryDimensionKey: 'hospital',
                seriesDimensionKey: 'month',
                includeNullBuckets: true,
            ),
            layout: 'stacked',
            top: 10,
        );

        self::assertSame('hospital', $config->primaryDimensionKey);
        self::assertSame('month', $config->secondaryDimensionKey);
        self::assertSame(['count'], $config->metricKeys);
        self::assertSame('count', $config->visualMetricKey);
        self::assertTrue($config->includeNullBuckets);
        self::assertSame('stacked', $config->layout);
        self::assertSame(10, $config->top);
    }
}

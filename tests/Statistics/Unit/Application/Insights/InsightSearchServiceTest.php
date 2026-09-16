<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Insights;

use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionProviderInterface;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use App\Statistics\Application\Insights\InsightNavPlacement;
use App\Statistics\Application\Insights\InsightSearchService;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InsightSearchServiceTest extends TestCase
{
    public function testReturnsEmptyBelowMinimumLength(): void
    {
        $service = new InsightSearchService(
            new InsightDimensionRegistry([]),
            new StatisticsNavigationUrlBuilder($this->createStub(UrlGeneratorInterface::class)),
        );

        self::assertSame([], $service->search('S', new Request()));
    }

    public function testAggregatesProviderHitsAndStopsAtMaxResults(): void
    {
        $indications = $this->provider(InsightDimensionKey::Indications, [
            ['id' => 1, 'label' => 'STEMI (1001)', 'code' => 1001, 'publicId' => null, 'contextLabel' => null],
        ]);
        $assignments = $this->provider(InsightDimensionKey::Assignments, [
            ['id' => 2, 'label' => 'Primary', 'code' => null, 'publicId' => null, 'contextLabel' => 'Assignment'],
        ]);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => sprintf(
                '/statistics/insights/%s/%d',
                $params['dimension'],
                $params['id'],
            ),
        );

        $service = new InsightSearchService(
            new InsightDimensionRegistry([$indications, $assignments]),
            new StatisticsNavigationUrlBuilder($urlGenerator),
        );

        $hits = $service->search('st', new Request());

        self::assertCount(2, $hits);
        self::assertSame(InsightDimensionKey::Indications, $hits[0]->dimension);
        self::assertSame('/statistics/insights/indications/1', $hits[0]->url);
        self::assertSame('Primary', $hits[1]->label);
        self::assertSame('Assignment', $hits[1]->contextLabel);
    }

    public function testCanRestrictSearchToOneDimension(): void
    {
        $indications = $this->provider(InsightDimensionKey::Indications, [
            ['id' => 1, 'label' => 'STEMI (1001)', 'code' => 1001, 'publicId' => null, 'contextLabel' => null],
        ]);
        $assignments = $this->provider(InsightDimensionKey::Assignments, [
            ['id' => 2, 'label' => 'Primary', 'code' => null, 'publicId' => null, 'contextLabel' => 'Assignment'],
        ]);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/statistics/insights/assignments/2');

        $service = new InsightSearchService(
            new InsightDimensionRegistry([$indications, $assignments]),
            new StatisticsNavigationUrlBuilder($urlGenerator),
        );

        $hits = $service->search('st', new Request(), InsightDimensionKey::Assignments);

        self::assertCount(1, $hits);
        self::assertSame(InsightDimensionKey::Assignments, $hits[0]->dimension);
        self::assertSame(2, $hits[0]->id);
    }

    /**
     * @param list<array{id: int, label: string, code: ?int, publicId: ?string, contextLabel: ?string}> $hits
     */
    private function provider(InsightDimensionKey $key, array $hits): InsightDimensionProviderInterface
    {
        $provider = $this->createStub(InsightDimensionProviderInterface::class);
        $provider->method('key')->willReturn($key);
        $provider->method('navOrder')->willReturn(InsightDimensionKey::Indications === $key ? 10 : 30);
        $provider->method('navPlacement')->willReturn(InsightNavPlacement::Primary);
        $provider->method('featuredOnOverview')->willReturn(InsightDimensionKey::Indications === $key);
        $provider->method('searchEntities')->willReturn($hits);

        return $provider;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionProviderInterface;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use App\Statistics\Application\Insights\InsightNavPlacement;
use App\Statistics\UI\Http\Controller\Insights\InsightsSubnavViewModelFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InsightsSubnavViewModelFactoryTest extends TestCase
{
    public function testMarksOverviewActiveAndNestsGroupsUnderIndications(): void
    {
        $indications = $this->provider(InsightDimensionKey::Indications, 10);
        $assignments = $this->provider(InsightDimensionKey::Assignments, 30);
        $registry = new InsightDimensionRegistry([$assignments, $indications]);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => match ($route) {
                'app_stats_insights' => '/statistics/insights',
                'app_stats_insights_dimension' => '/statistics/insights/'.$params['dimension'],
                default => $route,
            },
        );

        $factory = new InsightsSubnavViewModelFactory(
            $registry,
            new StatisticsNavigationUrlBuilder($urlGenerator),
        );

        $overview = $factory->create(new Request(), null);
        self::assertTrue($overview['overviewActive']);
        self::assertSame('/statistics/insights', $overview['overviewUrl']);
        self::assertSame(
            ['indications', 'assignments'],
            array_column($overview['tabs'], 'key'),
        );
        self::assertFalse($overview['tabs'][0]['active']);

        $groups = $factory->create(new Request(), InsightDimensionKey::IndicationGroups);
        self::assertFalse($groups['overviewActive']);
        self::assertTrue($groups['tabs'][0]['active']);
        self::assertFalse($groups['tabs'][1]['active']);
    }

    private function provider(InsightDimensionKey $key, int $order): InsightDimensionProviderInterface
    {
        $provider = $this->createStub(InsightDimensionProviderInterface::class);
        $provider->method('key')->willReturn($key);
        $provider->method('navPlacement')->willReturn(InsightNavPlacement::Primary);
        $provider->method('navOrder')->willReturn($order);
        $provider->method('featuredOnOverview')->willReturn(InsightDimensionKey::Indications === $key);
        $provider->method('labelTranslationKey')->willReturn('stats.insights.dimension.'.$key->value.'.label');

        return $provider;
    }
}

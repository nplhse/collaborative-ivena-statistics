<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\AnalysisPresetRegistry;
use App\Statistics\GenericAnalysis\Application\DTO\ResolvedGenericAnalysisConfig;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisConfigResolver;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisDimensionPolicy;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Controller\GenericAnalysisPageViewModelFactory;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GenericAnalysisPageViewModelFactoryTest extends TestCase
{
    private GenericAnalysisPageViewModelFactory $factory;

    protected function setUp(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static function (string $name, array $params = []): string {
                $presetKey = $params['presetKey'] ?? '';
                unset($params['presetKey']);
                $query = http_build_query($params);

                return '/statistics/generic-analysis/'.$presetKey.('' !== $query ? '?'.$query : '');
            },
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $hospitalAccess = $this->createMock(HospitalAccessInterface::class);
        $hospitalAccess->method('isAdminHospitalScopeUser')->willReturn(true);

        $this->factory = new GenericAnalysisPageViewModelFactory(
            new AnalysisPresetRegistry(),
            new DimensionRegistry(),
            new GenericAnalysisDimensionPolicy($hospitalAccess),
            new StatisticsNavigationUrlBuilder($router),
            $router,
            $translator,
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

    public function testPresetMenuUrlRemovesCustomQueryKeys(): void
    {
        $request = Request::create(
            '/statistics/generic-analysis/custom',
            Request::METHOD_GET,
            [
                'scope' => 'public',
                GenericAnalysisQueryKeys::PRIMARY => 'hour',
                GenericAnalysisQueryKeys::SERIES => 'urgency',
            ],
            [
                'presetKey' => 'custom',
                '_route' => 'app_stats_generic_analysis',
                '_route_params' => ['presetKey' => 'custom'],
            ],
        );

        $config = $this->resolvedConfig('hour', 'urgency');
        $viewModel = $this->factory->create($request, 'custom', $config, $this->publicFilter(), null);

        $monthItem = array_values(array_filter(
            $viewModel->presetMenu,
            static fn (array $item): bool => 'allocations_by_month' === $item['key'],
        ))[0];

        self::assertStringContainsString('/statistics/generic-analysis/allocations_by_month', $monthItem['url']);
        self::assertStringNotContainsString('ga_primary', $monthItem['url']);
        self::assertStringContainsString('scope=public', $monthItem['url']);
    }

    public function testCustomFormActionUsesCustomRoute(): void
    {
        $request = Request::create(
            '/statistics/generic-analysis/allocations_by_month',
            Request::METHOD_GET,
            ['scope' => 'public'],
            [
                'presetKey' => 'allocations_by_month',
                '_route_params' => ['presetKey' => 'allocations_by_month'],
            ],
        );

        $config = $this->resolvedConfig('month', null);
        $viewModel = $this->factory->create($request, 'allocations_by_month', $config, $this->publicFilter(), null);

        self::assertSame('/statistics/generic-analysis/custom', $viewModel->customFormAction);
    }

    private function resolvedConfig(
        string $primary,
        ?string $series,
    ): ResolvedGenericAnalysisConfig {
        $hospitalAccess = $this->createMock(HospitalAccessInterface::class);
        $hospitalAccess->method('isAdminHospitalScopeUser')->willReturn(true);

        $resolver = new GenericAnalysisConfigResolver(
            new AnalysisPresetRegistry(),
            new DimensionRegistry(),
            new GenericAnalysisDimensionPolicy($hospitalAccess),
            $this->createMock(TranslatorInterface::class),
        );

        $request = Request::create('/statistics/generic-analysis/custom', Request::METHOD_GET, array_filter([
            GenericAnalysisQueryKeys::PRIMARY => $primary,
            GenericAnalysisQueryKeys::SERIES => $series,
        ]));

        return $resolver->resolve(
            'custom',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            null,
        );
    }
}

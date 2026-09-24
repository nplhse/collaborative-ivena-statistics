<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\InsightCompare;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightPopulationFilter;
use App\Statistics\Application\Insights\InsightSubject;
use App\Statistics\Benchmarking\Application\BenchmarkSelectionQueryBuilder;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionFormDataFactory;
use App\Statistics\UI\Http\Controller\InsightComparePickerViewModelFactory;
use App\Statistics\UI\Http\Controller\InsightCompareUrlHelper;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InsightComparePickerViewModelFactoryTest extends TestCase
{
    public function testSearchUrlStripsDimensionAndCompareSubjectKeys(): void
    {
        $factory = $this->factory();
        $subjectA = $this->subject(InsightDimensionKey::Indications, 12, 'ACS');
        $request = Request::create('/statistics/insights/indications/12', Request::METHOD_GET, [
            'dimension' => InsightDimensionKey::Indications->value,
            'id' => '12',
            StatisticsQueryKeys::SCOPE => StatisticsFilterScope::Public->value,
            StatisticsQueryKeys::PERIOD => StatisticsFilterPeriod::All->value,
            StatisticsQueryKeys::SUBJECT_A_DIMENSION => InsightDimensionKey::Indications->value,
            StatisticsQueryKeys::SUBJECT_A_ID => '12',
            'q' => 'stem',
        ]);

        $viewModel = $factory->create(
            $request,
            $subjectA,
            null,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
            'ACS',
            'Public · All',
        );

        $params = $this->queryParams($viewModel->searchUrl);

        self::assertSame('app_stats_insights_search', $params['_route']);
        self::assertSame(StatisticsFilterScope::Public->value, $params[StatisticsQueryKeys::SCOPE]);
        self::assertSame(StatisticsFilterPeriod::All->value, $params[StatisticsQueryKeys::PERIOD]);
        self::assertArrayNotHasKey('dimension', $params);
        self::assertArrayNotHasKey('id', $params);
        self::assertArrayNotHasKey('q', $params);
        self::assertArrayNotHasKey(StatisticsQueryKeys::SUBJECT_A_DIMENSION, $params);
        self::assertArrayNotHasKey(StatisticsQueryKeys::SUBJECT_A_ID, $params);
        self::assertSame('ACS', $viewModel->referenceLabelA);
        self::assertSame('Public · All', $viewModel->referenceDetailA);
    }

    private function factory(): InsightComparePickerViewModelFactory
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => $route.'?'.http_build_query(array_merge(['_route' => $route], $params)),
        );
        $navigationUrlBuilder = new StatisticsNavigationUrlBuilder($router);

        return new InsightComparePickerViewModelFactory(
            new InsightCompareUrlHelper(
                $navigationUrlBuilder,
                new BenchmarkSelectionQueryBuilder(),
                new BenchmarkSelectionFormDataFactory(),
            ),
            $navigationUrlBuilder,
            new BenchmarkSelectionFormDataFactory(),
        );
    }

    private function subject(InsightDimensionKey $dimension, int $id, string $label): InsightSubject
    {
        return new InsightSubject(
            $dimension,
            $id,
            $label,
            InsightPopulationFilter::of($dimension->projectionColumn(), [$id]),
        );
    }

    /**
     * @return array<string, string>
     */
    private function queryParams(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);

        parse_str($query, $params);

        /* @var array<string, string> $params */
        return $params;
    }
}

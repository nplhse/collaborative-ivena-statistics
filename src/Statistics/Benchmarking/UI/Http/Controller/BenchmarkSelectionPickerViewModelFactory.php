<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

use App\Statistics\UI\Http\Controller\OverviewPeriodViewModel;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModel;
use App\Statistics\UI\Http\Navigation\StatisticsQueryParamNormalizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class BenchmarkSelectionPickerViewModelFactory
{
    public function __construct(
        private BenchmarkSelectionViewModelFactory $benchmarkSelectionViewModelFactory,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function create(Request $request, ?\App\User\Domain\Entity\User $user, \App\Statistics\Application\DTO\StatisticsFilter $primaryFilter, \App\Statistics\Application\DTO\StatisticsFilter $comparisonFilter): BenchmarkSelectionPickerViewModel
    {
        $selection = $this->benchmarkSelectionViewModelFactory->create($request, $user, $primaryFilter, $comparisonFilter);

        return new BenchmarkSelectionPickerViewModel(
            $this->router->generate('app_stats_benchmarking'),
            $this->extractInitialParams($request),
            $this->mapSide($selection->primaryPageViewModel, $selection->primaryPeriodViewModel),
            $this->mapSide($selection->comparisonPageViewModel, $selection->comparisonPeriodViewModel),
        );
    }

    /**
     * @return array<string, scalar>
     */
    private function extractInitialParams(Request $request): array
    {
        $routeParams = $request->attributes->get('_route_params', []);
        if (!\is_array($routeParams)) {
            $routeParams = [];
        }

        $params = array_merge($routeParams, $request->query->all());

        return StatisticsQueryParamNormalizer::normalize($params);
    }

    private function mapSide(StatisticsPageViewModel $pageViewModel, OverviewPeriodViewModel $periodViewModel): BenchmarkSelectionPickerSideViewModel
    {
        return new BenchmarkSelectionPickerSideViewModel(
            $pageViewModel->showScopeSecondaryPicker,
            $pageViewModel->scopePrimaryDropdownLabel,
            $pageViewModel->scopeSecondaryDropdownLabel,
            $periodViewModel->primaryDropdownLabel,
            $periodViewModel->secondaryDropdownLabel,
            $periodViewModel->showSecondaryPicker,
            $this->mapMenu($pageViewModel->scopePrimaryMenu),
            $this->mapMenu($pageViewModel->scopeSecondaryMenu),
            $this->mapMenu($periodViewModel->primaryMenu),
            $this->mapSecondaryMenu($periodViewModel->secondaryMenu),
        );
    }

    /**
     * @param list<array{label: string, url: string, active: bool, key?: string}> $menu
     *
     * @return list<array{label: string, active: bool, params: array<string, scalar>}>
     */
    private function mapMenu(array $menu): array
    {
        $mapped = [];
        foreach ($menu as $item) {
            $mapped[] = [
                'label' => $item['label'],
                'active' => $item['active'],
                'params' => $this->paramsFromUrl($item['url']),
            ];
        }

        return $mapped;
    }

    /**
     * @param list<array{label: string, url: string, active: bool, divider?: bool}> $menu
     *
     * @return list<array{divider: true}|array{label: string, active: bool, params: array<string, scalar>}>
     */
    private function mapSecondaryMenu(array $menu): array
    {
        $mapped = [];
        foreach ($menu as $item) {
            if ($item['divider'] ?? false) {
                $mapped[] = ['divider' => true];

                continue;
            }

            $mapped[] = [
                'label' => $item['label'],
                'active' => $item['active'],
                'params' => $this->paramsFromUrl($item['url']),
            ];
        }

        return $mapped;
    }

    /**
     * @return array<string, scalar>
     */
    private function paramsFromUrl(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!\is_string($query) || '' === $query) {
            return [];
        }

        parse_str($query, $params);

        return StatisticsQueryParamNormalizer::normalize($params);
    }
}

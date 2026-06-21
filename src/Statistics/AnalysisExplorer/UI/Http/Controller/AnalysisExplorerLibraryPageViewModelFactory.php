<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AnalysisExplorerLibraryPageViewModelFactory
{
    public function __construct(
        private SavedExplorerViewRepository $repository,
        private UrlGeneratorInterface $router,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(Request $request): AnalysisExplorerLibraryPageViewModel
    {
        $grouped = [];
        foreach ($this->repository->findAllSystemViewsOrdered() as $view) {
            $grouped[$view->getCategory()][] = $this->buildCard($request, $view);
        }

        $categories = [];
        foreach ($grouped as $category => $cards) {
            $categories[] = [
                'key' => $this->categoryKey($category),
                'title' => $category,
                'label' => $this->translator->trans('stats.analysis_explorer.library.category.'.$this->categoryKey($category)),
                'cards' => $cards,
            ];
        }

        return new AnalysisExplorerLibraryPageViewModel($categories);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCard(Request $request, SavedExplorerView $view): array
    {
        $config = $view->getConfigJson();
        $query = $config['query'] ?? [];
        $presentation = $config['presentation'] ?? [];

        return [
            'slug' => $view->getSlug(),
            'title' => $view->getTitle(),
            'description' => $view->getDescription() ?? '',
            'dimension' => $this->dimensionLabel((string) ($query['dimension'] ?? '')),
            'grain' => $this->grainLabel((string) ($query['grain'] ?? '')),
            'chartType' => $this->chartTypeLabel((string) ($presentation['chartType'] ?? '')),
            'openUrl' => $this->router->generate('app_stats_analysis_explorer_view', array_merge(
                ['view' => $view->getSlug()],
                $this->scopeQuery($request),
            )),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function scopeQuery(Request $request): array
    {
        $query = [];
        foreach ([
            StatisticsQueryKeys::SCOPE,
            StatisticsQueryKeys::HOSPITAL,
            StatisticsQueryKeys::COHORT,
            StatisticsQueryKeys::STATE,
            StatisticsQueryKeys::DISPATCH_AREA,
            StatisticsQueryKeys::PERIOD,
            StatisticsQueryKeys::YEAR,
            StatisticsQueryKeys::MONTH,
            StatisticsQueryKeys::QUARTER,
        ] as $key) {
            if ($request->query->has($key)) {
                $query[$key] = $request->query->getString($key);
            }
        }

        return $query;
    }

    private function categoryKey(string $category): string
    {
        return mb_strtolower(preg_replace('/[^a-z0-9]+/i', '_', $category) ?? $category);
    }

    private function dimensionLabel(string $dimension): string
    {
        if ('' === $dimension) {
            return '';
        }

        $key = 'stats.analysis_explorer.dimension.'.$dimension;

        return $this->translator->trans($key);
    }

    private function grainLabel(string $grain): string
    {
        if ('' === $grain) {
            return '';
        }

        return match ($grain) {
            'month' => $this->translator->trans('stats.analysis_explorer.dimension.month'),
            'year' => $this->translator->trans('stats.analysis_explorer.dimension.year'),
            'total' => $this->translator->trans('stats.analysis_explorer.grain.total'),
            default => $grain,
        };
    }

    private function chartTypeLabel(string $chartType): string
    {
        if ('' === $chartType) {
            return '';
        }

        $key = 'stats.analysis_explorer.chart.'.$chartType;

        return $this->translator->trans($key);
    }
}

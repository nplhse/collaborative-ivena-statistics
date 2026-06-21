<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewFavoriteService;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AnalysisExplorerLibraryPageViewModelFactory
{
    public function __construct(
        private SavedExplorerViewRepository $repository,
        private SavedExplorerViewFavoriteService $favoriteService,
        private UrlGeneratorInterface $router,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(Request $request, ?User $user): AnalysisExplorerLibraryPageViewModel
    {
        $sections = [];

        if ($user instanceof User) {
            $favoriteCards = [];
            foreach ($this->favoriteService->listViewsForUser($user) as $view) {
                $favoriteCards[] = $this->buildCard($request, $view, $user);
            }

            $sections[] = [
                'key' => 'favorites',
                'label' => $this->translator->trans('stats.analysis_explorer.library.section.favorites'),
                'cards' => $favoriteCards,
            ];

            $myViewCards = [];
            foreach ($this->repository->findByCreatorOrdered($user) as $view) {
                $myViewCards[] = $this->buildCard($request, $view, $user);
            }

            $sections[] = [
                'key' => 'my_views',
                'label' => $this->translator->trans('stats.analysis_explorer.library.section.my_views'),
                'cards' => $myViewCards,
            ];
        }

        $grouped = [];
        foreach ($this->repository->findAllSystemViewsOrdered() as $view) {
            $grouped[$view->getCategory()][] = $this->buildCard($request, $view, $user);
        }

        $systemCategories = [];
        foreach ($grouped as $category => $cards) {
            $systemCategories[] = [
                'key' => $this->categoryKey($category),
                'title' => $category,
                'label' => $this->translator->trans('stats.analysis_explorer.library.category.'.$this->categoryKey($category)),
                'cards' => $cards,
            ];
        }

        $sections[] = [
            'key' => 'system',
            'label' => $this->translator->trans('stats.analysis_explorer.library.section.system'),
            'categories' => $systemCategories,
        ];

        return new AnalysisExplorerLibraryPageViewModel($sections);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCard(Request $request, SavedExplorerView $view, ?User $user): array
    {
        $config = $view->getConfigJson();
        $query = $config['query'] ?? [];
        $presentation = $config['presentation'] ?? [];
        $rowAxis = \is_array($query['rows'] ?? null) ? $query['rows'] : [];
        $dimension = (string) ($rowAxis['dimension'] ?? $query['dimension'] ?? '');
        $grain = (string) ($rowAxis['grain'] ?? $query['grain'] ?? '');
        $viewId = $view->getId();
        $canFavorite = $user instanceof User && null !== $viewId;

        return [
            'id' => $viewId,
            'title' => $view->getTitle(),
            'description' => $view->getDescription() ?? '',
            'dimension' => $this->dimensionLabel($dimension),
            'grain' => $this->grainLabel($grain),
            'chartType' => $this->chartTypeLabel((string) ($presentation['chartType'] ?? '')),
            'isSystem' => $view->isSystem(),
            'viewTypeLabel' => $view->isSystem()
                ? $this->translator->trans('stats.analysis_explorer.view_type.system')
                : $this->translator->trans('stats.analysis_explorer.view_type.user'),
            'openUrl' => null !== $viewId
                ? $this->router->generate('app_stats_analysis_explorer_view', array_merge(
                    ['view' => (string) $viewId],
                    $this->scopeQuery($request),
                ))
                : '#',
            'canFavorite' => $canFavorite,
            'isFavorite' => $canFavorite && $this->favoriteService->isFavorite($user, $view),
            'favoriteUrl' => $canFavorite
                ? $this->router->generate('app_stats_analysis_explorer_favorite_toggle', ['id' => $viewId])
                : null,
            'favoriteToken' => $canFavorite ? 'explorer_favorite_'.$viewId : null,
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
        if ('My views' === $category) {
            return 'my_views';
        }

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

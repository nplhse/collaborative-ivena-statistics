<?php

declare(strict_types=1);

namespace App\Statistics\UI\Live;

use App\Statistics\Application\Filter\FilterState;
use App\Statistics\Application\Mapping\GenderValueMapper;
use App\Statistics\Application\Mapping\HospitalLocationValueMapper;
use App\Statistics\Application\Mapping\HospitalTypeValueMapper;
use App\Statistics\Application\Mapping\TriageValueMapper;
use App\Statistics\Application\Mapping\ValueMapper;
use App\Statistics\Application\Panel\Distribution\DistributionTransformer;
use App\Statistics\Application\Panel\Distribution\Renderer;
use App\Statistics\Application\Panel\PanelDefinition;
use App\Statistics\Application\Panel\PanelFactory;
use App\Statistics\Application\State\QueryStateResolver;
use App\Statistics\Infrastructure\Query\DistributionPanelQuery;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(name: 'DistributionPanel', template: '@Statistics/components/DistributionPanel.html.twig')]
final class DistributionPanelComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $viewMode = 'absolute';

    #[LiveProp(writable: true)]
    public string $datePreset = 'all_cases';

    #[LiveProp(writable: true)]
    public string $panelKey = 'urgency';

    #[LiveProp(writable: true)]
    public string $groupedBy = 'none';

    public function __construct(
        private readonly PanelFactory $panelFactory,
        private readonly QueryStateResolver $queryStateResolver,
        private readonly DistributionPanelQuery $distributionPanelQuery,
        private readonly DistributionTransformer $transformer,
        private readonly Renderer $renderer,
        private readonly TriageValueMapper $triageMapper,
        private readonly GenderValueMapper $genderValueMapper,
        private readonly HospitalTypeValueMapper $hospitalTypeMapper,
        private readonly HospitalLocationValueMapper $hospitalLocationValueMapper,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function mount(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof \Symfony\Component\HttpFoundation\Request) {
            return;
        }

        $this->panelKey = $request->query->getString('panel', 'urgency');
        $this->groupedBy = $request->query->getString('grouped_by', 'none');
        $panel = $this->panelFactory->createDistributionPanel($this->panelKey);

        $filterState = $this->queryStateResolver->resolveFilters($request->query, $panel);
        $this->datePreset = (string) ($filterState->get('date_range') ?? 'all_cases');
        $this->viewMode = $this->queryStateResolver->resolveViewMode($request->query, $panel, 'percent' === $this->viewMode);
        $this->viewMode = $this->normalizeViewMode($this->viewMode);
    }

    /**
     * @return array{
     *     chart: array<string, mixed>,
     *     table: list<array{dimensionLabel: string, groupLabel: string|null, value: int, percent: float, isTotal: bool}>
     * }
     */
    public function getRenderedData(): array
    {
        $panel = $this->panelFactory->createDistributionPanel($this->panelKey);
        $filterState = new FilterState([
            'date_range' => $this->datePreset,
        ]);

        $groupByField = match ($this->groupedBy) {
            'tier' => 'hospital_tier_code',
            'location' => 'hospital_location_code',
            default => null,
        };

        $rows = $this->distributionPanelQuery->fetchDistribution($panel, $filterState, $groupByField);
        $distribution = $this->transformer->transform(
            $rows,
            $this->dimensionMapperFor($panel),
            $this->groupMapperForSelection(),
        );

        $this->viewMode = $this->normalizeViewMode($this->viewMode);

        return $this->renderer->render($distribution, $this->viewMode);
    }

    /**
     * @return array<string, mixed>
     */
    public function getUrlState(): array
    {
        $state = $this->queryStateResolver->serializeToQuery([
            'date_range' => $this->datePreset,
        ], $this->viewMode);

        $state['panel'] = $this->panelKey;
        $state['grouped_by'] = $this->groupedBy;
        $state['view'] = $this->normalizeViewMode($this->viewMode);

        return $state;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function getAvailablePanels(): array
    {
        $items = [];
        foreach ($this->panelFactory->listDistributionPanels() as $panel) {
            $items[] = [
                'key' => $panel->key,
                'label' => $panel->dimensionLabel,
            ];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    public function getAllowedViewModes(): array
    {
        if ('none' === $this->groupedBy) {
            return ['absolute', 'percent_of_total'];
        }

        return ['grouped', 'stacked', 'percent'];
    }

    private function dimensionMapperFor(PanelDefinition $panel): ValueMapper
    {
        return match ($panel->key) {
            'gender' => $this->genderValueMapper,
            default => $this->triageMapper,
        };
    }

    private function groupMapperForSelection(): ?ValueMapper
    {
        return match ($this->groupedBy) {
            'tier' => $this->hospitalTypeMapper,
            'location' => $this->hospitalLocationValueMapper,
            default => null,
        };
    }

    private function normalizeViewMode(string $viewMode): string
    {
        $allowed = $this->getAllowedViewModes();
        if (\in_array($viewMode, $allowed, true)) {
            return $viewMode;
        }

        return $allowed[0];
    }
}

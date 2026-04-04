<?php

declare(strict_types=1);

namespace App\Statistics\UI\Live;

use App\Statistics\Application\Filter\FilterRegistry;
use App\Statistics\Application\Filter\FilterState;
use App\Statistics\Application\Mapping\AgeCohortValueMapper;
use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\Application\Mapping\GenderValueMapper;
use App\Statistics\Application\Mapping\HospitalLocationValueMapper;
use App\Statistics\Application\Mapping\HospitalTypeValueMapper;
use App\Statistics\Application\Mapping\TriageValueMapper;
use App\Statistics\Application\Mapping\ValueMapper;
use App\Statistics\Application\Panel\Distribution\DimensionKind;
use App\Statistics\Application\Panel\Distribution\DistributionNumericMetric;
use App\Statistics\Application\Panel\Distribution\DistributionNumericMetricMerge;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfig;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfigResolver;
use App\Statistics\Application\Panel\Distribution\DistributionPanelNumericMetricPolicy;
use App\Statistics\Application\Panel\Distribution\DistributionSectionNavProvider;
use App\Statistics\Application\Panel\Distribution\DistributionTransformer;
use App\Statistics\Application\Panel\Distribution\Renderer;
use App\Statistics\Application\Panel\PanelDefinition;
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

    /**
     * @var (
     *     array{
     *         route_name: string,
     *         section_key: string,
     *         panels: list<array<string, mixed>>,
     *         default_panel_key?: string|null,
     *     }|array{}
     * )
     */
    #[LiveProp(writable: true)]
    public array $distributionPageOptions = [];

    #[LiveProp(writable: true)]
    public string $viewMode = 'absolute';

    /** @var array<string, mixed> */
    #[LiveProp(writable: true)]
    public array $filterValues = [];

    #[LiveProp(writable: true)]
    public string $panelKey = 'urgency';

    #[LiveProp(writable: true)]
    public string $groupedBy = 'none';

    #[LiveProp(writable: true)]
    public string $chartType = 'bar';

    #[LiveProp(writable: true)]
    public string $barBasis = 'counts';

    public function __construct(
        private readonly DistributionSectionNavProvider $distributionSectionNavProvider,
        private readonly DistributionPageConfigResolver $distributionPageConfigResolver,
        private readonly QueryStateResolver $queryStateResolver,
        private readonly DistributionPanelQuery $distributionPanelQuery,
        private readonly DistributionTransformer $transformer,
        private readonly DistributionNumericMetricMerge $distributionNumericMetricMerge,
        private readonly Renderer $renderer,
        private readonly TriageValueMapper $triageMapper,
        private readonly GenderValueMapper $genderValueMapper,
        private readonly HospitalTypeValueMapper $hospitalTypeMapper,
        private readonly HospitalLocationValueMapper $hospitalLocationValueMapper,
        private readonly AgeCohortValueMapper $ageCohortValueMapper,
        private readonly FilterRegistry $filterRegistry,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param array{
     *     route_name: string,
     *     section_key: string,
     *     panels: list<array<string, mixed>>,
     *     default_panel_key?: string|null,
     * } $distributionPageOptions
     */
    public function mount(array $distributionPageOptions): void
    {
        $this->distributionPageOptions = $distributionPageOptions;

        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof \Symfony\Component\HttpFoundation\Request) {
            return;
        }

        $pageConfig = $this->pageConfig();
        $defaultPanel = $pageConfig->defaultPanel();
        $this->panelKey = $request->query->getString('panel', $defaultPanel->key);
        if (!$pageConfig->getPanel($this->panelKey) instanceof PanelDefinition) {
            $this->panelKey = $defaultPanel->key;
        }

        $panel = $pageConfig->getPanel($this->panelKey) ?? $defaultPanel;
        $this->groupedBy = $request->query->getString('grouped_by', 'none');
        if (!($panel->controls['allow_group_by'] ?? true)) {
            $this->groupedBy = 'none';
        }

        $filterState = $this->queryStateResolver->resolveFilters($request->query, $panel);
        $this->filterValues = $this->mergeFilterDefaults($panel, $filterState->values);

        $showPercent = true === ($panel->options['show_percent'] ?? false);
        $this->viewMode = $this->queryStateResolver->resolveViewMode($request->query, $panel, $showPercent);

        $this->chartType = $request->query->getString('chart_type', 'bar');
        $this->barBasis = $request->query->getString('bar_basis', 'counts');
        $this->normalizeChartState($panel);
        $this->viewMode = $this->normalizeViewMode($this->viewMode);
    }

    /**
     * @return array{
     *     chart: array<string, mixed>,
     *     table: list<array<string, mixed>>,
     *     tableMode: string
     * }
     */
    public function getRenderedData(): array
    {
        $pageConfig = $this->pageConfig();
        $panel = $pageConfig->getPanel($this->panelKey) ?? $pageConfig->defaultPanel();
        $filterState = new FilterState($this->effectiveFilterValues($panel));
        $policy = DistributionPanelNumericMetricPolicy::for($panel);
        $metric = $policy->metric();

        $this->normalizeChartState($panel);

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

        if ('bar' === $this->chartType && 'average' === $this->barBasis && $policy->allowsAverageBars()) {
            $overallH = $this->distributionPanelQuery->fetchOverallHospitalParticipation($panel, $filterState);
            $distribution = $this->distributionNumericMetricMerge->mergeCasesPerHospitalBarAverage($distribution, $overallH);
        }

        if ($policy->needsNumericQuery($this->chartType) && $metric instanceof DistributionNumericMetric) {
            $statRows = $this->distributionPanelQuery->fetchNumericDistributionStats(
                $panel,
                $filterState,
                $metric,
                $groupByField,
            );
            $overall = $this->distributionPanelQuery->fetchOverallNumericStats($panel, $filterState, $metric);
            $distribution = $this->distributionNumericMetricMerge->mergeBoxplotTable($distribution, $statRows, $overall);
        }

        $this->viewMode = $this->normalizeViewMode($this->viewMode);

        return $this->renderer->render(
            $distribution,
            $this->viewMode,
            $this->chartType,
            $this->barBasis,
            $metric,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getUrlState(): array
    {
        $panel = $this->pageConfig()->getPanel($this->panelKey) ?? $this->pageConfig()->defaultPanel();
        $state = $this->queryStateResolver->serializeToQuery($this->effectiveFilterValues($panel), $this->viewMode);

        $state['panel'] = $this->panelKey;
        $state['grouped_by'] = $this->groupedBy;
        $state['view'] = $this->normalizeViewMode($this->viewMode);
        $state['chart_type'] = $this->chartType;
        $state['bar_basis'] = $this->barBasis;

        return $state;
    }

    /** @return array<string, mixed> */
    public function getCrossSectionQueryParams(): array
    {
        $panel = $this->pageConfig()->getPanel($this->panelKey) ?? $this->pageConfig()->defaultPanel();

        return [
            'view' => $this->normalizeViewMode($this->viewMode),
            'grouped_by' => $this->groupedBy,
            'chart_type' => $this->chartType,
            'bar_basis' => $this->barBasis,
            'f' => $this->effectiveFilterValues($panel),
        ];
    }

    public function getPageRouteName(): string
    {
        return $this->pageConfig()->routeName;
    }

    public function pageConfig(): DistributionPageConfig
    {
        $this->assertDistributionPageOptionsNotEmpty();

        return $this->distributionPageConfigResolver->resolve($this->distributionPageOptions);
    }

    /**
     * @psalm-assert array{
     *     route_name: string,
     *     section_key: string,
     *     panels: list<array<string, mixed>>,
     *     default_panel_key?: string|null,
     * } $this->distributionPageOptions
     */
    private function assertDistributionPageOptionsNotEmpty(): void
    {
        if ([] === $this->distributionPageOptions) {
            throw new \LogicException('distributionPageOptions must be set (e.g. from the distribution controller via Twig).');
        }
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function getAvailablePanels(): array
    {
        $items = [];
        foreach ($this->pageConfig()->panels as $panel) {
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
        if ('boxplot' === $this->chartType) {
            return ['absolute'];
        }

        if ('average' === $this->barBasis) {
            return 'none' === $this->groupedBy ? ['absolute'] : ['grouped'];
        }

        if ('none' === $this->groupedBy) {
            return ['absolute', 'percent_of_total'];
        }

        return ['grouped', 'stacked', 'percent'];
    }

    /**
     * @return list<array{key: string, type: string}>
     */
    public function getActivePanelFilters(): array
    {
        $panel = $this->pageConfig()->getPanel($this->panelKey) ?? $this->pageConfig()->defaultPanel();
        $out = [];
        foreach ($panel->filters as $key) {
            $out[] = [
                'key' => $key,
                'type' => $this->filterRegistry->get($key)->type,
            ];
        }

        return $out;
    }

    public function getDateRangePreset(): string
    {
        $panel = $this->pageConfig()->getPanel($this->panelKey) ?? $this->pageConfig()->defaultPanel();
        $v = $this->effectiveFilterValues($panel)['date_range'] ?? 'all_cases';

        return \is_string($v) ? $v : 'all_cases';
    }

    /**
     * @return list<int>
     */
    public function getHospitalTierSelection(): array
    {
        return $this->intListFromFilterKey('hospital_tier');
    }

    /**
     * @return list<int>
     */
    public function getHospitalLocationSelection(): array
    {
        return $this->intListFromFilterKey('hospital_location');
    }

    public function showGroupingControl(): bool
    {
        $panel = $this->pageConfig()->getPanel($this->panelKey) ?? $this->pageConfig()->defaultPanel();

        return true === ($panel->controls['allow_group_by'] ?? true);
    }

    public function showViewModeToggle(): bool
    {
        $panel = $this->pageConfig()->getPanel($this->panelKey) ?? $this->pageConfig()->defaultPanel();
        $base = true === ($panel->controls['allow_view_mode_toggle'] ?? false);

        return $base
            && 'bar' === $this->chartType
            && 'counts' === $this->barBasis;
    }

    public function showChartTypeControl(): bool
    {
        $panel = $this->pageConfig()->getPanel($this->panelKey) ?? $this->pageConfig()->defaultPanel();

        return DistributionPanelNumericMetricPolicy::for($panel)->showChartTypeControl();
    }

    public function showBarBasisControl(): bool
    {
        $panel = $this->pageConfig()->getPanel($this->panelKey) ?? $this->pageConfig()->defaultPanel();

        return 'bar' === $this->chartType
            && DistributionPanelNumericMetricPolicy::for($panel)->showBarBasisControl();
    }

    public function useYAxisPercentFormatter(): bool
    {
        return 'bar' === $this->chartType
            && 'counts' === $this->barBasis
            && \in_array($this->viewMode, ['percent', 'percent_of_total'], true);
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function getHospitalTierFilterOptions(): array
    {
        $choices = [];
        foreach (AllocationStatsHospitalTierProjectionCode::cases() as $case) {
            $choices[] = [
                'value' => $case->value,
                'label' => $this->hospitalTypeMapper->label($case->value),
            ];
        }

        return $choices;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function getHospitalLocationFilterOptions(): array
    {
        $choices = [];
        foreach (AllocationStatsHospitalLocationProjectionCode::cases() as $case) {
            $choices[] = [
                'value' => $case->value,
                'label' => $this->hospitalLocationValueMapper->label($case->value),
            ];
        }

        return $choices;
    }

    /**
     * @return list<array{route: string, label: string, active: bool, hrefParams: array<string, mixed>}>
     */
    public function getDistributionSectionNav(): array
    {
        $currentRoute = $this->pageConfig()->routeName;
        $hrefBase = $this->getCrossSectionQueryParams();

        $out = [];
        foreach ($this->distributionSectionNavProvider->sections() as $section) {
            $out[] = [
                'route' => $section['route'],
                'label' => $section['label'],
                'active' => $section['route'] === $currentRoute,
                'hrefParams' => $hrefBase,
            ];
        }

        return $out;
    }

    private function normalizeChartState(PanelDefinition $panel): void
    {
        $policy = DistributionPanelNumericMetricPolicy::for($panel);

        if (!\in_array($this->chartType, ['bar', 'boxplot'], true)) {
            $this->chartType = 'bar';
        }

        if ('boxplot' === $this->chartType && !$policy->allowsBoxplotChart()) {
            $this->chartType = 'bar';
        }

        if (!\in_array($this->barBasis, ['counts', 'average'], true)) {
            $this->barBasis = 'counts';
        }

        if ('average' === $this->barBasis && !$policy->allowsAverageBars()) {
            $this->barBasis = 'counts';
        }

        if ('boxplot' === $this->chartType) {
            $this->barBasis = 'counts';
        }
    }

    private function dimensionMapperFor(PanelDefinition $panel): ValueMapper
    {
        if (DimensionKind::AgeCohort === $panel->dimensionKind) {
            return $this->ageCohortValueMapper;
        }

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

    /**
     * @param array<string, mixed> $fromRequest
     *
     * @return array<string, mixed>
     */
    private function mergeFilterDefaults(PanelDefinition $panel, array $fromRequest): array
    {
        $defaults = [];
        foreach ($panel->filters as $fk) {
            $def = $this->filterRegistry->get($fk);
            $defaults[$fk] = $panel->filterDefaults[$fk] ?? $def->defaultValue;
        }

        return array_replace($defaults, $fromRequest);
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveFilterValues(PanelDefinition $panel): array
    {
        return $this->mergeFilterDefaults($panel, $this->filterValues);
    }

    /**
     * @return list<int>
     */
    private function intListFromFilterKey(string $key): array
    {
        $panel = $this->pageConfig()->getPanel($this->panelKey) ?? $this->pageConfig()->defaultPanel();
        $v = $this->effectiveFilterValues($panel)[$key] ?? [];
        if (!\is_array($v)) {
            return [];
        }

        $out = [];
        foreach ($v as $x) {
            if (is_numeric($x)) {
                $out[] = (int) $x;
            }
        }

        return $out;
    }
}

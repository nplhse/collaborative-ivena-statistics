<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Statistics\Domain\Enum\TimeGridMode;
use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Availability\ScopeAvailabilityService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'ChooseScopePicker', template: '@Statistics/components/ChooseScopePicker.html.twig')]
final class ChooseScopePicker
{
    /**
     * set by template.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public Scope $primary;

    /** set by template */
    public ?Scope $base = null;

    /** set by template */
    public TimeGridMode $mode = TimeGridMode::RAW;

    /** set by template */
    public string $preset = 'default';

    /** @var array<int,string> */
    private array $hospitalNames = [];

    /** @var array<int,string> */
    private array $dispatchAreaNames = [];

    /** @var array<int,string> */
    private array $stateNames = [];

    public function __construct(
        private RequestStack $requestStack,
        private RouterInterface $router,
        private ScopeAvailabilityService $availability,
        private HospitalRepository $hospitalRepository,
        private DispatchAreaRepository $dispatchAreaRepository,
        private StateRepository $stateRepository,
    ) {
    }

    /**
     * Groups of available scopes for selects.
     *
     * @return array<int, array{label:string, items: list<array{type:string,id:string,label:string,selected:bool,url:string}>}>
     */
    public function primaryGroups(): array
    {
        return $this->buildGroups(forBase: false);
    }

    /**
     * Only used when mode === COMPARE.
     *
     * @return array<int, array{label:string, items: list<array{type:string,id:string,label:string,selected:bool,url:string}>}>
     */
    public function baseGroups(): array
    {
        return $this->buildGroups(forBase: true);
    }

    public function isCompare(): bool
    {
        return TimeGridMode::COMPARE === $this->mode;
    }

    public function modeUrl(string $mode): string
    {
        $r = $this->requestStack->getCurrentRequest();
        if (!$r) {
            return '#';
        }

        $route = (string) $r->attributes->get('_route');

        $params = array_merge(
            $r->attributes->get('_route_params', []),
            $r->query->all(),
            ['mode' => $mode]
        );

        return $this->router->generate($route, $params);
    }

    /**
     * @return array<int, array{label:string, items: list<array{type:string,id:string,label:string,selected:bool,url:string}>}>
     */
    private function buildGroups(bool $forBase): array
    {
        // We list scopes based on the currently selected primary (keeps it simple and fast)
        $matrix = $this->availability->getSidebarTree();

        // group by scope_type
        $byType = [];
        foreach ($matrix as $row) {
            $type = $row['scope_type'];
            $id = $row['scope_id'];
            $byType[$type][] = $id;
        }

        $labelForType = [
            'public' => 'Public',
            'state' => 'State',
            'dispatch_area' => 'Dispatch Area',
            'hospital' => 'Hospital',
            'hospital_tier' => 'Hospital Tier',
            'hospital_size' => 'Hospital Size',
            'hospital_location' => 'Hospital Location',
            'hospital_cohort' => 'Hospital Cohort',
        ];

        $groups = [];
        foreach ($byType as $type => $ids) {
            sort($ids, SORT_NATURAL);
            $items = [];
            foreach ($ids as $id) {
                $selected = false;

                if ($forBase) {
                    if (null !== $this->base) {
                        $selected = ($this->base->scopeType === $type && $this->base->scopeId === $id);
                    }
                } else {
                    $selected = ($this->primary->scopeType === $type && $this->primary->scopeId === $id);
                }

                $items[] = [
                    'type' => $type,
                    'id' => $id,
                    'label' => $this->renderScopeOptionLabel($type, $id),
                    'selected' => $selected,
                    'url' => $this->buildUrl($type, $id, $forBase),
                ];
            }

            $groups[] = [
                'label' => $labelForType[$type] ?? ucfirst(str_replace('_', ' ', $type)),
                'items' => $items,
            ];
        }

        // Sort groups by custom order: Public, State, Dispatch Area, Hospital, others
        $order = [
            'Public' => 1,
            'State' => 2,
            'Dispatch Area' => 3,
            'Hospital' => 4,
        ];

        usort($groups, static function ($a, $b) use ($order) {
            $rankA = $order[$a['label']] ?? 99;
            $rankB = $order[$b['label']] ?? 99;

            // Primary sort: rank
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            // Secondary sort: alphabetic for same rank
            return strcmp($a['label'], $b['label']);
        });

        return $groups;
    }

    private function buildUrl(string $type, string $id, bool $forBase): string
    {
        $r = $this->requestStack->getCurrentRequest();
        if (!$r) {
            return '#';
        }

        $route = (string) $r->attributes->get('_route');

        // Start mit aktuellen Parametern
        $params = array_merge(
            $r->attributes->get('_route_params', []),
            $r->query->all(),
            [
                'preset' => $this->preset,
                'gran' => $this->primary->granularity,
                'key' => $this->primary->periodKey,
                'mode' => $this->mode->value,
            ]
        );

        if (false === $forBase) {
            // PRIMARY wird gewechselt
            $params['primaryType'] = $type;
            $params['primaryId'] = $id;

            // Falls wir NICHT im Compare-Modus sind:
            if (!$this->isCompare()) {
                unset($params['baseType'], $params['baseId']);
            } else {
                // Compare-Mode: Base behalten
                if (null !== $this->base) {
                    $params['baseType'] = $this->base->scopeType;
                    $params['baseId'] = $this->base->scopeId;
                }
            }
        } else {
            // BASE wird gewechselt
            if ($this->isCompare()) {
                $params['baseType'] = $type;
                $params['baseId'] = $id;
            }

            // Primary bleibt unverändert
            $params['primaryType'] = $this->primary->scopeType;
            $params['primaryId'] = $this->primary->scopeId;
        }

        // Entferne Nulls für saubere URLs
        $params = array_filter($params, static fn ($v) => null !== $v);

        return $this->router->generate($route, $params);
    }

    private function renderScopeOptionLabel(string $type, string $id): string
    {
        // Public scope: simple fixed label
        if ('public' === $type) {
            return 'All scopes';
        }

        if ('state' === $type) {
            $intId = (int) $id;

            if (!array_key_exists($intId, $this->stateNames)) {
                $entity = $this->stateRepository->find($intId);
                $this->stateNames[$intId] = $entity?->getName() ?? ("State #$id");
            }

            return $this->stateNames[$intId];
        }

        // Dispatch area: resolve from repository, with small in-memory cache
        if ('dispatch_area' === $type) {
            $intId = (int) $id;

            if (!array_key_exists($intId, $this->dispatchAreaNames)) {
                $entity = $this->dispatchAreaRepository->find($intId);
                $this->dispatchAreaNames[$intId] = $entity?->getName() ?? ("Dispatch Area #$id");
            }

            return $this->dispatchAreaNames[$intId];
        }

        // Hospital: resolve from repository, with small in-memory cache
        if ('hospital' === $type) {
            $intId = (int) $id;

            if (!array_key_exists($intId, $this->hospitalNames)) {
                $entity = $this->hospitalRepository->find($intId);
                $this->hospitalNames[$intId] = $entity?->getName() ?? ("Hospital #$id");
            }

            return $this->hospitalNames[$intId];
        }

        if ('hospital_cohort' === $type) {
            $parts = explode('_', $id, 2);

            if (count($parts) < 2) {
                return ucfirst($type).' '.$id;
            }

            [$tier, $location] = array_map('ucfirst', $parts);

            return "Tier: $tier — Location: $location";
        }

        // Fallback for any other scope types
        return ucfirst($type).' '.$id;
    }
}

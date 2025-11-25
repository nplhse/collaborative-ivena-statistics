<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Availability\ScopeAvailabilityService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'ScopePicker', template: '@Statistics/components/ScopePicker.html.twig')]
final class ScopePicker
{
    /**
     * The currently active scope, set from the template.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public Scope $scope;

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
     * Groups of available scopes for the select.
     *
     * @return array<int, array{label:string, items: list<array{type:string,id:string,label:string,selected:bool,url:string}>}>
     */
    public function groups(): array
    {
        // We reuse the same availability tree logic
        $matrix = $this->availability->getSidebarTree();

        // Group by scope_type
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
                $selected = ($this->scope->scopeType === $type && $this->scope->scopeId === $id);

                $items[] = [
                    'type' => $type,
                    'id' => $id,
                    'label' => $this->renderScopeOptionLabel($type, $id),
                    'selected' => $selected,
                    'url' => $this->buildUrl($type, $id),
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

    private function buildUrl(string $type, string $id): string
    {
        $r = $this->requestStack->getCurrentRequest();
        if (!$r) {
            return '#';
        }

        $route = (string) $r->attributes->get('_route');

        // Start with current route params and query params
        $params = array_merge(
            $r->attributes->get('_route_params', []),
            $r->query->all(),
        );

        // Only override the scope; keep everything else (gran, key, view, tt, ...)
        $params['scopeType'] = $type;
        $params['scopeId'] = $id;

        // Remove null values to keep URLs clean
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

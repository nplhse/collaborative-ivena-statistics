<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Model\Scope;
use App\Service\Statistics\ScopeAvailabilityService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'ScopePicker')]
final class ScopePicker
{
    /**
     * The currently active scope, set from the template.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public Scope $scope;

    public function __construct(
        private RequestStack $requestStack,
        private RouterInterface $router,
        private ScopeAvailabilityService $availability,
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

        // Put "Public" group first if present
        usort($groups, static function ($a, $b) {
            if ('Public' === $a['label']) {
                return -1;
            }
            if ('Public' === $b['label']) {
                return 1;
            }

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
        if ('public' === $type) {
            return 'All';
        }
        if ('state' === $type) {
            return "State #$id";
        }
        if ('dispatch_area' === $type) {
            return "Dispatch Area #$id";
        }
        if ('hospital' === $type) {
            return "Hospital #$id";
        }

        return ucfirst(str_replace('_', ' ', $type)).' '.$id;
    }
}

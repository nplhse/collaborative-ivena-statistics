<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Enum\TimeGridMode;
use App\Model\Scope;
use App\Service\Statistics\ScopeAvailabilityService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'ChooseScopePicker')]
final class ChooseScopePicker
{
    /** set by template */
    public Scope $primary;

    /** set by template (optional in RAW/DELTA) */
    public ?Scope $base = null;

    /** set by template */
    public TimeGridMode $mode;

    /** set by template, e.g. "total" | "gender" | ... */
    public string $preset = 'total';

    public function __construct(
        private RequestStack $requestStack,
        private RouterInterface $router,
        private ScopeAvailabilityService $availability
    ) {}

    /**
     * Groups of available scopes for selects.
     * @return array<int, array{label:string, items: list<array{type:string,id:string,label:string,selected:bool,url:string}>}>
     */
    public function primaryGroups(): array
    {
        return $this->buildGroups(forBase: false);
    }

    /**
     * Only used when mode === COMPARE.
     * @return array<int, array{label:string, items: list<array{type:string,id:string,label:string,selected:bool,url:string}>}>
     */
    public function baseGroups(): array
    {
        return $this->buildGroups(forBase: true);
    }

    public function isCompare(): bool
    {
        return $this->mode === TimeGridMode::COMPARE;
    }

    public function modeUrl(string $mode): string
    {
        $r = $this->requestStack->getCurrentRequest();
        if (!$r) return '#';

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
        $matrix = $this->availability->getSidebarTree(); // list of {scope_type, scope_id, count}

        // group by scope_type
        $byType = [];
        foreach ($matrix as $row) {
            $type = (string) $row['scope_type'];
            $id   = (string) $row['scope_id'];
            $byType[$type][] = $id;
        }

        $labelForType = [
            'public'          => 'Public',
            'state'           => 'State',
            'dispatch_area'   => 'Dispatch Area',
            'hospital'        => 'Hospital',
            'hospital_tier'   => 'Hospital Tier',
            'hospital_size'   => 'Hospital Size',
            'hospital_location'=> 'Hospital Location',
            'hospital_cohort' => 'Hospital Cohort',
        ];

        $groups = [];
        foreach ($byType as $type => $ids) {
            sort($ids, SORT_NATURAL);
            $items = [];
            foreach ($ids as $id) {
                $selected = false;

                if ($forBase) {
                    if ($this->base !== null) {
                        $selected = ($this->base->scopeType === $type && (string)$this->base->scopeId === (string)$id);
                    }
                } else {
                    $selected = ($this->primary->scopeType === $type && (string)$this->primary->scopeId === (string)$id);
                }

                $items[] = [
                    'type'     => $type,
                    'id'       => (string)$id,
                    'label'    => $this->renderScopeOptionLabel($type, (string)$id),
                    'selected' => $selected,
                    'url'      => $this->buildUrl($type, (string)$id, $forBase),
                ];
            }

            $groups[] = [
                'label' => $labelForType[$type] ?? ucfirst(str_replace('_', ' ', $type)),
                'items' => $items,
            ];
        }

        // Put "public" group first if present
        usort($groups, static function ($a, $b) {
            if ($a['label'] === 'Public') return -1;
            if ($b['label'] === 'Public') return 1;
            return strcmp($a['label'], $b['label']);
        });

        return $groups;
    }

    private function buildUrl(string $type, string $id, bool $forBase): string
    {
        $r = $this->requestStack->getCurrentRequest();
        if (!$r) return '#';

        $route = (string) $r->attributes->get('_route');

        // Start mit aktuellen Parametern
        $params = array_merge(
            $r->attributes->get('_route_params', []),
            $r->query->all(),
            [
                'preset' => $this->preset,
                'gran'   => $this->primary->granularity,
                'key'    => $this->primary->periodKey,
                'mode'   => $this->mode->value,
            ]
        );

        if ($forBase === false) {
            // PRIMARY wird gewechselt
            $params['primaryType'] = $type;
            $params['primaryId']   = $id;

            // Falls wir NICHT im Compare-Modus sind:
            if (!$this->isCompare()) {
                unset($params['baseType'], $params['baseId']);
            } else {
                // Compare-Mode: Base behalten
                if ($this->base !== null) {
                    $params['baseType'] = $this->base->scopeType;
                    $params['baseId']   = $this->base->scopeId;
                }
            }

        } else {
            // BASE wird gewechselt
            if ($this->isCompare()) {
                $params['baseType'] = $type;
                $params['baseId']   = $id;
            }

            // Primary bleibt unverändert
            $params['primaryType'] = $this->primary->scopeType;
            $params['primaryId']   = $this->primary->scopeId;
        }

        // Entferne Nulls für saubere URLs
        $params = array_filter($params, static fn($v) => $v !== null);

        return $this->router->generate($route, $params);
    }

    private function renderScopeOptionLabel(string $type, string $id): string
    {
        // Human-ish labels; keep simple (you can enhance with DB lookups later)
        if ($type === 'public')   { return 'All'; }
        if ($type === 'state')    { return "State #$id"; }
        if ($type === 'dispatch_area') { return "Dispatch Area #$id"; }
        if ($type === 'hospital') { return "Hospital #$id"; }
        return ucfirst($type) . ' ' . $id;
    }
}

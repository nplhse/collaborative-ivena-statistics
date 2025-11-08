<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Model\Scope;
use App\Service\ScopeRoute;
use App\Service\Statistics\ScopeAvailabilityService;
use App\Service\Statistics\Util\DbScopeNameResolver;
use App\Service\Statistics\Util\Period;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'SidebarScopes')]
final class SidebarScopes
{
    /** @var array<string, list<array{scope: Scope, label: string, url: string, hasData: bool, count: int}>> */
    public array $tree = [];

    public ?Scope $current = null;

    public function __construct(
        private ScopeRoute $route,
        private ScopeAvailabilityService $availability,
        private DbScopeNameResolver $resolver,
    ) {
    }

    public function mount(): void
    {
        /**
         * @var list<array{
         *   scope_type: string,
         *   scope_id: string,
         *   count?: int|numeric-string,
         *   cnt?:   int|numeric-string
         * }> $rows
         */
        $rows = $this->availability->getSidebarTree();

        /** @var array<string, list<array{scope: Scope, label: string, url: string, hasData: bool, count: int}>> $grouped */
        $grouped = [];

        foreach ($rows as $r) {
            $type = $r['scope_type'];
            $id = $r['scope_id'];

            $rawCount = $r['count'] ?? ($r['cnt'] ?? 0);
            $count = (int) $rawCount;

            $scope = new Scope($type, $id, Period::ALL, Period::ALL_ANCHOR_DATE);

            $group = $this->groupLabel($type);
            $label = $this->labelFor($type, $id);

            $grouped[$group] ??= [];
            $grouped[$group][] = [
                'scope' => $scope,
                'label' => $label,
                'url' => $this->urlFor($scope),
                'hasData' => $count > 0,
                'count' => $count,
            ];
        }

        $order = [
            'Public' => 1,
            'States' => 2,
            'Dispatch Areas' => 3,
            'Hospitals' => 4,
            'Cohorts • Tier' => 5,
            'Cohorts • Size' => 6,
            'Cohorts • Location' => 7,
            'Cohorts • Tier × Location' => 8,
        ];

        uksort($grouped, function (string $a, string $b) use ($order): int {
            $posA = $order[$a] ?? 999;
            $posB = $order[$b] ?? 999;

            return $posA <=> $posB;
        });

        foreach ($grouped as &$items) {
            /* @var list<array{label:string}> $items */
            usort($items, static function (array $x, array $y): int {
                return strcmp($x['label'], $y['label']);
            });
        }
        unset($items);

        $this->tree = $grouped;
    }

    public function urlFor(Scope $s): string
    {
        return $this->route->toPath($s->scopeType, $s->scopeId, $s->granularity, $s->periodKey);
    }

    public function isActive(Scope $s): bool
    {
        return null !== $this->current
            && $this->current->scopeType === $s->scopeType
            && $this->current->scopeId === $s->scopeId;
    }

    private function groupLabel(string $type): string
    {
        return match ($type) {
            'public' => 'Public',
            'hospital' => 'Hospitals',
            'dispatch_area' => 'Dispatch Areas',
            'state' => 'States',
            'hospital_tier' => 'Cohorts • Tier',
            'hospital_size' => 'Cohorts • Size',
            'hospital_location' => 'Cohorts • Location',
            'hospital_cohort' => 'Cohorts • Tier × Location',
            default => ucfirst($type),
        };
    }

    private function labelFor(string $type, string $id): string
    {
        $name = static fn (?string $v): string => $v ?? 'Unknown';

        return match ($type) {
            'public' => 'Public data',
            'hospital' => 'Hospital: '.$name($this->resolver->resolve('hospital', $id)),
            'dispatch_area' => 'Dispatch Area: '.$name($this->resolver->resolve('dispatch_area', $id)),
            'state' => 'State: '.$name($this->resolver->resolve('state', $id)),
            'hospital_tier' => 'Tier: '.ucfirst($id),
            'hospital_size' => 'Size: '.ucfirst($id),
            'hospital_location' => 'Location: '.ucfirst($id),
            'hospital_cohort' => (static function (string $sid): string {
                [$tier, $loc] = array_pad(explode('_', $sid, 2), 2, '');

                return sprintf('Tier: %s • Location: %s', ucfirst($tier), ucfirst($loc));
            })($id),
            default => ucfirst($type).' '.$id,
        };
    }
}

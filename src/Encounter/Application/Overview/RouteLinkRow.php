<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

/**
 * One row of the arrival route × IVENA link table: visits linked automatically, linked after a
 * confirmation, linked in total (both), not linked, and all visits of the row. Shares are of the
 * row's visits.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the overview templates.
 */
final readonly class RouteLinkRow
{
    public OverviewCount $automatic;

    public OverviewCount $confirmed;

    public OverviewCount $linked;

    public OverviewCount $notLinked;

    public OverviewCount $total;

    /**
     * @param string $route `ems`, `self`, `unknown` or `total`
     */
    public function __construct(
        public string $route,
        int $automatic,
        int $confirmed,
        int $notLinked,
    ) {
        $total = $automatic + $confirmed + $notLinked;
        $this->automatic = new OverviewCount('automatic', $automatic, $total);
        $this->confirmed = new OverviewCount('confirmed', $confirmed, $total);
        $this->linked = new OverviewCount('linked', $automatic + $confirmed, $total);
        $this->notLinked = new OverviewCount('not_linked', $notLinked, $total);
        $this->total = new OverviewCount('total', $total, $total);
    }
}

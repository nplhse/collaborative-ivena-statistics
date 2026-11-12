<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

use App\Encounter\Application\Glossary\MtsCount;

/**
 * One process interval of the overview. Median and quartiles are shown only from MtsCount::THRESHOLD
 * valid durations on (ADR 012); below that they would describe single visits.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the overview templates.
 */
final readonly class ProcessTimeRow
{
    public MtsCount $valid;

    public MtsCount $missing;

    public MtsCount $negative;

    public MtsCount $implausible;

    public bool $suppressed;

    public ProcessInterval $interval;

    public ?float $q1;

    public ?float $median;

    public ?float $q3;

    public function __construct(ProcessTimeStatistics $statistics)
    {
        $this->interval = $statistics->interval;
        $this->valid = new MtsCount($statistics->valid);
        $this->missing = new MtsCount($statistics->missing);
        $this->negative = new MtsCount($statistics->negative);
        $this->implausible = new MtsCount($statistics->implausible);
        $this->suppressed = $statistics->valid < MtsCount::THRESHOLD;
        $this->q1 = $this->suppressed ? null : $statistics->q1;
        $this->median = $this->suppressed ? null : $statistics->median;
        $this->q3 = $this->suppressed ? null : $statistics->q3;
    }
}

<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

use App\Encounter\Application\Glossary\MtsCount;

/**
 * One box of the process time box plot: an MTS category of one interval. Shown only from
 * MtsCount::THRESHOLD valid durations on (ADR 012); otherwise every value is null.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the overview templates.
 */
final readonly class ProcessTimeBox
{
    public MtsCount $valid;

    public bool $suppressed;

    /** @var list<float>|null P10, Q1, median, Q3, P90 */
    public ?array $values;

    public function __construct(
        public string $category,
        ProcessTimeDistribution $distribution,
    ) {
        $this->valid = new MtsCount($distribution->valid);
        $this->suppressed = $distribution->valid < MtsCount::THRESHOLD;
        $values = [$distribution->p10, $distribution->q1, $distribution->median, $distribution->q3, $distribution->p90];
        $this->values = $this->suppressed || \in_array(null, $values, true) ? null : array_map(floatval(...), $values);
    }
}

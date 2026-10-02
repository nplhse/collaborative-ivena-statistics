<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationLoad;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureTemporalQuery;
use Psr\Clock\ClockInterface;

final readonly class ClosureDurationLoadService
{
    public function __construct(
        private ClosureTemporalQuery $temporalQuery,
        private ClosureDurationLoadCalculator $calculator,
        private ClockInterface $clock,
    ) {
    }

    public function build(ClosureAnalyticsCriteria $criteria): ClosureDurationLoad
    {
        $snapshot = $this->temporalQuery->fetchDurationLoad(
            ClosureDurationLoadWindow::cap($criteria, $this->clock->now()),
        );

        return $this->calculator->calculate($snapshot->intervals, $snapshot->observed);
    }
}

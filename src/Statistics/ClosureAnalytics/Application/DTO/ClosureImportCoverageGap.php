<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

/**
 * Uncovered range between two import export periods, shown with an inclusive last day.
 */
final readonly class ClosureImportCoverageGap
{
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
    ) {
    }

    public function lastDay(): \DateTimeImmutable
    {
        return $this->endsAt->modify('-1 second')->setTime(0, 0);
    }

    public function days(): int
    {
        return max(1, (int) ceil(($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 86400));
    }
}

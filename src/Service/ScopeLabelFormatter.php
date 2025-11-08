<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Scope;
use App\Service\Statistics\Util\DbScopeNameResolver;

/** @psalm-suppress ClassMustBeFinal */
class ScopeLabelFormatter
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private DbScopeNameResolver $resolver,
    ) {
    }

    public function format(Scope $s, bool $includePeriod = true): string
    {
        $scopeLabel = $this->formatScopeOnly($s);

        if (!$includePeriod) {
            return $scopeLabel;
        }

        $period = $this->periodLabel($s);

        return 'all' === $s->granularity
            ? $scopeLabel.' – '.$period
            : $period.' – '.$scopeLabel;
    }

    public function formatScopeOnly(Scope $s): string
    {
        $name = $this->resolver->resolve($s->scopeType, $s->scopeId);

        return match ($s->scopeType) {
            'public' => 'Public Data',
            'hospital' => "Hospital: {$name}",
            'hospital_tier' => 'Hospital Tier: '.ucfirst($s->scopeId),
            'hospital_size' => 'Hospital Size: '.ucfirst($s->scopeId),
            'hospital_location' => 'Hospital Location: '.ucfirst($s->scopeId),
            'hospital_cohort' => 'Hospital Cohort: '.$s->scopeId, // z.B. Basic_Urban
            'dispatch_area' => "Dispatch Area: {$name}",
            'state' => "State: {$name}",
            default => ucfirst($s->scopeType).' '.$s->scopeId,
        };
    }

    private function periodLabel(Scope $s): string
    {
        if ('all' === $s->granularity) {
            return 'Overall Summary';
        }

        $date = new \DateTimeImmutable($s->periodKey);

        return match ($s->granularity) {
            'year' => 'Year '.$date->format('Y'),
            'quarter' => sprintf('Q%s %s', (int) \ceil(((int) $date->format('n')) / 3), $date->format('Y')),
            'month' => $date->format('F Y'),
            'week' => sprintf('Week %s %s', $date->format('W'), $date->format('Y')),
            'day' => $date->format('M j, Y'),
            default => ucfirst($s->granularity),
        };
    }
}

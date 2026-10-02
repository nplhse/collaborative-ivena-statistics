<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationDistributionGroup;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationLoad;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ClosureDurationLoadLabeler
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function label(ClosureDurationLoad $load): ClosureDurationLoad
    {
        $reasons = array_map(
            fn (ClosureDurationDistributionGroup $group): ClosureDurationDistributionGroup => $group->withLabel($this->reasonLabel($group->key)),
            $load->reasons,
        );
        usort(
            $reasons,
            static fn (ClosureDurationDistributionGroup $left, ClosureDurationDistributionGroup $right): int => $right->medianSeconds <=> $left->medianSeconds
                ?: strcmp($left->label, $right->label)
                ?: strcmp($left->key, $right->key),
        );

        return $load->withReasons($reasons);
    }

    private function reasonLabel(string $key): string
    {
        if ('' === $key) {
            return $this->translator->trans('stats.closure.duration_load.reason_missing', domain: 'statistics');
        }

        return $this->translator->trans('stats.closure.reason.'.$key, domain: 'statistics');
    }
}

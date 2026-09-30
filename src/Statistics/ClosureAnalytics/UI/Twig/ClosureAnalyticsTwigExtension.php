<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Twig;

use App\Statistics\ClosureAnalytics\Application\ClosureDurationFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class ClosureAnalyticsTwigExtension extends AbstractExtension
{
    /**
     * @return list<TwigFilter>
     */
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('closure_duration', ClosureDurationFormatter::humanize(...)),
        ];
    }
}

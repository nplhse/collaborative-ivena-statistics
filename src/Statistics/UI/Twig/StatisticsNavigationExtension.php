<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig;

use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class StatisticsNavigationExtension extends AbstractExtension
{
    public function __construct(
        private readonly StatisticsNavigationUrlBuilder $urlBuilder,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('statistics_nav_url', $this->statisticsNavUrl(...)),
        ];
    }

    public function statisticsNavUrl(StatisticWidgetNavigationTarget $target): string
    {
        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return '#';
        }

        return $this->urlBuilder->buildFromTarget($request, $target);
    }
}

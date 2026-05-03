<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Navigation;

use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds statistics URLs from the current request query plus replacements (same semantics as the previous trait-based approach).
 */
final readonly class StatisticsNavigationUrlBuilder
{
    public function __construct(
        private UrlGeneratorInterface $router,
    ) {
    }

    /**
     * @param array<string, scalar|null> $replace
     * @param list<string>               $removeKeys
     */
    public function build(Request $request, string $routeName, array $replace = [], array $removeKeys = []): string
    {
        $params = $request->query->all();
        foreach ($removeKeys as $key) {
            unset($params[$key]);
        }
        foreach ($replace as $key => $value) {
            if (null === $value) {
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }

        return $this->router->generate($routeName, $params);
    }

    public function buildFromTarget(Request $request, StatisticWidgetNavigationTarget $target): string
    {
        return $this->build($request, $target->route, $target->params, $target->removeKeys);
    }
}

<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Navigation;

use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds statistics URLs from the current request query plus replacements (same semantics as the previous trait-based approach).
 *
 * @phpstan-type QueryReplace array<string, scalar|list<scalar>|null>
 */
final readonly class StatisticsNavigationUrlBuilder
{
    public function __construct(
        private UrlGeneratorInterface $router,
    ) {
    }

    /**
     * @param QueryReplace $replace
     * @param list<string> $removeKeys
     */
    public function build(Request $request, string $routeName, array $replace = [], array $removeKeys = []): string
    {
        $routeParams = $request->attributes->get('_route_params', []);
        if (!\is_array($routeParams)) {
            $routeParams = [];
        }

        $params = array_merge($routeParams, $request->query->all());
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

    /**
     * @param QueryReplace $replace
     * @param list<string> $removeKeys
     *
     * @return array<string, scalar>
     */
    public function buildParams(Request $request, string $routeName, array $replace = [], array $removeKeys = []): array
    {
        $url = $this->build($request, $routeName, $replace, $removeKeys);
        $query = parse_url($url, PHP_URL_QUERY);
        if (!\is_string($query) || '' === $query) {
            return [];
        }

        parse_str($query, $params);

        return StatisticsQueryParamNormalizer::normalize($params);
    }
}

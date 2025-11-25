<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'TransportTimeControls', template: '@Statistics/components/TransportTimeControls.html.twig')]
final class TransportTimeControls
{
    /** @var list<array{value:string,label:string}> */
    public array $buckets = [];

    public string $currentBucket = 'all';

    public bool $withProgress = false;

    public bool $withPhysician = true;

    public string $anchorId = 'transport-time-top';

    public function __construct(
        private RequestStack $requestStack,
        private RouterInterface $router,
    ) {
    }

    public function bucketLabel(string $value): string
    {
        foreach ($this->buckets as $b) {
            if ($b['value'] === $value) {
                return $b['label'];
            }
        }

        return 'All durations';
    }

    public function bucketUrl(string $value): string
    {
        $r = $this->requestStack->getCurrentRequest();
        if (!$r) {
            return '#'.$this->anchorId;
        }

        $route = (string) $r->attributes->get('_route');

        $params = array_merge(
            $r->attributes->get('_route_params', []),
            $r->query->all(),
            ['bucket' => $value]
        );

        // saubere Nulls weg
        $params = array_filter($params, static fn ($v) => null !== $v);

        return $this->router->generate($route, $params).'#'.$this->anchorId;
    }

    public function toggleProgressUrl(): string
    {
        $r = $this->requestStack->getCurrentRequest();
        if (!$r) {
            return '#'.$this->anchorId;
        }

        $route = (string) $r->attributes->get('_route');

        $current = $this->withProgress;
        $params = array_merge(
            $r->attributes->get('_route_params', []),
            $r->query->all(),
            ['progress' => $current ? 0 : 1]
        );

        $params = array_filter($params, static fn ($v) => null !== $v);

        return $this->router->generate($route, $params).'#'.$this->anchorId;
    }

    public function togglePhysicianUrl(): string
    {
        $r = $this->requestStack->getCurrentRequest();
        if (!$r) {
            return '#'.$this->anchorId;
        }

        $route = (string) $r->attributes->get('_route');

        $current = $this->withPhysician;
        $params = array_merge(
            $r->attributes->get('_route_params', []),
            $r->query->all(),
            ['physician' => $current ? 0 : 1]
        );

        $params = array_filter($params, static fn ($v) => null !== $v);

        return $this->router->generate($route, $params).'#'.$this->anchorId;
    }
}

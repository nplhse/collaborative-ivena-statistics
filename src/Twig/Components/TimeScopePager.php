<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Model\Scope;
use App\Service\Statistics\TimeScopeNavigator;
use App\Service\Statistics\Util\Period;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent(name: 'TimeScopePager')]
final class TimeScopePager
{
    public Scope $scope;

    public string $variant = 'full';

    /** @var array{disabled:bool, url:string, label:string, hint:?string} */
    public array $prev = [];

    /** @var array{disabled:bool, url:string, label:string, hint:?string} */
    public array $next = [];

    public function __construct(
        private RequestStack $requestStack,
        private RouterInterface $router,
        private TimeScopeNavigator $navigator,
    ) {
    }

    #[PostMount]
    public function init(): void
    {
        // calculate prev/next using Navigator
        $calc = $this->navigator->calculate($this->scope);

        // build prev/next structures
        $this->prev = $this->buildSide($calc['prev'] ?? null, -1);
        $this->next = $this->buildSide($calc['next'] ?? null, +1);
    }

    /**
     * @param array{key:string,label:string,hint?:string}|null $raw
     *
     * @return array{disabled:bool, url:string, label:string, hint:?string}
     */
    private function buildSide(?array $raw, int $dir): array
    {
        if (!$raw) {
            return [
                'disabled' => true,
                'url' => '#',
                'label' => '',
                'hint' => null,
            ];
        }

        // range limits
        $min = new \DateTimeImmutable(Period::ALL_ANCHOR_DATE);
        $max = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);

        $candidate = new \DateTimeImmutable($raw['key']);

        $disabled =
            ($dir < 0 && $candidate < $min)
            || ($dir > 0 && $candidate > $max);

        $url = $disabled ? '#' : $this->buildUrl($raw['key']);

        return [
            'disabled' => $disabled,
            'url' => $url,
            'label' => $raw['label'],
            'hint' => $raw['hint'] ?? null,
        ];
    }

    private function buildUrl(string $periodKey): string
    {
        $r = $this->requestStack->getCurrentRequest();
        if (!$r) {
            return '#';
        }

        $route = (string) $r->attributes->get('_route');
        $params = array_merge(
            $r->attributes->get('_route_params', []),
            $r->query->all(),
            [
                'gran' => $this->scope->granularity,
                'key' => $periodKey,
            ]
        );

        return $this->router->generate($route, $params);
    }
}

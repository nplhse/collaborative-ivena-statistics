<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Model\Scope;
use App\Service\Statistics\Util\Period;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'GranularitySwitch')]
final class GranularitySwitch
{
    public Scope $scope;

    public string $variant = 'list';

    public bool $showPeriod = false;

    /** @var list<string>|null */
    public ?array $allowedOptions = null;

    private const array GRAN_ALIASES = ['gran', 'granularity'];
    private const array KEY_ALIASES = ['key', 'periodKey'];

    public function __construct(
        private RequestStack $requestStack,
        private UrlGeneratorInterface $router,
    ) {
    }

    /** @return list<string> */
    /** @return list<string> */
    public function options(): array
    {
        $default = [
            Period::ALL,
            Period::YEAR,
            Period::QUARTER,
            Period::MONTH,
            Period::WEEK,
            Period::DAY,
        ];

        $opts = $this->allowedOptions
            ? array_values(array_intersect($default, $this->allowedOptions))
            : $default;

        if (!in_array($this->scope->granularity, $opts, true)) {
            $opts[] = $this->scope->granularity;
        }

        return $opts;
    }

    public function isActive(string $gran): bool
    {
        return $this->scope->granularity === $gran;
    }

    public function currentLabel(): string
    {
        return $this->optionLabelFor($this->scope->granularity);
    }

    public function optionLabelFor(string $gran): string
    {
        [, $label, $period] = $this->deriveKeyAndLabel($gran);

        return $this->showPeriod && null !== $period ? sprintf('%s (%s)', $label, $period) : $label;
    }

    public function url(string $gran): string
    {
        [$key] = $this->deriveKeyAndLabel($gran);

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            throw new \LogicException('GranularitySwitch requires an active HTTP request.');
        }

        $routeName = (string) $request->attributes->get('_route');
        $routeParams = (array) $request->attributes->get('_route_params', []);
        $queryParams = $request->query->all();

        // Den tatsächlich verwendeten Parameternamen pro Seite ermitteln
        $granParam = $this->resolveParamName(self::GRAN_ALIASES, $routeParams, $queryParams);
        $keyParam = $this->resolveParamName(self::KEY_ALIASES, $routeParams, $queryParams);

        // Alles zusammenführen (Route-Params + Query), dann gezielt überschreiben
        $all = array_merge($routeParams, $queryParams);
        $all[$granParam] = $gran;
        $all[$keyParam] = $key;

        // Alle *anderen* Aliasse entfernen, damit es keine Doppelung gibt
        foreach (self::GRAN_ALIASES as $alias) {
            if ($alias !== $granParam) {
                unset($all[$alias]);
            }
        }
        foreach (self::KEY_ALIASES as $alias) {
            if ($alias !== $keyParam) {
                unset($all[$alias]);
            }
        }

        // Optional: Null-Werte entfernen, falls du jemals damit Keys explizit löschen willst
        // $all = array_filter($all, static fn($v) => $v !== null);

        return $this->router->generate($routeName, $all);
    }

    private function resolveParamName(array $aliases, array $routeParams, array $queryParams): string
    {
        // Falls einer der Aliasse bereits in Route ODER Query vorkommt, nimm diesen.
        foreach ($aliases as $name) {
            if (array_key_exists($name, $routeParams) || array_key_exists($name, $queryParams)) {
                return $name;
            }
        }

        // Sonst den ersten als Default (BC: 'gran' bzw. 'key')
        return $aliases[0];
    }

    /** @return list{string, string, ?string} */
    private function deriveKeyAndLabel(string $target): array
    {
        $d = new \DateTimeImmutable($this->scope->periodKey);

        switch ($target) {
            case Period::ALL:
                return [Period::ALL_ANCHOR_DATE, 'All', null];

            case Period::YEAR:
                $y = $this->startOfYear($d);

                return [$y->format('Y-m-01'), 'Year', $y->format('Y')];

            case Period::QUARTER:
                $q = $this->startOfQUARTERer($d);
                $qIndex = (int) ceil(((int) $q->format('n')) / 3);

                return [$q->format('Y-m-01'), 'Quarter', sprintf('Q%s %s', $qIndex, $q->format('Y'))];

            case Period::MONTH:
                $m = $this->startOfMonth($d);

                return [$m->format('Y-m-01'), 'Month', $m->format('F Y')];

            case Period::WEEK:
                $anchor = match ($this->scope->granularity) {
                    Period::YEAR => $this->startOfYear($d),
                    Period::QUARTER => $this->startOfQUARTERer($d),
                    Period::MONTH => $this->startOfMonth($d),
                    Period::WEEK, Period::DAY => $d,
                    Period::ALL => $this->startOfYear($d),
                    default => $d,
                };
                $w = $this->startOfWeek($anchor);

                return [$w->format('Y-m-d'), 'Week', sprintf('%s, %s', $w->format('W'), $w->format('Y'))];

            case Period::DAY:
                $anchor = match ($this->scope->granularity) {
                    Period::YEAR => $this->startOfYear($d),
                    Period::QUARTER => $this->startOfQUARTERer($d),
                    Period::MONTH => $this->startOfMonth($d),
                    Period::WEEK => $this->startOfWeek($d),
                    Period::DAY => $d,
                    Period::ALL => $this->startOfYear($d),
                    default => $d,
                };

                return [$anchor->format('Y-m-d'), 'Day', $anchor->format('M j, Y')];

            default:
                return [$this->scope->periodKey, ucfirst($target), null];
        }
    }

    private function startOfYear(\DateTimeImmutable $x): \DateTimeImmutable
    {
        return $x->setDate((int) $x->format('Y'), 1, 1)->setTime(0, 0, 0);
    }

    private function startOfQUARTERer(\DateTimeImmutable $x): \DateTimeImmutable
    {
        $m = (int) $x->format('n');
        $qm = intdiv($m - 1, 3) * 3 + 1;

        return $x->setDate((int) $x->format('Y'), $qm, 1)->setTime(0, 0, 0);
    }

    private function startOfMonth(\DateTimeImmutable $x): \DateTimeImmutable
    {
        return $x->setDate((int) $x->format('Y'), (int) $x->format('n'), 1)->setTime(0, 0, 0);
    }

    private function startOfWeek(\DateTimeImmutable $x): \DateTimeImmutable
    {
        $dow = (int) $x->format('N'); // ISO Monday = 1

        return $x->modify('-'.($dow - 1).' days')->setTime(0, 0, 0);
    }
}

<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Model\Scope;
use App\Service\ScopeRoute;
use App\Service\Statistics\Util\Period;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'GranularitySwitch')]
final class GranularitySwitch
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public Scope $scope;

    /** @psalm-suppress PossiblyUnusedProperty */
    public string $variant = 'list';

    public bool $showPeriod = false;

    public function __construct(
        private ScopeRoute $route,
    ) {
    }

    /**
     * @return list<string>
     */
    public function options(): array
    {
        return [
            Period::ALL,
            Period::YEAR,
            Period::QUARTER,
            Period::MONTH,
            Period::WEEK,
            Period::DAY,
        ];
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

        if ($this->showPeriod && null !== $period) {
            return sprintf('%s (%s)', $label, $period);
        }

        return $label;
    }

    public function url(string $gran): string
    {
        [$key] = $this->deriveKeyAndLabel($gran);

        return $this->route->toPath(
            $this->scope->scopeType,
            $this->scope->scopeId,
            $gran,
            $key
        );
    }

    /**
     * @return list{string, string, ?string}
     */
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
                    Period::WEEK => $d,
                    Period::DAY => $d,
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

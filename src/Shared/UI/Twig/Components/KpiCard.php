<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A single key figure with an optional absolute change. Callers pass prepared values; the component only
 * formats and presents them. See docs/03-development/twig-components.md#kpicard.
 */
#[AsTwigComponent(name: 'KpiCard', template: '@Shared/components/KpiCard.html.twig')]
final class KpiCard
{
    public const string TREND_UP = 'up';

    public const string TREND_DOWN = 'down';

    public const string TREND_FLAT = 'flat';

    /** Tabler's default sparkline viewBox height and padding, see {@see self::getSparklinePoints()}. */
    private const float SPARKLINE_HEIGHT = 24.0;

    private const float SPARKLINE_PAD = 2.0;

    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $label;

    public int|float|string|null $value = null;

    public ?string $unit = null;

    public ?string $description = null;

    public ?string $icon = null;

    public int|float|null $delta = null;

    /** Unit of the delta when it differs from the value, for example percentage points next to a percentage. */
    public ?string $deltaUnit = null;

    public ?string $comparisonLabel = null;

    public bool $colorizeTrend = false;

    public bool $invertTrend = false;

    public ?string $href = null;

    public int $fractionDigits = 0;

    /**
     * Optional trend across the bottom of the card, drawn by Tabler's sparkline (#1239). Oldest first; the caller decides what the
     * points are. Fewer than two points show no sparkline.
     *
     * @var list<array{label: string, value: int|float}>
     */
    public array $sparkline = [];

    /** Numbers are formatted for the locale; strings are printed as the caller prepared them. */
    public function isNumericValue(): bool
    {
        return \is_int($this->value) || \is_float($this->value);
    }

    /**
     * The delta rounded to the displayed precision, so sign, icon and colour match what the reader sees
     * (0.04 with one fraction digit rounds to 0 and counts as unchanged).
     */
    public function getRoundedDelta(): ?float
    {
        if (null === $this->delta) {
            return null;
        }

        $rounded = round((float) $this->delta, max(0, $this->fractionDigits));

        // round(-0.04, 1) is -0.0; adding +0.0 turns it into 0.0, so it is not formatted with a minus sign.
        return $rounded + 0.0;
    }

    public function getTrendDirection(): ?string
    {
        $delta = $this->getRoundedDelta();

        return match (true) {
            null === $delta => null,
            $delta > 0 => self::TREND_UP,
            $delta < 0 => self::TREND_DOWN,
            default => self::TREND_FLAT,
        };
    }

    public function getTrendClass(): string
    {
        $direction = $this->getTrendDirection();

        if (!$this->colorizeTrend || null === $direction || self::TREND_FLAT === $direction) {
            return 'text-secondary';
        }

        $favourable = (self::TREND_UP === $direction) !== $this->invertTrend;

        return $favourable ? 'text-green' : 'text-red';
    }

    public function getTrendIcon(): ?string
    {
        return match ($this->getTrendDirection()) {
            self::TREND_UP => 'tabler:trending-up',
            self::TREND_DOWN => 'tabler:trending-down',
            self::TREND_FLAT => 'tabler:minus',
            default => null,
        };
    }

    public function getDeltaUnitLabel(): ?string
    {
        return $this->deltaUnit ?? $this->unit;
    }

    public function hasSparkline(): bool
    {
        return \count($this->sparkline) >= 2;
    }

    /**
     * Where each point sits in the sparkline, in percent of its box, so a hover target with the value can sit on it.
     * Mirrors the geometry of Tabler's line sparkline: x spread evenly, y between a padding of 2 of 24 units, the
     * lowest value at the bottom and the highest at the top (all values equal: bottom).
     *
     * @return list<array{label: string, value: int|float, left: float, top: float}>
     */
    public function getSparklinePoints(): array
    {
        if (!$this->hasSparkline()) {
            return [];
        }

        $values = array_map(static fn (array $point): float => (float) $point['value'], $this->sparkline);
        $min = $values[0];
        $max = $values[0];
        foreach ($values as $value) {
            $min = min($min, $value);
            $max = max($max, $value);
        }
        $span = $max > $min ? $max - $min : 1.0;
        $last = (float) (\count($values) - 1);

        $points = [];
        foreach ($this->sparkline as $index => $point) {
            $y = self::SPARKLINE_PAD + (1.0 - ($values[$index] - $min) / $span) * (self::SPARKLINE_HEIGHT - 2.0 * self::SPARKLINE_PAD);
            $points[] = [
                'label' => $point['label'],
                'value' => $point['value'],
                'left' => round((float) $index / $last * 100.0, 2),
                'top' => round($y / self::SPARKLINE_HEIGHT * 100.0, 2),
            ];
        }

        return $points;
    }

    /** Values for Tabler's `data-bs-values`, comma-separated. */
    public function getSparklineValues(): string
    {
        return implode(',', array_map(static fn (array $point): string => (string) $point['value'], $this->sparkline));
    }

    public function getDeltaSign(): string
    {
        return self::TREND_UP === $this->getTrendDirection() ? '+' : '';
    }
}

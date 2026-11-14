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

    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $label;

    public int|float|string|null $value = null;

    public ?string $unit = null;

    public ?string $description = null;

    public ?string $icon = null;

    public int|float|null $delta = null;

    public ?string $comparisonLabel = null;

    public bool $colorizeTrend = false;

    public bool $invertTrend = false;

    public ?string $href = null;

    public int $fractionDigits = 0;

    /** Numbers are formatted for the locale; strings are printed as the caller prepared them. */
    public function isNumericValue(): bool
    {
        return \is_int($this->value) || \is_float($this->value);
    }

    /**
     * The delta rounded to the displayed precision, so sign, icon and colour match what the reader sees
     * (0.04 with one fraction digit is shown as 0.0 and counts as unchanged).
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

    public function getDeltaSign(): string
    {
        return self::TREND_UP === $this->getTrendDirection() ? '+' : '';
    }
}

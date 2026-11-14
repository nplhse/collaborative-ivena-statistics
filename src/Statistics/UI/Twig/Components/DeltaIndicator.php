<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Statistics:DeltaIndicator', template: '@Statistics/components/DeltaIndicator.html.twig')]
final class DeltaIndicator
{
    public int|float|null $value = null;

    /**
     * `percent` for a relative change, `pp` for the difference between two shares
     * (percentage points), `count`, `decimal` or `minutes`.
     */
    public string $unit = 'percent';

    public string $display = 'badge';

    public string $ariaKey = '';

    public string $whenZero = 'hide';

    public string $title = '';

    public string $extraClass = '';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function isVisible(): bool
    {
        if (null === $this->value) {
            return false;
        }

        return !$this->isZero() || 'neutral' === $this->whenZero;
    }

    public function getLabel(): string
    {
        return $this->format(
            'pp' === $this->unit ? $this->translator->trans('stats.unit.percentage_points.short', [], 'statistics') : null,
        );
    }

    /**
     * Explicit title wins; percentage-point deltas fall back to the spelled-out unit.
     */
    public function getTitleText(): string
    {
        if ('' !== $this->title || 'pp' !== $this->unit) {
            return $this->title;
        }

        return $this->translator->trans('stats.unit.percentage_points.long', [], 'statistics');
    }

    public function getCssClass(): string
    {
        $value = (float) ($this->value ?? 0);
        $text = 'text' === $this->display;
        if ($value > 0) {
            $tone = $text ? 'text-green' : 'badge bg-green-lt stats-rank-shift-badge';
        } elseif ($value < 0) {
            $tone = $text ? 'text-red' : 'badge bg-red-lt stats-rank-shift-badge';
        } else {
            $tone = $text ? 'text-secondary' : 'badge bg-secondary-lt stats-rank-shift-badge';
        }

        $extra = trim($this->extraClass);

        return '' === $extra ? $tone : $tone.' '.$extra;
    }

    public function getAriaLabel(): string
    {
        // Screen readers get the spelled-out unit; "pp" would be read letter by letter.
        $delta = 'pp' === $this->unit
            ? $this->format($this->translator->trans('stats.unit.percentage_points.long', [], 'statistics'))
            : $this->getLabel();

        return $this->translator->trans($this->ariaKey, ['delta' => $delta], 'statistics');
    }

    private function format(?string $percentagePointUnit): string
    {
        $value = (float) ($this->value ?? 0);
        $magnitude = match ($this->unit) {
            'count' => number_format($value, 0, ',', '.'),
            'decimal' => number_format($value, 1, ',', '.'),
            'minutes' => number_format($value, 1, ',', '.').' '.$this->translator->trans('stats.reports.monthly.kpi.minutes', [], 'statistics'),
            'pp' => number_format($value, 1, ',', '.').' '.$percentagePointUnit,
            default => number_format($value, 1, ',', '.').'%',
        };

        return $value > 0 ? '+'.$magnitude : $magnitude;
    }

    private function isZero(): bool
    {
        return 0.0 === (float) $this->value;
    }
}

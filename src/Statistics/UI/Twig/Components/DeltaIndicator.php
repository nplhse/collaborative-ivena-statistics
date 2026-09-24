<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Statistics:DeltaIndicator', template: '@Statistics/components/DeltaIndicator.html.twig')]
final class DeltaIndicator
{
    public int|float|null $value = null;

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
        $value = (float) ($this->value ?? 0);
        $magnitude = match ($this->unit) {
            'count' => number_format($value, 0, ',', '.'),
            'minutes' => number_format($value, 1, ',', '.').' '.$this->translator->trans('stats.reports.monthly.kpi.minutes', [], 'statistics'),
            default => number_format($value, 1, ',', '.').'%',
        };

        return $value > 0 ? '+'.$magnitude : $magnitude;
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
        return $this->translator->trans($this->ariaKey, ['delta' => $this->getLabel()], 'statistics');
    }

    private function isZero(): bool
    {
        return 0.0 === (float) $this->value;
    }
}
